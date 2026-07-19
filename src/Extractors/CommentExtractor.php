<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Extractors;

use Stillat\Meerkat\Contracts\FieldExtractor;

class CommentExtractor implements FieldExtractor
{
    public function extract(array $data): array
    {
        return [
            'comment' => $data['comment'] ?? null,
        ];
    }
}
