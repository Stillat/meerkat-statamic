<?php

declare(strict_types=1);

namespace Stillat\Meerkat\GraphQL\Queries;

use GraphQL\Type\Definition\Type;
use Statamic\Facades\GraphQL;
use Statamic\GraphQL\Queries\Query;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\GraphQL\Concerns\InteractsWithCommentVisibility;
use Stillat\Meerkat\GraphQL\Types\CommentType;

class CommentQuery extends Query
{
    use InteractsWithCommentVisibility;

    protected $attributes = [
        'name' => 'meerkatComment',
        'description' => 'A single Meerkat comment by id, with nested replies.',
    ];

    public function type(): Type
    {
        return GraphQL::type(CommentType::NAME);
    }

    public function args(): array
    {
        return [
            'id' => ['type' => GraphQL::nonNull(GraphQL::id())],
        ];
    }

    /** @param array<string, mixed> $args */
    public function resolve(mixed $root, array $args): ?Comment
    {
        $id = $args['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $comment = Comment::query()->find($id);

        if ($comment === null || ! $this->visibility()->isPublicVisible($comment)) {
            return null;
        }

        $hidden = $this->visibility()->hiddenIdsForThread($comment->thread_id);

        $comment->load(['userMeta', 'allChildren' => $this->publicChildrenConstraint($hidden)]);

        return $comment;
    }
}
