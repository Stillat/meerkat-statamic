<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Resources\Comments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Comments\PublicCommentData;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Support\CommentMarkdownRenderer;
use Stillat\Meerkat\Support\CommentVisibility;

class PublicCommentResource extends JsonResource
{
    use Hookable;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Comment $comment */
        $comment = $this->resource;

        $privileged = static::requesterCanSeeModerationInternals($comment);

        $base = [
            'id' => $comment->id,
            'thread_id' => $comment->thread_id,
            'parent_id' => $comment->parent_id,
            'depth' => $comment->depth,
            'replies_count' => $comment->replies_count,
            'comment_text' => $comment->comment_text,
            'comment_html' => app(CommentMarkdownRenderer::class)->render($comment->comment_text),
            'author' => [
                'name' => $comment->resolvedName(),
                'gravatar' => $comment->gravatarUrl(),
            ],
            'anchor' => 'comment-'.$comment->id,
            'created_at' => $comment->created_at?->toIso8601ZuluString('millisecond'),
        ];

        if ($privileged) {
            $base['author']['email'] = $comment->publicEmail();
            $base['author']['id'] = $comment->author_id;
            $base = array_merge($base, PublicCommentData::privilegedFields($comment));
        }

        $payload = $this->runHooksWith('data', [
            'comment' => $comment,
            'data' => $base,
            'privileged' => $privileged,
            'request' => $request,
        ]);

        if (! $payload instanceof Payload || ! is_array($payload->data)) {
            return $base;
        }

        $data = [];

        foreach ($payload->data as $key => $value) {
            if (! is_string($key)) {
                return $base;
            }

            $data[$key] = $value;
        }

        return $data;
    }

    public static function requesterCanSeeModerationInternals(?Comment $comment = null): bool
    {
        if ($comment instanceof Comment) {
            return app(CommentVisibility::class)->canViewModerationForComment($comment);
        }

        return auth()->user()?->can('view comments') ?? false;
    }
}
