<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Fixtures;

use Stillat\Meerkat\Contracts\FieldExtractor;
use Stillat\Meerkat\Extractors\AuthorExtractor;

class CustomAuthorExtractor implements FieldExtractor
{
    public function extract(array $data): array
    {
        return [
            'name' => AuthorExtractor::normalizeName(is_string($data['display_name'] ?? null) ? $data['display_name'] : null),
            'email' => AuthorExtractor::normalizeEmail(is_string($data['contact_email'] ?? null) ? $data['contact_email'] : null),
        ];
    }
}
