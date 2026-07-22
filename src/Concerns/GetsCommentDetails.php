<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Concerns;

use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryApi;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\ThreadResolver;

trait GetsCommentDetails
{
    /** @return array{Entry, Comment}|null */
    protected function getCommentDetails(int $id): ?array
    {
        $comment = Comment::query()->find($id);

        if (! $comment) {
            return null;
        }

        $entry = app(ThreadResolver::class)->resolveEntry($comment->thread_id) ?? EntryApi::find($comment->thread_id);

        if (! $entry) {
            return null;
        }

        return [$entry, $comment];
    }
}
