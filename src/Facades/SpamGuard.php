<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Facades;

use Illuminate\Support\Facades\Facade;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Guard\Manager;

/**
 * @method static bool isSpam(Entry $entry, Comment $comment)
 * @method static void reportSpam(Entry $entry, Comment $comment)
 * @method static void reportHam(Entry $entry, Comment $comment)
 * @method static void reportSpamById(int $id)
 * @method static void reportHamById(int $id)
 */
class SpamGuard extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Manager::class;
    }
}
