<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Stillat\Meerkat\Concerns\GetsMeerkatPermissions;
use Stillat\Meerkat\Database\CommentQueryBuilder;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Scopes\AuthorDetailsScope;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Permissions\Permissions;

class CommentVisibility
{
    use GetsMeerkatPermissions;

    /**
     * @var array<string, list<int>>
     */
    private array $hiddenIdsByThread = [];

    /**
     * @param  Builder<Comment>|CommentQueryBuilder  $query
     * @return Builder<Comment>|CommentQueryBuilder
     */
    public function applyPublicVisibility(Builder|CommentQueryBuilder $query, ?string $threadId = null): Builder|CommentQueryBuilder
    {
        $query
            ->where('comments.is_published', true)
            ->where('comments.is_spam', false)
            ->where('comments.is_removed', false);

        if ($threadId !== null) {
            $hidden = $this->hiddenIdsForThread($threadId);

            if ($hidden !== []) {
                $query->whereNotIn('comments.id', $hidden);
            }
        }

        return $query;
    }

    /**
     * @param  Builder<Comment>|CommentQueryBuilder  $query
     * @return Builder<Comment>|CommentQueryBuilder
     */
    public function applyAccessibleScope(Builder|CommentQueryBuilder $query, ?Permissions $permissions = null): Builder|CommentQueryBuilder
    {
        $permissions ??= $this->getPermissions();

        if ($permissions->hasCollectionRestrictions) {
            if ($permissions->accessibleCollections === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('comments.collection', $permissions->accessibleCollections);
        }

        if ($permissions->hasSiteRestrictions) {
            if ($permissions->accessibleSites === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('comments.site', $permissions->accessibleSites);
        }

        return $query;
    }

    public function canViewModerationForComment(Comment $comment): bool
    {
        $permissions = $this->getPermissions();

        return $permissions->canViewComments
            && $permissions->canAccessCollection($comment->collection)
            && $permissions->canAccessSite($comment->site);
    }

    public function canViewModeration(): bool
    {
        return $this->getPermissions()->canViewComments;
    }

    public function canViewModerationEverywhere(): bool
    {
        $permissions = $this->getPermissions();

        return $permissions->canViewComments
            && ! $permissions->hasCollectionRestrictions
            && ! $permissions->hasSiteRestrictions;
    }

    public function canViewModerationForThread(string $threadId): bool
    {
        $permissions = $this->getPermissions();

        if (! $permissions->canViewComments) {
            return false;
        }

        [$site, $collection] = $this->threadSiteAndCollection($threadId);

        if ($collection !== null && ! $permissions->canAccessCollection($collection)) {
            return false;
        }

        if ($site !== null && ! $permissions->canAccessSite($site)) {
            return false;
        }

        if ($collection === null && $permissions->hasCollectionRestrictions) {
            return false;
        }

        return $site !== null || ! $permissions->hasSiteRestrictions;
    }

    public function isPublicVisible(Comment $comment): bool
    {
        if (! $comment->is_published || $comment->is_spam || $comment->is_removed || $comment->trashed()) {
            return false;
        }

        return ! in_array($comment->id, $this->hiddenIdsForThread($comment->thread_id), true);
    }

    /**
     * @return list<int>
     */
    public function hiddenIdsForThread(string $threadId): array
    {
        return $this->hiddenIdsByThread[$threadId] ??= Comments::hiddenSubtreeIds($threadId, false, false);
    }

    public function publicCount(string $threadId, ?string $site = null): int
    {
        $query = Comment::query()->where('comments.thread_id', $threadId);
        $this->applyPublicVisibility($query, $threadId);
        $this->excludeOrphanedSubtrees($query);
        $this->applySiteFilter($query, $site);

        return $query->count();
    }

    /**
     * Excludes descendants of non-visible ancestors via the materialized
     * `path` column, matching the rendered tree's pruning.
     *
     * @param  Builder<Comment>|CommentQueryBuilder  $query
     * @return Builder<Comment>|CommentQueryBuilder
     */
    public function excludeOrphanedSubtrees(Builder|CommentQueryBuilder $query): Builder|CommentQueryBuilder
    {
        $base = $query->getQuery();

        $grammar = $base->getGrammar();
        $path = $grammar->wrap('comments.path');
        $ancestorPath = $grammar->wrap('hidden_ancestors.path');

        $connection = $base->getConnection();
        $driver = $connection instanceof Connection
            ? $connection->getDriverName()
            : '';
        $pattern = in_array($driver, ['mysql', 'mariadb'], true)
            ? "CONCAT({$ancestorPath}, '.%')"
            : "({$ancestorPath} || '.%')";

        $query->whereNotExists(function (\Illuminate\Database\Query\Builder $sub) use ($path, $pattern): void {
            $sub->from('comments as hidden_ancestors')
                ->whereColumn('hidden_ancestors.thread_id', 'comments.thread_id')
                ->where(function (\Illuminate\Database\Query\Builder $hidden): void {
                    $hidden->where('hidden_ancestors.is_published', false)
                        ->orWhere('hidden_ancestors.is_spam', true)
                        ->orWhere('hidden_ancestors.is_removed', true)
                        ->orWhereNotNull('hidden_ancestors.deleted_at');
                })
                ->whereRaw("{$path} LIKE {$pattern}");
        });

        return $query;
    }

    /**
     * @return list<Comment>
     */
    public function recentPublicComments(int $limit, ?string $site = null): array
    {
        $query = Comment::query()->orderByDesc('comments.created_at');
        $this->excludeOrphanedSubtrees($query);
        $this->applySiteFilter($query, $site);

        return $this->collectPublicComments($query, $limit);
    }

    /**
     * @return list<Comment>
     */
    public function publicAuthorHistory(string $identifier, int $limit, ?string $site = null): array
    {
        $query = Comments::query()->byAuthor($identifier)->newest();
        $this->excludeOrphanedSubtrees($query);
        $this->applySiteFilter($query, $site);

        return $this->collectPublicComments($query, $limit);
    }

    /**
     * @return list<Comment>
     */
    public function publicSearch(string $term, int $limit): array
    {
        $query = Comments::query()->searchAll($term)->newest();
        $this->excludeOrphanedSubtrees($query);

        return $this->collectPublicComments($query, $limit);
    }

    /**
     * @return list<array{thread_id: string, comment_count: int, participant_count: int, last_activity: mixed}>
     */
    public function topPublicThreads(int $limit, ?string $site = null): array
    {
        $aggregate = Comment::query()->withoutGlobalScope(AuthorDetailsScope::class);
        $this->applyPublicVisibility($aggregate);
        $this->excludeOrphanedSubtrees($aggregate);
        $this->applySiteFilter($aggregate, $site);

        $createdAt = $aggregate->getQuery()->getGrammar()->wrap('comments.created_at');

        $threadIds = $aggregate
            ->groupBy('comments.thread_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByRaw("MAX({$createdAt}) DESC")
            ->limit($limit)
            ->pluck('comments.thread_id')
            ->all();

        $rows = [];

        foreach ($threadIds as $threadId) {
            if (! is_string($threadId)) {
                continue;
            }

            $query = Comment::query()->where('comments.thread_id', $threadId);
            $this->applyPublicVisibility($query);
            $this->excludeOrphanedSubtrees($query);
            $this->applySiteFilter($query, $site);
            $comments = $query->get();

            if ($comments->isEmpty()) {
                continue;
            }

            $rows[] = [
                'thread_id' => $threadId,
                'comment_count' => $comments->count(),
                'participant_count' => $this->participantCount($comments),
                'last_activity' => $comments->max('created_at'),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function publicMetricArray(string $threadId, ?string $siteFilter = null): array
    {
        [$site, $collection] = $this->threadSiteAndCollection($threadId);

        $query = Comment::query()->where('comments.thread_id', $threadId);
        $this->applyPublicVisibility($query, $threadId);
        $this->excludeOrphanedSubtrees($query);
        $this->applySiteFilter($query, $siteFilter);
        $comments = $query->get()->values();

        return [
            'thread_id' => $threadId,
            'site' => $site,
            'collection' => $collection,
            'total_comments' => $comments->count(),
            'published_comments' => $comments->count(),
            'pending_comments' => 0,
            'spam_comments' => 0,
            'root_comments' => $comments->filter(fn (Comment $comment) => $comment->parent_id === null)->count(),
            'reply_comments' => $comments->filter(fn (Comment $comment) => $comment->parent_id !== null)->count(),
            'participants' => $this->participantCount($comments),
            'max_depth' => $this->integerValue($comments->max('depth')),
            'first_comment_at' => $comments->min('created_at'),
            'last_activity_at' => $comments->max(fn (Comment $comment) => $comment->last_activity_at ?? $comment->created_at),
            'metadata' => [],
        ];
    }

    /**
     * @param  Builder<Comment>|CommentQueryBuilder  $query
     */
    private function applySiteFilter(Builder|CommentQueryBuilder $query, ?string $site): void
    {
        if ($site !== null && $site !== '*') {
            $query->where('comments.site', $site);
        }
    }

    private function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function threadSiteAndCollection(string $threadId): array
    {
        $thread = Thread::query()->where('thread_id', $threadId)->first();

        $site = $thread?->site;
        $collection = $thread?->collection;

        if ($site !== null && $collection !== null) {
            return [$site, $collection];
        }

        $comment = Comment::query()
            ->where('comments.thread_id', $threadId)
            ->latest('comments.created_at')
            ->first();

        return [
            $site ?? $comment?->site,
            $collection ?? $comment?->collection,
        ];
    }

    /**
     * @param  Builder<Comment>|CommentQueryBuilder  $query
     * @return list<Comment>
     */
    private function collectPublicComments(Builder|CommentQueryBuilder $query, int $limit): array
    {
        $this->applyPublicVisibility($query);

        $comments = [];
        $page = 1;
        $chunkSize = max($limit * 2, 25);

        do {
            $chunk = $query->forPage($page, $chunkSize)->get();

            foreach ($chunk as $comment) {
                if ($this->isPublicVisible($comment)) {
                    $comments[] = $comment;
                }

                if (count($comments) >= $limit) {
                    return array_slice($comments, 0, $limit);
                }
            }

            $page++;
        } while ($chunk->count() === $chunkSize);

        return $comments;
    }

    /**
     * @param  Collection<int, Comment>  $comments
     */
    private function participantCount(Collection $comments): int
    {
        return $comments
            ->map(fn (Comment $comment) => $comment->author_id ?: $comment->author_email)
            ->filter()
            ->unique()
            ->count();
    }
}
