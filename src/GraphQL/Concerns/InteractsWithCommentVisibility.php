<?php

declare(strict_types=1);

namespace Stillat\Meerkat\GraphQL\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Statamic\Facades\Entry;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Services\ThreadResolver;
use Stillat\Meerkat\Support\CommentVisibility;

trait InteractsWithCommentVisibility
{
    protected function visibility(): CommentVisibility
    {
        return app(CommentVisibility::class);
    }

    protected function resolveThreadId(?string $threadId, ?string $entryId): ?string
    {
        if ($threadId !== null && Thread::query()->where('thread_id', $threadId)->exists()) {
            return $threadId;
        }

        $entryId ??= $threadId;

        if ($entryId === null) {
            return null;
        }

        $entry = Entry::find($entryId);

        return $entry !== null ? app(ThreadResolver::class)->forEntry($entry) : null;
    }

    /**
     * @param  list<int>  $hidden
     */
    protected function publicChildrenConstraint(array $hidden): Closure
    {
        return $this->childrenConstraintAtDepth($hidden, 1, $this->maxHydrationDepth());
    }

    /**
     * @param  list<int>  $hidden
     */
    private function childrenConstraintAtDepth(array $hidden, int $depth, int $maxDepth): Closure
    {
        return function (Relation $relation) use ($hidden, $depth, $maxDepth): void {
            $query = $relation->getQuery();

            $query->where('is_published', true)
                ->where('is_spam', false)
                ->where('is_removed', false);

            if ($hidden !== []) {
                $query->whereNotIn('comments.id', $hidden);
            }

            $query->with('userMeta');

            // allChildren eager-loads itself by definition; past the cap it must be removed.
            $depth < $maxDepth
                ? $query->with(['allChildren' => $this->childrenConstraintAtDepth($hidden, $depth + 1, $maxDepth)])
                : $query->without('allChildren');
        };
    }

    private function maxHydrationDepth(): int
    {
        $writeDepth = $this->integerGraphqlConfig('meerkat.publishing.max_reply_depth', 0);

        return $writeDepth > 0
            ? $writeDepth
            : max(1, $this->integerGraphqlConfig('meerkat.graphql.max_depth', 25));
    }

    protected function resolvePerPage(?int $limit): int
    {
        $default = $this->integerGraphqlConfig('meerkat.graphql.per_page', 15);
        $max = $this->integerGraphqlConfig('meerkat.graphql.max_per_page', 100);
        $perPage = $limit !== null && $limit > 0 ? $limit : $default;

        return max(1, min($perPage, $max));
    }

    private function integerGraphqlConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<int>
     */
    protected function hiddenIds(string $threadId): array
    {
        return Comments::hiddenSubtreeIds($threadId, false, false);
    }
}
