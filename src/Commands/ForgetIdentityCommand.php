<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Listeners\InvalidatesGraphQlCache;
use Stillat\Meerkat\Mirror\Mirror;
use Stillat\Meerkat\Services\Identity\IdentityDataResolver;
use Stillat\Meerkat\Services\Identity\IdentityDataset;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Support\ThreadCache;

class ForgetIdentityCommand extends Command
{
    use RunsInPlease;

    private const MODES = ['anonymize', 'tombstone', 'hard-delete'];

    private const ANONYMIZED_NAME = '[deleted]';

    protected $signature = 'meerkat:forget-identity
        {--email= : Email address to forget}
        {--user-id= : User id to forget}
        {--mode=anonymize : anonymize | tombstone | hard-delete}
        {--collection= : Restrict the comment scope to one collection}
        {--site= : Restrict the comment scope to one site}
        {--scrub-moderator-actions : Also null actor_id on moderation audits and edited_by on revisions}
        {--dry-run : Show what would be done without making changes}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Erase or anonymize every Meerkat row tied to one identity.';

    public function handle(IdentityDataResolver $resolver): int
    {
        $email = $this->stringOption('email');
        $userId = $this->stringOption('user-id');
        $mode = $this->stringOption('mode') ?? 'anonymize';

        if (! $email && ! $userId) {
            $this->components->error('Provide --email and/or --user-id.');

            return self::FAILURE;
        }

        if (! in_array($mode, self::MODES, true)) {
            $this->components->error('Invalid --mode. Choose one of: '.implode(', ', self::MODES).'.');

            return self::FAILURE;
        }

        $dataset = $resolver->resolve($email, $userId, [
            'collection' => $this->stringOption('collection'),
            'site' => $this->stringOption('site'),
        ]);

        if ($dataset->isEmpty()) {
            $this->components->info('No data found for that identity. Nothing to forget.');

            return self::SUCCESS;
        }

        $counts = $dataset->counts();
        $this->components->info(sprintf(
            'Mode: %s. Will affect: %d comments, %d revisions, %d moderation actions, %d users_meta row(s).',
            $mode,
            $counts['comments'],
            $counts['revisions'],
            $counts['moderation_actions'],
            $counts['users_meta'],
        ));

        $retainsActions = $mode !== 'hard-delete' && ! $this->option('scrub-moderator-actions');

        if ($retainsActions && ($counts['revisions'] > 0 || $counts['moderation_actions'] > 0)) {
            $this->components->warn(
                'Revisions and moderation actions will be RETAINED (their actor attribution is left intact). '
                .'Pass --scrub-moderator-actions to also null edited_by / actor_id on those rows.'
            );
        }

        if ($mode === 'hard-delete' && $counts['comments'] > 0) {
            $this->components->warn(
                'hard-delete cascades to descendant comments (other people\'s replies underneath the subject\'s comments). '
                .'Confirm the subject understood this consequence; anonymize is usually the right answer.'
            );
        }

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run: no rows modified.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->components->confirm("Apply {$mode} now? This cannot be undone.")) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $applied = match ($mode) {
            'anonymize' => $this->anonymize($dataset),
            'tombstone' => $this->tombstoneAfterAnonymize($dataset),
            'hard-delete' => $this->hardDelete($dataset),
        };

        Log::info('meerkat.identity.forget', [
            'subject_hash' => $dataset->subjectHash(),
            'mode' => $mode,
            'requested_counts' => $counts,
            'applied_counts' => $applied,
            'operator' => auth()->id(),
        ]);

        foreach ($applied as $label => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $label), (string) $count);
        }

        $this->components->info('Forget request applied.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function anonymize(IdentityDataset $dataset): array
    {
        $counts = [];

        if ($dataset->commentIds !== []) {
            $counts['comments_anonymized'] = Comment::query()->withTrashed()
                ->whereIn('comments.id', $dataset->commentIds)
                ->update([
                    'author_name' => self::ANONYMIZED_NAME,
                    'author_email' => null,
                    'author_id' => null,
                    'user_ip' => null,
                    'user_agent' => null,
                    'referer' => null,
                ]);

            Mirror::rewrite($dataset->commentIds);
            $this->refreshThreads($dataset->commentIds);
        }

        $counts['users_meta_deleted'] = $this->forceDeleteUserMeta($dataset);

        if ($this->option('scrub-moderator-actions')) {
            if ($dataset->revisionIds !== []) {
                $counts['revisions_scrubbed'] = CommentRevision::query()
                    ->whereIn('id', $dataset->revisionIds)
                    ->update(['edited_by' => null]);
            }

            if ($dataset->moderationAuditIds !== []) {
                $counts['moderation_actions_scrubbed'] = CommentModerationAudit::query()
                    ->whereIn('id', $dataset->moderationAuditIds)
                    ->update(['actor_id' => null]);
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function tombstoneAfterAnonymize(IdentityDataset $dataset): array
    {
        $counts = $this->anonymize($dataset);

        if ($dataset->commentIds !== []) {
            Comment::query()
                ->whereIn('comments.id', $dataset->commentIds)
                ->where('comments.is_removed', false)
                ->where('comments.is_published', true)
                ->where('comments.is_spam', false)
                ->whereNotNull('comments.parent_id')
                ->pluck('comments.parent_id')
                ->countBy()
                ->each(function (int $replies, int $parentId): void {
                    Comment::query()->where('comments.id', $parentId)->decrement('replies_count', $replies);
                });

            $counts['comments_tombstoned'] = Comment::query()->withTrashed()
                ->whereIn('comments.id', $dataset->commentIds)
                ->where('comments.is_removed', false)
                ->update([
                    'is_removed' => true,
                    'removed_at' => now(),
                    'removed_by' => auth()->id(),
                    'removed_reason' => 'forget_request',
                ]);

            Mirror::rewrite($dataset->commentIds);
            $this->refreshThreads($dataset->commentIds);
        }

        return $counts;
    }

    /** @param list<int> $commentIds */
    private function refreshThreads(array $commentIds): void
    {
        $threadIds = Comment::query()->withTrashed()
            ->whereIn('comments.id', $commentIds)
            ->distinct()
            ->pluck('comments.thread_id');

        foreach ($threadIds as $threadId) {
            if (! is_string($threadId) || $threadId === '') {
                continue;
            }

            ThreadCache::invalidate($threadId);
            app(ThreadMetricsManager::class)->recalculateThread($threadId);
        }

        InvalidatesGraphQlCache::flush();
    }

    /**
     * @return array<string, int>
     */
    private function hardDelete(IdentityDataset $dataset): array
    {
        $counts = [
            'comments_deleted' => 0,
            'users_meta_deleted' => 0,
        ];

        foreach ($dataset->commentIds as $id) {
            if (! Comment::withTrashed()->whereKey($id)->exists()) {
                continue;
            }

            if (Comments::forceDeleteComment($id)) {
                $counts['comments_deleted']++;
            }
        }

        $counts['users_meta_deleted'] = $this->forceDeleteUserMeta($dataset);

        return $counts;
    }

    private function forceDeleteUserMeta(IdentityDataset $dataset): int
    {
        if ($dataset->userMetaIds === []) {
            return 0;
        }

        $deleted = UserMeta::query()->withTrashed()
            ->whereIn('id', $dataset->userMetaIds)
            ->forceDelete();

        return is_int($deleted) ? $deleted : 0;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
