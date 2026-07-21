<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database;

use Illuminate\Database\Migrations\Migration as BaseMigration;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class Migration extends BaseMigration
{
    public function getConnection(): ?string
    {
        $connection = config('meerkat.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    protected function schema(): Builder
    {
        return Schema::connection($this->getConnection());
    }

    /**
     * True when the table exists and is Meerkat's; throws when a same-named
     * table belongs to another application.
     *
     * @param  list<string>  $sentinelColumns
     */
    protected function hasMeerkatTable(string $table, array $sentinelColumns): bool
    {
        $schema = $this->schema();

        if (! $schema->hasTable($table)) {
            return false;
        }

        if ($schema->hasColumns($table, $sentinelColumns)) {
            return true;
        }

        throw new RuntimeException(
            "Refusing to modify the existing [{$table}] table: it is missing Meerkat's ["
            .implode(', ', $sentinelColumns).'] columns. '
            .'Point MEERKAT_DATABASE_CONNECTION at a connection with a table prefix and re-run the migrations.'
        );
    }

    /**
     * Drops the table only when it carries Meerkat's sentinel columns.
     *
     * @param  list<string>  $sentinelColumns
     */
    protected function dropMeerkatTable(string $table, array $sentinelColumns): void
    {
        $schema = $this->schema();

        if ($schema->hasTable($table) && $schema->hasColumns($table, $sentinelColumns)) {
            $schema->drop($table);
        }
    }
}
