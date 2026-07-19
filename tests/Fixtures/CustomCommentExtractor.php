<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Fixtures;

use Stillat\Meerkat\Contracts\FieldExtractor;

class CustomCommentExtractor implements FieldExtractor
{
    public function extract(array $data): array
    {
        return [
            'comment' => $data['body'] ?? null,
        ];
    }
}
