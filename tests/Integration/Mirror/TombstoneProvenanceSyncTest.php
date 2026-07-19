<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Mirror\CommentSerializer;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;

class TombstoneProvenanceSyncTest extends TestCase
{
    #[Test]
    public function tombstone_provenance_round_trips_through_the_mirror(): void
    {
        $comment = new Comment;
        $comment->forceFill([
            'thread_id' => 'tomb-thread',
            'timestamp_id' => '1700000123',
            'author_name' => 'Mod Target',
            'author_email' => 'target@example.com',
            'comment_text' => 'this will be removed',
            'comment_data' => ['comment' => 'this will be removed'],
            'is_published' => true,
            'is_removed' => true,
            'removed_at' => Carbon::createFromTimestamp(1700009999),
            'removed_by' => 'admin-user-7',
            'removed_reason' => 'Off-topic and abusive',
            'created_at' => Carbon::createFromTimestamp(1700000123),
        ]);

        $serialized = CommentSerializer::toString($comment);

        $this->assertStringContainsString("removed_by: 'admin-user-7'", $serialized);
        $this->assertStringContainsString("removed_reason: 'Off-topic and abusive'", $serialized);
        $this->assertStringContainsString('removed_at: 1700009999', $serialized);

        $root = $this->temporaryDirectory('meerkat-tomb-');
        $dir = $root.'/tomb-thread/1700000123';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/comment.md', $serialized);

        (new FilesystemSync($root))->run();

        $rebuilt = Comment::query()->where('comments.timestamp_id', '1700000123')->first();

        $this->assertNotNull($rebuilt);
        $this->assertTrue((bool) $rebuilt->is_removed);
        $this->assertSame('admin-user-7', $rebuilt->removed_by);
        $this->assertSame('Off-topic and abusive', $rebuilt->removed_reason);
        $this->assertEquals(Carbon::createFromTimestamp(1700009999), $rebuilt->removed_at);

        $this->assertArrayNotHasKey('removed_by', $rebuilt->comment_data);
        $this->assertArrayNotHasKey('removed_reason', $rebuilt->comment_data);
    }
}
