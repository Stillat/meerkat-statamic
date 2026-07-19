<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Events;

use Statamic\Events\Event;
use Stillat\Meerkat\Database\Models\Comment;

class CommentSaved extends Event
{
    public function __construct(public Comment $comment) {}
}
