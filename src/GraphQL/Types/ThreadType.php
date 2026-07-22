<?php

declare(strict_types=1);

namespace Stillat\Meerkat\GraphQL\Types;

use Rebing\GraphQL\Support\Type;
use Statamic\Facades\GraphQL;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Support\CommentVisibility;

class ThreadType extends Type
{
    const NAME = 'MeerkatThread';

    protected $attributes = [
        'name' => self::NAME,
        'description' => 'A Meerkat comment thread, usually attached to an entry.',
    ];

    public function fields(): array
    {
        return [
            'id' => ['type' => GraphQL::nonNull(GraphQL::id()), 'resolve' => fn (Thread $t) => $t->thread_id],
            'thread_id' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->thread_id],
            'entry_id' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->entry_id],
            'site' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->site],
            'collection' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->collection],
            'title' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->cached_title],
            'comments_count' => [
                'type' => GraphQL::int(),
                'resolve' => fn (Thread $t) => app(CommentVisibility::class)->publicCount($t->thread_id, $t->site),
            ],
            'created_at' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->created_at?->toIso8601ZuluString('millisecond')],
            'updated_at' => ['type' => GraphQL::string(), 'resolve' => fn (Thread $t) => $t->updated_at?->toIso8601ZuluString('millisecond')],
        ];
    }
}
