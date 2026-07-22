<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class NoTablePrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.meerkat', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    #[Test]
    public function migrations_create_the_complete_unprefixed_schema(): void
    {
        $names = collect(DB::connection('meerkat')->select("select name from sqlite_master where type = 'table'"))->pluck('name')->all();

        foreach (['comments', 'threads', 'users_meta', 'comment_revisions', 'comment_moderation_audits', 'thread_metrics'] as $table) {
            $this->assertContains($table, $names);
            $this->assertTrue(Schema::connection('meerkat')->hasTable($table));
        }
    }

    #[Test]
    public function re_running_a_base_migration_is_a_no_op(): void
    {
        $migration = include $this->addonPath('migrations/2026_05_12_000000_create_comments_table.php');
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('meerkat');
        try {
            $this->runMigrationUp($migration);
        } finally {
            DB::setDefaultConnection($original);
        }

        $this->assertTrue(Schema::connection('meerkat')->hasTable('comments'));
    }
}
