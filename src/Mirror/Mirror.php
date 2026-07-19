<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Mirror;

use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\Thread;

class Mirror
{
    public static function enabled(): bool
    {
        return (bool) config('meerkat.mirror.enabled', true);
    }

    public static function root(): string
    {
        $configured = config('meerkat.mirror.path');

        if (is_string($configured) && $configured !== '') {
            return rtrim(str_replace('\\', '/', $configured), '/');
        }

        return rtrim(str_replace('\\', '/', base_path('content/comments')), '/');
    }

    public static function writer(): MirrorWriter
    {
        return new MirrorWriter(self::pathResolver());
    }

    public static function pathResolver(): MirrorPathResolver
    {
        return new MirrorPathResolver(self::root());
    }

    public static function handleSaved(Comment $comment): void
    {
        if (! self::enabled() || MirrorWriter::isSuppressed()) {
            return;
        }

        self::writer()->write($comment);
    }

    public static function handleForceDeleted(Comment $comment): void
    {
        if (! self::enabled() || MirrorWriter::isSuppressed()) {
            return;
        }

        self::writer()->remove($comment);
    }

    public static function handleThreadSaved(Thread $thread): void
    {
        if (! self::enabled() || MirrorWriter::isSuppressed()) {
            return;
        }

        self::writer()->writeThreadMeta($thread);
    }

    public static function handleThreadForceDeleted(Thread $thread): void
    {
        if (! self::enabled() || MirrorWriter::isSuppressed()) {
            return;
        }

        self::writer()->removeThread($thread);
    }

    public static function handleRevisionCreated(CommentRevision $revision): void
    {
        if (! self::enabled() || MirrorWriter::isSuppressed()) {
            return;
        }

        $comment = Comment::query()
            ->withTrashed()
            ->where('comments.id', $revision->comment_id)
            ->first();

        if ($comment === null) {
            return;
        }

        self::writer()->writeRevisions($comment);
    }

    /**
     * @param  iterable<int|string>  $commentIds
     */
    public static function rewrite(iterable $commentIds): int
    {
        if (! self::enabled() || MirrorWriter::isSuppressed()) {
            return 0;
        }

        $ids = array_values(array_unique(array_map(intval(...), iterator_to_array(
            (function () use ($commentIds) {
                yield from $commentIds;
            })(),
            false,
        ))));

        if ($ids === []) {
            return 0;
        }

        $writer = self::writer();
        $written = 0;

        foreach (Comment::query()->withTrashed()->whereIn('comments.id', $ids)->cursor() as $comment) {
            $writer->write($comment);
            $written++;
        }

        return $written;
    }
}
