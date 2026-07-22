<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Events;

use Statamic\Events\Event;
use Statamic\Facades\User;
use Stillat\Meerkat\Database\Models\Comment;

final class CommentSubmitted extends Event
{
    public function __construct(public Comment $comment) {}

    public static function dispatch(?Comment $comment = null): mixed
    {
        if (! $comment instanceof Comment) {
            throw new \InvalidArgumentException('A comment is required when dispatching CommentSubmitted.');
        }

        $event = new self($comment);

        $event->authenticatedUser = User::current();

        return event($event, [], true);
    }
}
