<?php

declare(strict_types=1);

namespace Stillat\Meerkat\GraphQL\Queries;

use GraphQL\Type\Definition\Type;
use Statamic\Facades\GraphQL;
use Statamic\GraphQL\Queries\Query;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\GraphQL\Concerns\InteractsWithCommentVisibility;
use Stillat\Meerkat\GraphQL\Types\ThreadType;

class ThreadQuery extends Query
{
    use InteractsWithCommentVisibility;

    protected $attributes = [
        'name' => 'meerkatThread',
        'description' => 'A Meerkat thread by its id or entry id.',
    ];

    public function type(): Type
    {
        return GraphQL::type(ThreadType::NAME);
    }

    public function args(): array
    {
        return [
            'id' => ['type' => GraphQL::string()],
            'entry_id' => ['type' => GraphQL::string()],
        ];
    }

    /** @param array<string, mixed> $args */
    public function resolve(mixed $root, array $args): ?Thread
    {
        $threadId = $this->resolveThreadId(
            is_string($args['id'] ?? null) ? $args['id'] : null,
            is_string($args['entry_id'] ?? null) ? $args['entry_id'] : null
        );

        if ($threadId === null) {
            return null;
        }

        return Thread::query()
            ->where('thread_id', $threadId)
            ->first();
    }
}
