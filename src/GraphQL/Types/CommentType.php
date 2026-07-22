<?php

declare(strict_types=1);

namespace Stillat\Meerkat\GraphQL\Types;

use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Collection;
use Rebing\GraphQL\Support\Type;
use Statamic\Exceptions\BlueprintNotFoundException;
use Statamic\Facades\GraphQL;
use Stillat\Meerkat\Blueprints\CommentBlueprint;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Support\CommentMarkdownRenderer;

class CommentType extends Type
{
    const NAME = 'MeerkatComment';

    /**
     * @var list<string>
     */
    private const HANDLED_EXPLICITLY = [
        'name', 'email', 'created_at', 'thread_id',
        'author_id', 'collection', 'site', 'is_spam',
        'is_published', 'moderation_status',
        'moderation_reason', 'moderation_notes',
    ];

    protected $attributes = [
        'name' => self::NAME,
        'description' => 'A public Meerkat comment.',
    ];

    public function fields(): array
    {
        return $this->blueprintFields()
            ->except(self::HANDLED_EXPLICITLY)
            ->map(fn (mixed $field): array => $this->fieldDefinition($field))
            ->merge($this->explicitFields())
            ->map(function (array $field): array {
                $field['resolve'] ??= $this->defaultResolver();

                return $field;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function fieldDefinition(mixed $field): array
    {
        if (! is_array($field)) {
            return ['type' => $field];
        }

        $definition = [];

        foreach ($field as $key => $value) {
            if (is_string($key)) {
                $definition[$key] = $value;
            }
        }

        return $definition;
    }

    /**
     * @return Collection<string, mixed>
     */
    private function blueprintFields()
    {
        $blueprint = config('meerkat.form.blueprint', 'meerkat');

        try {
            $fields = CommentBlueprint::getBlueprint(
                is_string($blueprint) ? $blueprint : 'meerkat'
            )->fields()->toGql();

            if (! $fields instanceof Collection) {
                throw new \LogicException('Statamic returned invalid GraphQL blueprint fields.');
            }

            $normalized = [];

            foreach ($fields as $handle => $field) {
                if (is_string($handle)) {
                    $normalized[$handle] = $field;
                }
            }

            return collect($normalized);
        } catch (BlueprintNotFoundException) {
            return collect();
        }
    }

    /**
     * @return Collection<string, array{type: mixed, resolve: Closure}>
     */
    private function explicitFields()
    {
        return collect([
            'id' => $this->explicitField(GraphQL::nonNull(GraphQL::id()), fn (Comment $c) => (string) $c->id),
            'thread_id' => $this->explicitField(GraphQL::string(), fn (Comment $c) => $c->thread_id),
            'parent_id' => $this->explicitField(GraphQL::int(), fn (Comment $c) => $c->parent_id),
            'depth' => $this->explicitField(GraphQL::int(), fn (Comment $c) => (int) $c->depth),
            'replies_count' => $this->explicitField(GraphQL::int(), fn (Comment $c) => (int) $c->replies_count),
            'anchor' => $this->explicitField(GraphQL::string(), fn (Comment $c) => 'comment-'.$c->id),
            'name' => $this->explicitField(GraphQL::string(), fn (Comment $c) => $c->resolvedName()),
            'gravatar' => $this->explicitField(GraphQL::string(), fn (Comment $c) => $c->gravatarUrl()),
            'comment_text' => $this->explicitField(GraphQL::string(), fn (Comment $c) => $c->comment_text),
            'comment_html' => $this->explicitField(GraphQL::string(), fn (Comment $c) => app(CommentMarkdownRenderer::class)->render($c->comment_text)),
            'created_at' => $this->explicitField(GraphQL::string(), fn (Comment $c) => $c->created_at?->toIso8601ZuluString('millisecond')),
            'replies' => $this->explicitField(GraphQL::listOf(GraphQL::type(self::NAME)), $this->repliesResolver()),
        ]);
    }

    /** @return array{type: mixed, resolve: Closure} */
    private function explicitField(mixed $type, Closure $resolve): array
    {
        return ['type' => $type, 'resolve' => $resolve];
    }

    private function defaultResolver(): Closure
    {
        return fn (Comment $comment, mixed $args, mixed $context, ResolveInfo $info): mixed => $comment->resolveGqlValue($info->fieldName);
    }

    private function repliesResolver(): Closure
    {
        return function (Comment $comment) {
            if ($comment->relationLoaded('allChildren')) {
                return $comment->allChildren;
            }

            if ($comment->relationLoaded('children')) {
                return $comment->children;
            }

            return [];
        };
    }
}
