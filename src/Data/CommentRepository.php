<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Data;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry;
use Statamic\Extensions\Pagination\LengthAwarePaginator;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry as EntryApi;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Comments\CommentNode;
use Stillat\Meerkat\Comments\ThreadBuilder;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Concerns\GetsCommentDetails;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\CommentRepository as CommentRepositoryContract;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Facades\Comments as CommentsFacade;
use Stillat\Meerkat\Hooks\CommentSpamCheck;
use Stillat\Meerkat\Jobs\CheckForSpam;
use Stillat\Meerkat\Jobs\SubmitHam;
use Stillat\Meerkat\Jobs\SubmitSpam;
use Stillat\Meerkat\Mirror\Mirror;
use Stillat\Meerkat\Services\ModerationAuditManager;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Support\CommentVisibility;
use Stillat\Meerkat\Support\ThreadCache;
use Throwable;

class CommentRepository implements CommentRepositoryContract
{
    use ExtractsFields,
        GetsCommentDetails,
        GetsMeerkatConfig,
        Hookable;

    public function ensureThreadExists(Entry $entry): void
    {
        $threadId = app(ThreadResolver::class)->forEntry($entry);

        Thread::updateOrCreate(
            ['thread_id' => $threadId],
            [
                'thread_id' => $threadId,
                'entry_id' => $entry->id(),
                'cached_title' => $entry->title,
                'site' => $this->entryHandle($entry->site()),
                'collection' => $this->entryHandle($entry->collection()),
            ],
        );
    }

    public function deleteComment(int $id, ?string $reason = null): bool
    {
        $comment = $this->findComment($id);

        if (! $comment instanceof Comment) {
            return false;
        }

        if ($comment->is_removed) {

            return true;
        }

        $comment = $this->hookComment('deletingComment', $comment);

        $wasPublished = $comment->is_published;
        $threadId = $comment->thread_id;
        $hadChildren = $comment->children()->exists();

        $comment->is_removed = true;
        $comment->removed_at = now();
        $actorId = auth()->id();
        $comment->removed_by = is_string($actorId) || is_int($actorId) ? (string) $actorId : null;
        $comment->removed_reason = $reason;

        $result = $comment->save();

        if (! $result) {
            return false;
        }

        $this->audit()->log($comment, 'deleted', [
            'was_published' => $wasPublished,
            'had_children' => $hadChildren,
            'reason' => $reason,
        ]);
        $this->runHooksWith('after-comment-deleted', [
            'id' => $id,
            'was_published' => $wasPublished,
            'thread_id' => $threadId,
            'had_children' => $hadChildren,
        ]);
        $this->invalidateThreadCache($threadId);
        $this->metrics()->recalculateThread($threadId);

        return true;
    }

    public function removeSubtree(int $id, ?string $reason = null): int
    {
        $comment = $this->findComment($id);

        if (! $comment || $comment->is_removed) {
            return 0;
        }

        $threadId = $comment->thread_id;

        $ids = $this->integerIds($this->subtree($comment)->pluck('id')->all());

        $count = Comment::query()
            ->whereIn('comments.id', $ids)
            ->where('comments.is_removed', false)
            ->update([
                'is_removed' => true,
                'removed_at' => now(),
                'removed_by' => auth()->id(),
                'removed_reason' => $reason,
            ]);

        if ($count <= 0) {
            return $count;
        }

        $comment->refresh();
        $this->audit()->log($comment, 'deleted', [
            'subtree_size' => $count,
            'reason' => $reason,
        ]);

        Mirror::rewrite($ids);
        $this->invalidateThreadCache($threadId);
        $this->metrics()->recalculateThread($threadId);

        return $count;
    }

    /**
     * @return list<int>
     */
    public function hiddenSubtreeIds(string $threadId, bool $includeTombstones = false, bool $includeReplies = false): array
    {
        return $this->hiddenSubtreeIdsForThreads([$threadId], $includeTombstones, $includeReplies);
    }

    /**
     * @param  list<string>|null  $threadIds  Threads to scope to, or `null` for
     *                                        every thread. An empty list resolves to no hidden IDs.
     * @return list<int>
     */
    public function hiddenSubtreeIdsForThreads(
        ?array $threadIds,
        bool $includeTombstones = false,
        bool $includeReplies = false
    ): array {
        if ($includeReplies) {
            return [];
        }

        if ($threadIds === []) {
            return [];
        }

        $query = Comment::query()->where('is_removed', true);

        if ($threadIds !== null) {
            $query->whereIn('thread_id', $threadIds);
        }

        $tombstones = $this->integerIds($query->pluck('id')->all());

        if ($tombstones === []) {
            return [];
        }

        $descendants = $this->collectDescendantIds($tombstones);

        if ($includeTombstones) {

            return $descendants;
        }

        return array_values(array_unique(array_merge($tombstones, $descendants)));
    }

