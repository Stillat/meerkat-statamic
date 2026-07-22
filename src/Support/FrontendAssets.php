<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

class FrontendAssets
{
    public static function repliesPath(): string
    {
        return __DIR__.'/../../resources/dist/replies.js';
    }

    public static function repliesVersion(): string
    {
        $path = self::repliesPath();

        return is_file($path) ? substr((string) md5_file($path), 0, 12) : 'dev';
    }

    public static function repliesUrl(): string
    {
        return route('meerkat.assets.replies', ['v' => self::repliesVersion()]);
    }
}
