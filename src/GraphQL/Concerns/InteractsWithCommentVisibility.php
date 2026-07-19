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
        $constraint = function (Relation $relation) use (&$constraint, $hidden): void {
            $query = $relation->getQuery();

            $query->where('is_published', true)
                ->where('is_spam', false)
                ->where('is_removed', false);

            if ($hidden !== []) {
                $query->whereNotIn('comments.id', $hidden);
            }

            $query->with(['userMeta', 'allChildren' => $constraint]);
        };

        return $constraint;
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