    /**
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    private function collectDescendantIds(array $rootIds): array
    {
        $descendants = [];
        $frontier = $rootIds;

        while ($frontier !== []) {
            $next = $this->integerIds(Comment::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all());

            if ($next === []) {
                break;
            }

            $descendants = array_merge($descendants, $next);
            $frontier = $next;
        }

        return array_values(array_unique($descendants));
    }

    public function forceDeleteComment(int $id): bool
    {
        $comment = $this->findComment($id, withTrashed: true);

        if (! $comment instanceof Comment) {
            return false;
        }

        $threadId = $comment->thread_id;

        $result = $comment->forceDelete() !== false;

        if ($result) {
            $this->invalidateThreadCache($threadId);
            $this->metrics()->recalculateThread($threadId);
        }

        return $result;
    }

    public function restoreComment(int $id): bool
    {
        $comment = $this->findComment($id);

        if (! $comment || ! $comment->is_removed) {
            return false;
        }

        $comment->is_removed = false;
        $comment->removed_at = null;
        $comment->removed_by = null;
        $comment->removed_reason = null;

        $comment = $this->hookComment('restoringComment', $comment);

        return $this->saveAndRecord($comment, 'restored', [
            'thread_id' => $comment->thread_id,
        ]);
    }

    public function markAsSpam(int $id): bool
    {
        $comment = $this->findComment($id);

        if (! $comment instanceof Comment) {
            return false;
        }

        $comment->is_spam = true;
        $comment->is_ham = false;
        $comment->checked_for_spam = true;
        $comment->is_published = false;
        $comment->moderation_reason = 'manual_spam_report';
        $comment->stampModeration('spam');

        $comment = $this->hookComment('markingAsSpam', $comment);

        $saved = $this->saveAndRecord($comment, 'marked_spam', [
            'thread_id' => $comment->thread_id,
        ]);

        if ($this->submitSpamHamResultsToThirdParties()) {
            SubmitSpam::dispatchMeerkatJob($id);
        }

        return $saved;
    }

    public function markAsHam(int $id): bool
    {
        $comment = $this->findComment($id);

        if (! $comment instanceof Comment) {
            return false;
        }

        $wasOriginallySpam = $comment->is_spam;

        $comment->is_ham = true;
        $comment->is_spam = false;
        $comment->checked_for_spam = true;

        if ($wasOriginallySpam && ! $comment->is_published && $this->autoUnpublishSpamComments()) {
            $comment->is_published = true;
        }

        $comment->moderation_reason = 'manual_ham_report';
        $comment->stampModeration($comment->is_published ? 'approved' : 'pending');

        $comment = $this->hookComment('markingAsHam', $comment);

        $saved = $this->saveAndRecord($comment, 'marked_ham', [
            'thread_id' => $comment->thread_id,
        ]);

        if ($this->submitSpamHamResultsToThirdParties()) {
            SubmitHam::dispatchMeerkatJob($id);
        }

        return $saved;
    }

    public function reject(int $id, ?string $reason = null, ?string $notes = null): bool
    {
        $comment = $this->findComment($id);

        if (! $comment instanceof Comment) {
            return false;
        }

        $comment->is_published = false;
        $comment->is_spam = false;
        $comment->is_ham = false;
        $comment->checked_for_spam = true;
        $comment->moderation_reason = $reason ?: 'rejected';
        $comment->moderation_notes = $notes;
        $comment->stampModeration('rejected');

        $comment = $this->hookComment('rejectingComment', $comment);

        return $this->saveAndRecord($comment, 'rejected', [
            'reason' => $comment->moderation_reason,
        ]);
    }

    public function publish(int $id): bool
    {
        $comment = $this->findComment($id);

        if (! $comment instanceof Comment) {
            return false;
        }

        $comment->is_published = true;
        $comment->moderation_reason = 'published';
        $comment->stampModeration('approved');

        $comment = $this->hookComment('publishingComment', $comment);

        return $this->saveAndRecord($comment, 'published');
    }

    public function unpublish(int $id): bool
    {
        $comment = $this->findComment($id);

        if (! $comment instanceof Comment) {
            return false;
        }

        $comment->is_published = false;
        $comment->moderation_reason = 'unpublished';
        $comment->stampModeration($comment->is_spam ? 'spam' : 'pending');

        $comment = $this->hookComment('unpublishingComment', $comment);

        return $this->saveAndRecord($comment, 'unpublished');
    }

    public function restoreRevision(int $commentId, int $revisionNumber): bool
    {
        $comment = $this->findComment($commentId);

        if (! $comment instanceof Comment) {
            return false;
        }

        $revision = $comment->revision($revisionNumber);

        if (! $revision instanceof CommentRevision) {
            return false;
        }

        $before = $comment->revisions()->count();

        $comment->comment_text = $revision->comment_text;
        $comment->comment_data = $revision->comment_data ?? [];

        if (! $comment->save()) {
            return false;
        }

        if ($comment->revisions()->count() > $before) {
            $comment->latestRevision()?->update([
                'edit_reason' => __('meerkat::general.revision_restored_reason', ['number' => $revisionNumber]),
            ]);
            $this->audit()->log($comment, 'revision_restored', ['revision_number' => $revisionNumber]);
            $this->invalidateThreadCache($comment->thread_id);
        }

        return true;
    }

    public function areCommentsEnabledForEntry(Entry $entry): bool
    {

        if ($this->isExplicitlyDisabledForEntry($entry)) {
            return false;
        }

        if (! $this->commentsCanBeDisabled()) {
            return true;
        }

        $entryDate = $entry->date();

        if (! $entryDate instanceof \DateTimeInterface) {
            return true;
        }

        $entryDate = Carbon::instance($entryDate);

        $windowValue = Settings::get('publishing.automatically_close_comments', 0);
        $window = is_int($windowValue)
            ? $windowValue
            : (is_string($windowValue) && is_numeric($windowValue) ? (int) $windowValue : 0);
        $cutoff = Carbon::now()->subDays($window)->startOfDay();

        return $entryDate->copy()->startOfDay() >= $cutoff;
    }

    public function isExplicitlyDisabledForEntry(Entry $entry): bool
    {
        $disableField = config('meerkat.publishing.entry_disable_field');

        if ($disableField === null || $disableField === '') {
            return false;
        }

        $value = $entry->value($disableField);

        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function checkForSpam(int $id): void
    {
        $details = $this->getCommentDetails($id);

        if (! $details) {
            return;
        }

        /** @var Comment $comment */
        [$entry, $comment] = $details;

