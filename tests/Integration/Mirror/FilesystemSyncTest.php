<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;

class FilesystemSyncTest extends TestCase
{
    #[Test]
    public function bundled_v3_fixture_hydrates_counts_hierarchy_flags_paths_and_round_trip_data(): void
    {
        $result = (new FilesystemSync($this->fixtureRoot()))->run();
        $this->assertSame(5, $result['stats']['threads']);
        $this->assertSame(40, $result['stats']['comments_created']);
        $this->assertEmpty($result['errors']);
        $this->assertSame(5, Thread::query()->count());
        $this->assertSame(40, Comment::query()->count());

        $root = Comment::query()->where('timestamp_id', '1779039555')->firstOrFail();
        $deepest = Comment::query()->where('timestamp_id', '1779039692')->firstOrFail();
        $spam = Comment::query()->where('timestamp_id', '1778434851')->firstOrFail();
        $this->assertSame(0, $root->depth);
        $this->assertNull($root->parent_id);
        $this->assertSame(4, $deepest->depth);
        $this->assertFalse($spam->is_published);
        $this->assertTrue($spam->is_spam);
        $this->assertSame('http://meerkatmigrator.test/would-you-rather', $root->comment_data['page_url']);
        $this->assertSame((string) $root->id, $root->path);
        $child = Comment::query()->where('timestamp_id', '1779039592')->firstOrFail();
        $this->assertSame($root->path.'.'.$child->id, $child->path);
    }

    #[Test]
    public function second_sync_updates_in_place_without_duplicates(): void
    {
        (new FilesystemSync($this->fixtureRoot()))->run();
        $result = (new FilesystemSync($this->fixtureRoot()))->run();

        $this->assertSame(40, Comment::query()->count());
        $this->assertSame(0, $result['stats']['comments_created']);
        $this->assertSame(40, $result['stats']['comments_updated']);
    }

    #[Test]
    public function missing_root_is_reported_as_a_sync_error(): void
    {
        $result = (new FilesystemSync('/nonexistent/path-meerkat-test'))->run();

        $this->assertSame('Mirror root does not exist', $result['errors'][0]['error']);
    }
}
