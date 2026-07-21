<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Statamic\Contracts\GraphQL\ResponseCache;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Events\CommentSaved;

class InvalidatesGraphQlCache
{
    public static function register(): void
    {
        $flush = static function (Comment $comment): void {
            app(ResponseCache::class)->handleInvalidationEvent(new CommentSaved($comment));
        };

        Comment::created($flush);
        Comment::updated($flush);
        Comment::deleted($flush);
        Comment::restored($flush);
    }

    public static function flush(): void
    {
        if (! app()->bound(ResponseCache::class)) {
            return;
        }

        app(ResponseCache::class)->handleInvalidationEvent(new CommentSaved(new Comment));
    }
}
