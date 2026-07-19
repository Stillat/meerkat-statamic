<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Data;

interface RetrievesDataValue
{
    public function getDataValue(string $key): mixed;

    public function hasDataValue(string $key): bool;
}
