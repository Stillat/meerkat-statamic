<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Contracts;

use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Database\Models\Comment;

interface SpamGuard
{
    public function isSpam(Entry $entry, Comment $comment): bool;

    public function reportHam(Entry $entry, Comment $comment): void;

    public function reportSpam(Entry $entry, Comment $comment): void;
}
