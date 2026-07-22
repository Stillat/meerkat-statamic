<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

class ContextSigner
{
    public static function sign(string $context): string
    {
        return hash_hmac('sha256', $context, self::key());
    }

    public static function verify(string $context, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(self::sign($context), $signature);
    }

    private static function key(): string
    {
        $key = config('app.key');

        return is_string($key) ? $key : '';
    }
}
