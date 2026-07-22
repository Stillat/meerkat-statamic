<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

abstract class TablePrefixTestCase extends TestCase
{
    protected const BASE_TABLES = [
        'comments',
        'threads',
        'users_meta',
        'comment_revisions',
        'comment_moderation_audits',
        'thread_metrics',
    ];

    abstract protected function tablePrefix(): string;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.meerkat', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => $this->tablePrefix(),
            'foreign_key_constraints' => true,
        ]);
    }

    #[Test]
    public function migrations_create_every_table_behind_the_configured_prefix(): void
    {
        $names = collect(DB::connection('meerkat')->select(
            "select name from sqlite_master where type = 'table'"
        ))->pluck('name')->all();

        foreach (self::BASE_TABLES as $base) {
            $expected = $this->tablePrefix().$base;

            $this->assertContains(
                $expected,
                $names,
                "Expected migrations to create the prefixed table [{$expected}]."
            );

            if ($this->tablePrefix() !== '') {
                $this->assertNotContains(
                    $base,
                    $names,
                    "Unprefixed table [{$base}] should not exist when a prefix is configured."
                );
            }
        }
    }

    #[Test]
    public function schema_lookups_resolve_through_the_prefix(): void
    {
        $schema = Schema::connection('meerkat');

        foreach (self::BASE_TABLES as $base) {
            $this->assertTrue(
                $schema->hasTable($base),
                "Schema::hasTable should resolve [{$base}] through the connection prefix."
            );
        }
    }

    #[Test]
    public function re_running_a_migration_is_a_no_op_when_the_prefixed_table_exists(): void
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

    #[Test]
    public function author_aggregates_run_against_prefixed_tables(): void
    {
        CommentFactory::new()->count(3, [
            'thread_id' => 'prefix-thread',
            'author_name' => 'John Doe',
            'author_email' => 'john@example.com',
        ]);

        CommentFactory::new()->count(2, [
            'thread_id' => 'prefix-thread',
            'author_name' => 'Jane Smith',
            'author_email' => 'jane@example.com',
        ]);

        $counts = Comments::query()->forThread('prefix-thread')->countByAuthor();

        $this->assertSame(3, $counts['John Doe']);
        $this->assertSame(2, $counts['Jane Smith']);
    }

    #[Test]
    public function participant_counts_aggregate_across_prefixed_tables(): void
    {
        CommentFactory::new()->create(['thread_id' => 'prefix-thread', 'author_email' => 'a@example.com']);
        CommentFactory::new()->create(['thread_id' => 'prefix-thread', 'author_email' => 'b@example.com']);
        CommentFactory::new()->create(['thread_id' => 'prefix-thread', 'author_email' => 'a@example.com']);

        $active = Comments::query()->mostActiveThreads(1);

        $this->assertSame('prefix-thread', $active[0]['thread_id']);
        $this->assertSame(3, $active[0]['comment_count']);
        $this->assertSame(2, $active[0]['participant_count']);
    }

    #[Test]
    public function contributor_aggregates_join_prefixed_users_meta(): void
    {
        CommentFactory::new()->create([
            'thread_id' => 'thread-a',
            'author_name' => 'John Doe',
            'author_email' => 'john@example.com',
        ]);

        CommentFactory::new()->create([
            'thread_id' => 'thread-b',
            'author_name' => 'John Doe',
            'author_email' => 'john@example.com',
        ]);

        $contributors = Comments::query()->topContributors(1);

        $this->assertSame('John Doe', $contributors[0]['name']);
        $this->assertSame(2, $contributors[0]['comment_count']);
        $this->assertSame(2, $contributors[0]['threads_participated']);
    }

    #[Test]
    public function author_details_scope_resolves_names_from_prefixed_users_meta(): void
    {
        UserMeta::create([
            'user_id' => 'user-1',
            'name' => 'Registered User',
            'email' => 'registered@example.com',
        ]);

        $comment = CommentFactory::new()->create([
            'thread_id' => 'prefix-thread',
            'author_id' => 'user-1',
            'author_name' => null,
            'author_email' => null,
        ]);

        $fetched = $this->requireValue(Comment::query()->whereKey($comment->id)->first());

        $this->assertSame('Registered User', $fetched->name);
        $this->assertSame('registered@example.com', $fetched->email);
    }
}
