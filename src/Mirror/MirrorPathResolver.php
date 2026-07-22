<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Mirror;

use RuntimeException;
use Stillat\Meerkat\Database\Models\Comment;

class MirrorPathResolver
{
    public function __construct(private readonly string $root) {}

    public function directoryFor(Comment $comment): string
    {
        $chain = $this->ancestorTimestampChain($comment);

        $parts = [$this->root, $comment->thread_id, $chain[0]];

        for ($i = 1, $n = count($chain); $i < $n; $i++) {
            $parts[] = 'replies';
            $parts[] = $chain[$i];
        }

        return implode('/', $parts);
    }

    public function fileFor(Comment $comment): string
    {
        return $this->directoryFor($comment).'/comment.md';
    }

    public function threadDirectory(string $threadId): string
    {
        return $this->root.'/'.$threadId;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return list<string>
     */
    public function ancestorTimestampChain(Comment $comment): array
    {
        $chain = [];
        $current = $comment;
        $depth = 0;

        while ($current !== null) {
            if ($depth++ > 64) {
                throw new RuntimeException("Comment ancestor chain exceeded 64 levels for comment {$comment->id}; refusing to recurse further.");
            }

            $ts = $current->timestamp_id;

            if ($ts === null || $ts === '') {
                throw new RuntimeException("Comment {$current->id} is missing a timestamp_id; cannot resolve mirror path for comment {$comment->id}.");
            }

            array_unshift($chain, $ts);

            if ($current->parent_id === null) {
                break;
            }

            $current = Comment::query()->withTrashed()->where('comments.id', $current->parent_id)->first();
        }

        return $chain;
    }
}
