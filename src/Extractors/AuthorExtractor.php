<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Extractors;

use Stillat\Meerkat\Contracts\FieldExtractor;

class AuthorExtractor implements FieldExtractor
{
    public function extract(array $data): array
    {
        return [
            'name' => self::normalizeName(is_string($data['name'] ?? null) ? $data['name'] : null),
            'email' => self::normalizeEmail(is_string($data['email'] ?? null) ? $data['email'] : null),
        ];
    }

    public static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $trimmed = trim($email);

        if ($trimmed === '') {
            return null;
        }

        return mb_strtolower($trimmed);
    }

    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        return $trimmed === '' ? null : $trimmed;
    }
}
