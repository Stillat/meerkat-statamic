<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard\Guards;

use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;

abstract class BaseGuard implements SpamGuard
{
    /** @return array<string, mixed> */
    protected function getCommentSearchSpace(Comment $comment): array
    {
        return array_merge($comment->comment_data ?? [], [
            'comment_text' => $comment->comment_text,
            'author_name' => $comment->resolvedName(),
            'author_email' => $comment->resolvedEmail(),
        ]);
    }
}
