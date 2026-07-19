<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database;

use Illuminate\Database\Migrations\Migration as BaseMigration;

abstract class Migration extends BaseMigration
{
    public function getConnection(): ?string
    {
        $connection = config('meerkat.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
