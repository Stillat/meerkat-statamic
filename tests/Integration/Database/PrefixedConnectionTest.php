<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PrefixedConnectionTest extends TestCase
{
    #[Test]
    public function the_full_stack_works_on_a_connection_with_a_table_prefix(): void
    {
        config()->set('database.connections.mk', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'mk_',
        ]);
        config()->set('meerkat.database.connection', 'mk');
        DB::purge('mk');

        foreach ($this->migrationFiles() as $file) {
            $this->runMigrationUp($this->migration($file));
        }

        $tables = [];

        foreach (DB::connection('mk')->select("select name from sqlite_master where type = 'table'") as $row) {
            $name = is_object($row) ? (get_object_vars($row)['name'] ?? null) : null;

            if (is_string($name)) {
                $tables[] = $name;
            }
        }

        foreach ([
            'mk_comments', 'mk_threads', 'mk_users_meta',
            'mk_comment_revisions', 'mk_comment_moderation_audits', 'mk_thread_metrics',
        ] as $table) {
            $this->assertContains($table, $tables);
        }

        $comment = CommentFactory::new()
            ->threadId('prefix-thread')
            ->author('Prefix Author', 'prefix@example.com')
            ->published()
            ->create();

        $this->assertSame('mk', $comment->getConnectionName());

        $found = Comment::query()->where('comments.thread_id', 'prefix-thread')->firstOrFail();
        $this->assertSame('Prefix Author', $found->getAttribute('name'));
        $this->assertSame('prefix@example.com', $found->getAttribute('email'));

        $metric = app(ThreadMetricsManager::class)->recalculateThread('prefix-thread');
        $this->assertSame(1, $metric->total_comments);
        $this->assertSame(1, $metric->published_comments);
        $this->assertSame(1, $metric->participants);
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        return [
            '2026_05_12_000000_create_comments_table.php',
            '2026_05_12_000100_create_threads_table.php',
            '2026_05_12_000200_create_users_meta_table.php',
            '2026_05_13_010000_create_comment_revisions_table.php',
            '2026_06_10_000200_create_comment_moderation_audits_table.php',
            '2026_06_10_000300_create_thread_metrics_table.php',
        ];
    }

    private function migration(string $file): Migration
    {
        $migration = include $this->addonPath('migrations/'.$file);

        if (! $migration instanceof Migration) {
            throw new LogicException("Migration [{$file}] did not return a migration instance.");
        }

        return $migration;
    }
}
