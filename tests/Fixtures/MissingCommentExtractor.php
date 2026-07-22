<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Fixtures;

use Stillat\Meerkat\Contracts\FieldExtractor;

class MissingCommentExtractor implements FieldExtractor
{
    public function extract(array $data): array
    {
        return [];
    }
}
