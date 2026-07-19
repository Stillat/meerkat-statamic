<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Setup;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class MigrationSchemaTest extends TestCase
{
    #[Test]
    public function fresh_schema_contains_consolidated_comment_columns(): void
    {
        foreach ([
            'replies_count',
            'user_ip',
            'user_agent',
            'referer',
            'moderation_status',
            'moderation_reason',
            'moderation_notes',
            'moderated_at',
            'moderated_by',
            'last_activity_at',
            'published_at',
            'is_removed',
            'removed_at',
            'removed_by',
            'removed_reason',
        ] as $column) {
            $this->assertTrue(
                Schema::connection('meerkat')->hasColumn('comments', $column),
                "Expected comments.{$column} to exist on a fresh install."
            );
        }
    }

    #[Test]
    public function fresh_schema_contains_consolidated_thread_columns(): void
    {
        foreach (['entry_id', 'site', 'collection'] as $column) {
            $this->assertTrue(Schema::connection('meerkat')->hasColumn('threads', $column));
        }
    }

    #[Test]
    public function important_indexes_are_created_by_base_migrations(): void
    {
        $this->assertIndexExists('comments', 'meerkat_comments_thread_publish_parent_idx');
        $this->assertIndexExists('comments', 'meerkat_comments_thread_created_idx');
        $this->assertIndexExists('comments', 'meerkat_comments_author_created_idx');
        $this->assertIndexExists('comments', 'meerkat_comments_site_collection_created_idx');
        $this->assertIndexExists('comments', 'meerkat_comments_thread_moderation_idx');
        $this->assertIndexExists('threads', 'meerkat_threads_thread_id_unique');
        $this->assertIndexExists('users_meta', 'meerkat_users_meta_user_id_unique');
    }

    private function assertIndexExists(string $table, string $index): void
    {
        $indexes = collect(Schema::connection('meerkat')->getIndexes($table))->pluck('name');

        $this->assertTrue($indexes->contains($index), "Expected {$index} to exist on {$table}.");
    }
}
