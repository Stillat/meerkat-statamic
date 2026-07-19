<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use Illuminate\Support\Facades\Cache;

class ThreadCache
{
    public static function key(string $threadId, bool $publishedOnly): string
    {
        return 'meerkat.thread.'.md5($threadId.'|'.($publishedOnly ? 'published' : 'all'));
    }

    public static function invalidate(string $threadId): void
    {
        Cache::forget(self::key($threadId, true));
        Cache::forget(self::key($threadId, false));
    }
}
