<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Resources\Comments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
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
            $base['is_published'] = (bool) $comment->is_published;
            $base['is_spam'] = (bool) $comment->is_spam;
            $base['is_ham'] = (bool) $comment->is_ham;
            $base['is_removed'] = (bool) $comment->is_removed;
            $base['removed_at'] = $comment->removed_at?->toIso8601ZuluString('millisecond');
            $base['removed_by'] = $comment->removed_by;
            $base['removed_reason'] = $comment->removed_reason;
            $base['checked_for_spam'] = (bool) $comment->checked_for_spam;
            $base['moderation_status'] = $comment->moderation_status;
            $base['moderation_reason'] = $comment->moderation_reason;
            $base['moderation_notes'] = $comment->moderation_notes;
            $base['moderated_by'] = $comment->moderated_by;
            $base['moderated_at'] = $comment->moderated_at?->toIso8601ZuluString('millisecond');
            $base['published_at'] = $comment->published_at?->toIso8601ZuluString('millisecond');
            $base['last_activity_at'] = $comment->last_activity_at?->toIso8601ZuluString('millisecond');
            $base['updated_at'] = $comment->updated_at?->toIso8601ZuluString('millisecond');
            $base['user_ip'] = $comment->user_ip;
            $base['user_agent'] = $comment->user_agent;
            $base['referer'] = $comment->referer;
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
