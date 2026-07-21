<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use Illuminate\Support\Facades\Cache;

class ThreadCache
{
    public static function key(string $threadId, bool $publishedOnly): string
    {
        $viewer = auth()->id();
        $viewer = is_string($viewer) || is_int($viewer) ? (string) $viewer : 'guest';

        return 'meerkat.thread.'.md5(implode('|', [
            $threadId,
            (string) self::generation($threadId),
            $publishedOnly ? 'published' : 'all',
            $viewer,
        ]));
    }

    public static function invalidate(string $threadId): void
    {
        $key = self::generationKey($threadId);

        if (! Cache::add($key, 1)) {
            Cache::increment($key);
        }
    }

    private static function generation(string $threadId): int
    {
        $generation = Cache::get(self::generationKey($threadId), 0);

        return is_int($generation) ? $generation : 0;
    }

    private static function generationKey(string $threadId): string
    {
        return 'meerkat.thread-generation.'.md5($threadId);
    }
}
