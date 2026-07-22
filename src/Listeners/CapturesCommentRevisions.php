<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentRevision;

class CapturesCommentRevisions
{
    public static function register(): void
    {
        Comment::hook('saved', function (mixed $payload) {
            if (! $payload instanceof Payload || ! $payload->model instanceof Comment) {
                throw new \UnexpectedValueException('The saved hook must provide a comment model.');
            }

            app(CapturesCommentRevisions::class)
                ->handle($payload->model, (bool) $payload->was_created);

            return $payload;
        });
    }

    public function handle(Comment $comment, bool $wasCreated): void
    {
        if ($comment->skipHooks) {
            return;
        }

        if (! $wasCreated && ! $comment->wasChanged(['comment_text', 'comment_data'])) {
            return;
        }

        $attributes = [
            'comment_text' => $comment->comment_text,
            'comment_data' => $comment->comment_data,
            'edited_by' => auth()->id(),
            'edit_reason' => null,
            'edited_at' => $wasCreated ? ($comment->created_at ?? now()) : now(),
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $current = CommentRevision::query()
                ->where('comment_id', $comment->id)
                ->max('revision_number');
            $current = is_int($current)
                ? $current
                : (is_string($current) && is_numeric($current) ? (int) $current : 0);
            $next = $current + 1;

            try {
                CommentRevision::create(['comment_id' => $comment->id, 'revision_number' => $next] + $attributes);

                return;
            } catch (UniqueConstraintViolationException) {
                // Retry when a concurrent save claims the same revision number.
            }
        }

        throw new RuntimeException("Unable to allocate a revision number for comment {$comment->id}.");
    }
}
