<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stillat\Meerkat\Tests\TestCase;

class MigrationSafetyTest extends TestCase
{
    #[Test]
    public function up_refuses_to_adopt_a_foreign_comments_table(): void
    {
        $this->useThrowawayConnection('meerkat_collision');

        Schema::connection('meerkat_collision')->create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
        });

        DB::connection('meerkat_collision')->table('comments')->insert([
            'title' => 'Host post',
            'body' => 'This table belongs to the host application.',
        ]);

        $migration = $this->migration('2026_05_12_000000_create_comments_table.php');

        try {
            $this->runMigrationUp($migration);
            $this->fail('Expected the migration to refuse the foreign comments table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('MEERKAT_DATABASE_CONNECTION', $exception->getMessage());
        }

        // Rolling back must never drop a table Meerkat does not own.
        $this->runMigrationDown($migration);

        $this->assertTrue(Schema::connection('meerkat_collision')->hasTable('comments'));
        $this->assertTrue(Schema::connection('meerkat_collision')->hasColumn('comments', 'body'));
        $this->assertSame(1, DB::connection('meerkat_collision')->table('comments')->count());
    }

    #[Test]
    public function up_is_idempotent_when_the_meerkat_table_already_exists(): void
    {
        // The suite environment has already run every Meerkat migration on the
        // prefixed `meerkat` connection; a re-run must be a silent no-op.
        $this->runMigrationUp($this->migration('2026_05_12_000000_create_comments_table.php'));

        $this->assertTrue(Schema::connection('meerkat')->hasTable('comments'));
    }

    #[Test]
    public function down_drops_a_table_meerkat_owns(): void
    {
        $this->useThrowawayConnection('meerkat_owned');

        $migration = $this->migration('2026_05_12_000000_create_comments_table.php');
        $this->runMigrationUp($migration);
        $this->assertTrue(Schema::connection('meerkat_owned')->hasTable('comments'));

        $this->runMigrationDown($migration);
        $this->assertFalse(Schema::connection('meerkat_owned')->hasTable('comments'));
    }

    private function useThrowawayConnection(string $name, string $prefix = ''): void
    {
        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => $prefix,
        ]);
        config()->set('meerkat.database.connection', $name);
        DB::purge($name);
    }

    private function migration(string $file): Migration
    {
        $migration = include $this->addonPath('migrations/'.$file);

        if (! $migration instanceof Migration) {
            throw new LogicException("Migration [{$file}] did not return a migration instance.");
        }

        return $migration;
    }

    private function runMigrationDown(Migration $migration): void
    {
        $down = [$migration, 'down'];

        if (! is_callable($down)) {
            throw new LogicException('Expected a migration with a down method.');
        }

        $down();
    }
}
