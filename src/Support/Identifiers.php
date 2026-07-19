<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

class Identifiers
{
    public static function visualId(int|string $id): string
    {
        return str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public static function initials(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        if (count($parts) === 0) {
            return '?';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }

        return mb_strtoupper(
            mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1)
        );
    }
}
