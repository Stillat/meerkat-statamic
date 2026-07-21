<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Mirror\CommentParser;
use Stillat\Meerkat\Mirror\CommentSerializer;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;

class FilesystemSyncFidelityTest extends TestCase
{
    #[Test]
    public function imported_comment_preserves_body_state_timestamps_and_template_data_shape(): void
    {
        $source = (string) file_get_contents($this->fixtureRoot().'/2ed01b5f-355d-4a22-a3d5-d964cde4209f/1779039555/comment.md');
        $parsed = CommentParser::parse($source);
        (new FilesystemSync($this->fixtureRoot()))->run();
        $comment = Comment::query()->where('timestamp_id', '1779039555')->firstOrFail();
        $timestamp = Carbon::createFromTimestamp(1779039555);

        $this->assertSame($parsed['body'], $comment->comment_text);
        $this->assertFalse($comment->is_spam);
        $this->assertFalse($comment->is_ham);
        $this->assertFalse($comment->checked_for_spam);
        $this->assertEquals($timestamp, $comment->created_at);
        $this->assertEquals($timestamp, $comment->published_at);
        $this->assertEquals($timestamp, $comment->updated_at);
        $this->assertSame($comment->comment_text, $comment->comment_data['comment']);
    }

    #[Test]
    public function compatibility_fields_map_to_columns_without_duplicating_into_template_data(): void
    {
        (new FilesystemSync($this->fixtureRoot()))->run();
        $comment = Comment::query()->where('timestamp_id', '1779039555')->firstOrFail();
        $data = (array) $comment->comment_data;

        $this->assertSame('approved', $comment->moderation_status);
        $this->assertSame('http://meerkatmigrator.test/would-you-rather', $data['page_url']);

        $keys = [
            'ham', 'is_ham', 'checked_for_spam', 'moderation_status', 'moderation_reason',
            'moderation_notes', 'moderated_by', 'moderated_at', 'trashed', 'trashed_at',
        ];

        foreach ($keys as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }

    #[Test]
    public function sync_materializes_reply_counts_from_the_imported_hierarchy(): void
    {
        (new FilesystemSync($this->fixtureRoot()))->run();
        foreach (['1779039555', '1779039592', '1779039614', '1779039674'] as $timestamp) {
            $this->assertSame(1, Comment::query()->where('timestamp_id', $timestamp)->value('replies_count'));
        }
        $this->assertSame(0, Comment::query()->where('timestamp_id', '1779039692')->value('replies_count'));
    }

    #[Test]
    public function thread_created_at_uses_the_meta_created_timestamp(): void
    {
        (new FilesystemSync($this->fixtureRoot()))->run();

        $this->assertEquals(
            Carbon::createFromTimestamp(1779644895),
            Thread::query()->where('thread_id', '2ed01b5f-355d-4a22-a3d5-d964cde4209f')->value('created_at'),
        );
    }

    #[Test]
    public function simple_import_reserializes_byte_for_byte(): void
    {
        (new FilesystemSync($this->fixtureRoot()))->run();
        $comment = Comment::query()->where('timestamp_id', '1779039555')->firstOrFail();
        $original = (string) file_get_contents($this->fixtureRoot().'/2ed01b5f-355d-4a22-a3d5-d964cde4209f/1779039555/comment.md');

        $this->assertSame($original, CommentSerializer::toString($comment));
    }
}
