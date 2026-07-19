<?php

declare(strict_types=1);

namespace Stillat\Meerkat\GraphQL\Queries;

use GraphQL\Type\Definition\Type;
use Illuminate\Pagination\LengthAwarePaginator;
use Statamic\Facades\GraphQL;
use Statamic\GraphQL\Queries\Query;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\GraphQL\Concerns\InteractsWithCommentVisibility;
use Stillat\Meerkat\GraphQL\Types\CommentType;

class CommentsQuery extends Query
{
    use InteractsWithCommentVisibility;

    protected $attributes = [
        'name' => 'meerkatComments',
        'description' => 'Paginated root comments for a thread, with nested replies.',
    ];

    public function type(): Type
    {
        return GraphQL::paginate(GraphQL::type(CommentType::NAME));
    }

    public function args(): array
    {
        return [
            'thread_id' => ['type' => GraphQL::string()],
            'entry_id' => ['type' => GraphQL::string()],
            'limit' => ['type' => GraphQL::int()],
            'page' => ['type' => GraphQL::int()],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return LengthAwarePaginator<int, Comment>
     */
    public function resolve(mixed $root, array $args): LengthAwarePaginator
    {
        $perPage = $this->resolvePerPage($this->nullableInteger($args['limit'] ?? null));
        $page = max(1, $this->nullableInteger($args['page'] ?? null) ?? 1);

        $threadId = $this->resolveThreadId(
            is_string($args['thread_id'] ?? null) ? $args['thread_id'] : null,
            is_string($args['entry_id'] ?? null) ? $args['entry_id'] : null
        );

        if ($threadId === null) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        $hidden = $this->hiddenIds($threadId);

        $query = Comments::query()
            ->forThread($threadId)
            ->published()
            ->where('is_spam', false)
            ->where('comments.is_removed', false);

        if ($hidden !== []) {
            $query->whereNotIn('comments.id', $hidden);
        }

        return $query
            ->roots()
            ->hierarchical()
            ->with(['userMeta', 'allChildren' => $this->publicChildrenConstraint($hidden)])
            ->paginate($perPage, ['*'], 'page', $page);
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
