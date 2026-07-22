<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Contracts;

interface FieldExtractor
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function extract(array $data): array;
}
