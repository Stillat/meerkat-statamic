<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Hooks;

use Statamic\Contracts\Entries\Entry;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\SpamGuard;
use UnexpectedValueException;

class CommentSpamCheck
{
    use Hookable;

    /**
     * @return array{entry: Entry, comment: Comment, is_spam: bool}
     */
    public function resolve(Entry $entry, Comment $comment): array
    {
        $payload = $this->runHooksWith('checking', [
            'entry' => $entry,
            'comment' => $comment,
            'should_check' => true,
            'is_spam' => null,
        ]);

        if (! $payload instanceof Payload) {
            throw new UnexpectedValueException('Comment spam check hooks must return a payload.');
        }

        $entry = $payload->entry;
        $comment = $payload->comment;

        if (! $entry instanceof Entry || ! $comment instanceof Comment) {
            throw new UnexpectedValueException('Comment spam check hooks must return an entry and comment.');
        }

        if ($payload->should_check === false) {
            return [
                'entry' => $entry,
                'comment' => $comment,
                'is_spam' => (bool) $payload->is_spam,
            ];
        }

        return [
            'entry' => $entry,
            'comment' => $comment,
            'is_spam' => SpamGuard::isSpam($entry, $comment),
        ];
    }
}