        $wasPublished = (bool) $comment->is_published;

        try {
            $spam = app(CommentSpamCheck::class)->resolve($entry, $comment);
            $entry = $spam['entry'];
            $comment = $spam['comment'];
            $isSpam = $spam['is_spam'];

            $comment->is_spam = $isSpam;
            $comment->checked_for_spam = true;
            $comment->stampModeration($isSpam ? 'spam' : ($comment->is_published ? 'approved' : 'pending'));

            $payload = $this->runHooksWith('after-spam-determined', [
                'comment' => $comment,
                'entry' => $entry,
                'is_spam' => $isSpam,
                'checked_for_spam' => true,
            ]);

            $hookSpam = $payload instanceof Payload ? $payload->is_spam : null;
            $hookChecked = $payload instanceof Payload ? $payload->checked_for_spam : null;
            $comment->is_spam = is_bool($hookSpam) ? $hookSpam : $isSpam;
            $comment->checked_for_spam = is_bool($hookChecked) ? $hookChecked : true;
            $comment->moderation_status = $comment->is_spam ? 'spam' : ($comment->is_published ? 'approved' : 'pending');

            if ($comment->is_spam) {
                $shouldDelete = $this->autoDeleteSpam();
                $shouldUnpublish = $this->autoUnpublishSpamComments();

                $actionPayload = $this->runHooksWith('spam-action-decided', [
                    'comment' => $comment,
                    'is_spam' => $comment->is_spam,
                    'should_delete' => $shouldDelete,
                    'should_unpublish' => $shouldUnpublish,
                ]);

                $shouldDelete = $actionPayload instanceof Payload && $actionPayload->should_delete === true;
                $shouldUnpublish = $actionPayload instanceof Payload && $actionPayload->should_unpublish === true;

                if ($shouldDelete) {
                    $comment->delete();
                    $this->audit()->log($comment, 'auto_deleted_spam', []);
                } elseif ($shouldUnpublish) {
                    $comment->is_published = false;
                    $comment->moderation_status = 'spam';
                }
            }

            if (! $comment->trashed()) {
                $comment->saveQuietly();

                if ($wasPublished && ! $comment->is_published && $comment->parent_id) {
                    Comment::query()
                        ->where('comments.id', $comment->parent_id)
                        ->decrement('replies_count');
                }

                $this->audit()->log($comment, 'spam_checked', [
                    'is_spam' => $comment->is_spam,
                ]);
            }

            $this->invalidateThreadCache($comment->thread_id);
            $this->metrics()->recalculateThread($comment->thread_id);
        } catch (Throwable $throwable) {
            Log::warning('Meerkat: Checking for spam failed.', [
                'exception' => $throwable,
                'comment_id' => $comment->id,
                'thread_id' => $comment->thread_id,
            ]);

            if ($comment->is_published && $this->unpublishOnGuardFailure()) {
                $comment->is_published = false;
                $comment->moderation_status = 'pending';

                if (! $comment->save()) {
                    Log::error('Meerkat: Failed to unpublish comment after a spam-guard failure.', [
                        'comment_id' => $comment->id,
                    ]);
                }
            }
        }
    }

    public function checkOutstandingForSpam(): void
    {
        $ids = Comment::query()
            ->where('checked_for_spam', false)
            ->where('is_spam', false)
            ->pluck('id')
            ->all();

        foreach ($this->integerIds($ids) as $id) {
            CheckForSpam::dispatchMeerkatJob($id);
        }
    }

    public function inReplyTo(Comment $parent): Comment
    {
        $comment = new Comment;

        $comment->parent_id = $parent->id;
        $comment->thread_id = $parent->thread_id;
        $comment->site = $parent->site;
        $comment->collection = $parent->collection;
        $comment->is_published = true;
        $comment->is_spam = $comment->is_ham = false;
        $comment->depth = $parent->depth + 1;
        $comment->moderation_status = 'approved';

        return $comment;
    }

    public function getCommentEntry(Comment $comment): ?Entry
    {
        $entry = Blink::once(
            'meerkat.thread.entry'.$comment->thread_id,
            fn () => app(ThreadResolver::class)->resolveEntry($comment->thread_id) ?? EntryApi::find($comment->thread_id)
        );

        return $entry instanceof Entry ? $entry : null;
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<int>
     */
    private function integerIds(iterable $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $ids[] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    public function thread(string $threadId, bool $publishedOnly = true): array
    {
        return Cache::remember($this->threadCacheKey($threadId, $publishedOnly), now()->addMinutes(5), function () use ($threadId, $publishedOnly) {
            $query = CommentsFacade::query()
                ->forThread($threadId)
                ->roots()
                ->hierarchical();

            $hidden = [];

            if ($publishedOnly) {
                $visibility = app(CommentVisibility::class);
                $hidden = $visibility->hiddenIdsForThread($threadId);
                $visibility->applyPublicVisibility($query, $threadId);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, Comment> $roots */
            $roots = $publishedOnly
                ? $query->with(['allChildren' => $this->publicChildrenConstraint($hidden)])->get()
                : $query->with('allChildren')->get();

            if ($roots->isEmpty()) {
                return [];
            }

            $builder = app(ThreadBuilder::class);

            return array_values($builder->build($roots)
                ->map(fn (CommentNode $node): array => $node->toArray())
                ->all());
        });
    }

    public function rootsForThread(string $threadId, int $perPage = 15, bool $publishedOnly = true): LengthAwarePaginator
    {
        $query = CommentsFacade::query()
            ->forThread($threadId)
            ->roots()
            ->hierarchical();

        if ($publishedOnly) {
            app(CommentVisibility::class)->applyPublicVisibility($query, $threadId);
        }

        return $query->paginate($perPage);
    }

    /** @return list<array<string, mixed>> */
    public function userHistory(string|int $identifier, int $limit = 50): array
    {
        return array_values(collect(app(CommentVisibility::class)->publicAuthorHistory((string) $identifier, $limit))
            ->map(fn (Comment $comment) => $comment->toDataArray())
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function recentActivity(int $limit = 50, bool $publishedOnly = true): array
    {
        if ($publishedOnly) {
            return array_values(collect(app(CommentVisibility::class)->recentPublicComments($limit))
                ->map(fn (Comment $comment) => $comment->toDataArray())
                ->all());
        }

        $query = CommentsFacade::query()->newest();

        return array_values($query->limit($limit)->get()
            ->map(fn (Comment $comment): array => $comment->toDataArray())
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function moderationQueue(int $limit = 50): array
    {
        return array_values(CommentsFacade::query()
            ->where('moderation_status', 'pending')
            ->oldest()
            ->limit($limit)
            ->get()
            ->map(fn (Comment $comment): array => $comment->toDataArray())
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public function spamQueue(int $limit = 50): array
    {
        return array_values(CommentsFacade::query()
            ->spam()
            ->newest()
            ->limit($limit)
            ->get()
            ->map(fn (Comment $comment): array => $comment->toDataArray())
            ->all());
    }

    /** @param list<int> $ids */
    public function bulkApprove(array $ids): int
    {
        return $this->countSuccessfulBulk($ids, fn ($id) => $this->publish($id));
    }

    /** @param list<int> $ids */
    public function bulkSpam(array $ids): int
    {
        return $this->countSuccessfulBulk($ids, fn ($id) => $this->markAsSpam($id));
    }

    /** @param list<int> $ids */
    public function bulkReject(array $ids, ?string $reason = null): int
    {
        return $this->countSuccessfulBulk($ids, fn ($id) => $this->reject($id, $reason));
    }

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int
    {
        return $this->countSuccessfulBulk($ids, fn ($id) => $this->deleteComment($id), 25);
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(int): bool  $operation
     */
    private function countSuccessfulBulk(array $ids, callable $operation, int $chunkSize = 100): int
    {
        $count = 0;

        foreach (array_chunk($ids, max(1, $chunkSize)) as $chunk) {
            foreach ($chunk as $id) {
                if ($operation($id)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function withAncestry(int $commentId): ?Comment
    {
        $comment = Comment::find($commentId);

        if (! $comment) {
            return null;
        }

        $ancestors = [];
        $current = $comment;

        while ($current->parent_id) {
            $parent = Comment::find($current->parent_id);

            if (! $parent) {
                break;
            }

            array_unshift($ancestors, $parent);
            $current = $parent;
        }

        $comment->ancestors = $ancestors;

        return $comment;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $term, int $limit = 50): array
    {
        return array_values(collect(app(CommentVisibility::class)->publicSearch($term, $limit))
            ->map(fn (Comment $comment) => $comment->toDataArray())
            ->all());
    }

    /**
     * @param  list<int>  $hidden
     */
    private function publicChildrenConstraint(array $hidden): \Closure
    {
        // Capture by reference to apply the constraint recursively.
        $constraint = function (mixed $query) use (&$constraint, $hidden): void {
            if (! $query instanceof EloquentBuilder) {
                return;
            }

            $query
                ->where('is_published', true)
                ->where('is_spam', false)
                ->where('is_removed', false);

            if ($hidden !== []) {
                $query->whereNotIn('comments.id', $hidden);
            }

            $query->with(['allChildren' => $constraint]);
        };

        return $constraint;
    }

    private function hookComment(string $hook, Comment $comment): Comment
    {
        $result = $this->runHooks($hook, $comment);

        return $result instanceof Comment ? $result : $comment;
    }

    private function entryHandle(mixed $value): ?string
    {
        if (! is_object($value) || ! method_exists($value, 'handle')) {
            return null;
        }

        $handle = $value->handle();

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    private function findComment(int $id, bool $withTrashed = false): ?Comment
    {
        $query = Comment::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->find($id);
    }

    private function audit(): ModerationAuditManager
    {
        return app(ModerationAuditManager::class);
    }

    /** @param array<string, mixed> $details */
    private function saveAndRecord(Comment $comment, string $action, array $details = []): bool
    {
        $saved = $comment->save();

        if ($saved) {
            $this->audit()->log($comment, $action, $details);
            $this->invalidateThreadCache($comment->thread_id);
            $this->metrics()->recalculateThread($comment->thread_id);
        }

        return $saved;
    }

    private function metrics(): ThreadMetricsManager
    {
        return app(ThreadMetricsManager::class);
    }

    /** @return Collection<int, Comment> */
    private function subtree(Comment $comment): Collection
    {
        $nodes = collect([$comment]);
        $frontier = collect([$comment->id]);

        while ($frontier->isNotEmpty()) {
            $children = Comment::query()
                ->whereIn('parent_id', $frontier->all())
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $nodes = $nodes->concat($children);
            $frontier = $children->pluck('id');
        }

        return $nodes
            ->unique('id')
            ->sortByDesc('depth')
            ->values();
    }

    private function invalidateThreadCache(string $threadId): void
    {
        ThreadCache::invalidate($threadId);
    }

    private function threadCacheKey(string $threadId, bool $publishedOnly): string
    {
        return ThreadCache::key($threadId, $publishedOnly);
    }
}
