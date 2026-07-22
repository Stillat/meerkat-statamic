<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class LegacyV3CompatSyncTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-v3-compat-');
    }

    #[Test]
    public function v2_era_comment_front_matter_key_imports_as_the_body(): void
    {
        $this->writeComment('legacy-thread/1700000010', [
            'id' => '1700000010',
            'comment' => 'Body stored in the legacy comment key.',
            'published' => true,
        ], body: '');

        $this->sync();

        $comment = $this->findComment('legacy-thread', '1700000010');
        $this->assertSame('Body stored in the legacy comment key.', $comment->comment_text);
    }

    #[Test]
    public function v3_migrated_content_front_matter_key_imports_as_the_body(): void
    {
        $this->writeComment('legacy-thread/1700000011', [
            'id' => '1700000011',
            'content' => 'Body stored in the migrated content key.',
            'published' => true,
        ], body: '');

        $this->sync();

        $comment = $this->findComment('legacy-thread', '1700000011');
        $this->assertSame('Body stored in the migrated content key.', $comment->comment_text);
        $this->assertArrayNotHasKey('content', (array) $comment->comment_data);
    }

    #[Test]
    public function a_markdown_body_wins_over_a_leftover_content_key(): void
    {
        $this->writeComment('legacy-thread/1700000012', [
            'id' => '1700000012',
            'content' => 'stale value',
            'published' => true,
        ], body: 'The real body.');

        $this->sync();

        $comment = $this->findComment('legacy-thread', '1700000012');
        $this->assertSame('The real body.', $comment->comment_text);
        $this->assertSame('stale value', $comment->comment_data['content']);
    }

    #[Test]
    public function the_directory_name_wins_over_a_mismatched_id_header(): void
    {
        $this->writeComment('legacy-thread/1700000020', [
            'id' => '0000000000',
            'published' => true,
        ]);

        $this->sync();

        $comment = $this->findComment('legacy-thread', '1700000020');
        $this->assertSame('1700000020', $comment->timestamp_id);
        $this->assertSame(1700000020, $comment->created_at?->getTimestamp());
    }

    #[Test]
    public function a_missing_published_key_imports_as_unpublished(): void
    {
        $this->writeComment('legacy-thread/1700000030', ['id' => '1700000030']);

        $this->sync();

        $this->assertFalse($this->findComment('legacy-thread', '1700000030')->is_published);
    }

    #[Test]
    public function the_presence_of_the_spam_key_marks_the_comment_as_checked(): void
    {
        $this->writeComment('legacy-thread/1700000040', [
            'id' => '1700000040', 'published' => true, 'spam' => false,
        ]);
        $this->writeComment('legacy-thread/1700000041', [
            'id' => '1700000041', 'published' => true,
        ]);
        $this->writeComment('legacy-thread/1700000042', [
            'id' => '1700000042', 'published' => true, 'spam' => false, 'checked_for_spam' => false,
        ]);

        $this->sync();

        $this->assertTrue($this->findComment('legacy-thread', '1700000040')->checked_for_spam);
        $this->assertFalse($this->findComment('legacy-thread', '1700000041')->checked_for_spam);
        $this->assertFalse($this->findComment('legacy-thread', '1700000042')->checked_for_spam);
    }

    #[Test]
    public function same_second_comments_under_different_parents_both_import(): void
    {
        $this->writeComment('collision-thread/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'root A');
        $this->writeComment('collision-thread/1700000200', [
            'id' => '1700000200', 'published' => true,
        ], body: 'root B');
        $this->writeComment('collision-thread/1700000200/replies/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'reply under B, same second as root A');

        $result = $this->sync();

        $this->assertSame(3, Comment::query()->where('thread_id', 'collision-thread')->count());
        $this->assertSame(1, $result['stats']['comment_ids_corrected']);
        $this->assertSame('root A', $this->findComment('collision-thread', '1700000100')->comment_text);

        $bumped = $this->findComment('collision-thread', '1700000101');
        $this->assertSame('reply under B, same second as root A', $bumped->comment_text);
        $this->assertSame(1700000100, $bumped->created_at?->getTimestamp());
        $this->assertNotNull($bumped->parent_id);

        $this->sync();
        $this->assertSame(3, Comment::query()->where('thread_id', 'collision-thread')->count());
    }

    #[Test]
    public function a_bump_skips_ids_that_exist_as_directories_on_disk(): void
    {
        $this->writeComment('collision-thread/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'root A');
        $this->writeComment('collision-thread/1700000101', [
            'id' => '1700000101', 'published' => true,
        ], body: 'root C occupies the next second');
        $this->writeComment('collision-thread/1700000200', [
            'id' => '1700000200', 'published' => true,
        ], body: 'root B');
        $this->writeComment('collision-thread/1700000200/replies/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'colliding reply');

        $this->sync();

        $this->assertSame(4, Comment::query()->where('thread_id', 'collision-thread')->count());
        $this->assertSame('root C occupies the next second', $this->findComment('collision-thread', '1700000101')->comment_text);
        $this->assertSame('colliding reply', $this->findComment('collision-thread', '1700000102')->comment_text);

        $this->sync();
        $this->assertSame(4, Comment::query()->where('thread_id', 'collision-thread')->count());
    }

    #[Test]
    public function sync_recompute_excludes_tombstoned_replies_from_parent_counts(): void
    {
        $this->writeComment('tomb-count-thread/1700000300', [
            'id' => '1700000300', 'published' => true,
        ], body: 'root');
        $this->writeComment('tomb-count-thread/1700000300/replies/1700000301', [
            'id' => '1700000301', 'published' => true, 'is_deleted' => true,
        ], body: 'tombstoned reply');
        $this->writeComment('tomb-count-thread/1700000300/replies/1700000302', [
            'id' => '1700000302', 'published' => true,
        ], body: 'live reply');

        $this->sync();

        $this->assertSame(1, $this->findComment('tomb-count-thread', '1700000300')->replies_count);
    }

    #[Test]
    public function a_file_without_the_is_deleted_key_clears_a_tombstone_on_resync(): void
    {
        $this->writeComment('untomb-thread/1700000310', [
            'id' => '1700000310', 'published' => true, 'is_deleted' => true,
            'removed_by' => 'admin', 'removed_reason' => 'oops',
        ]);
        $this->sync();
        $this->assertTrue($this->findComment('untomb-thread', '1700000310')->is_removed);

        $this->writeComment('untomb-thread/1700000310', [
            'id' => '1700000310', 'published' => true,
        ]);
        $this->sync();

        $restored = $this->findComment('untomb-thread', '1700000310');
        $this->assertFalse($restored->is_removed);
        $this->assertNull($restored->removed_by);
        $this->assertNull($restored->removed_reason);
        $this->assertNull($restored->removed_at);
    }

    #[Test]
    public function a_rewritten_corrected_directory_does_not_duplicate_on_resync(): void
    {
        $this->writeComment('collision-thread/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'root A');
        $this->writeComment('collision-thread/1700000200', [
            'id' => '1700000200', 'published' => true,
        ], body: 'root B');
        $this->writeComment('collision-thread/1700000200/replies/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'colliding reply');

        $this->sync();
        $this->assertSame(3, Comment::query()->where('thread_id', 'collision-thread')->count());

        $this->writeComment('collision-thread/1700000200/replies/1700000101', [
            'id' => '1700000101', 'published' => true,
        ], body: 'colliding reply (rewritten)');

        $this->sync();

        $this->assertSame(3, Comment::query()->where('thread_id', 'collision-thread')->count());
        $this->assertSame(
            'colliding reply (rewritten)',
            $this->findComment('collision-thread', '1700000101')->comment_text,
        );
    }

    #[Test]
    public function corrected_directories_are_renamed_and_file_edits_survive_resync(): void
    {
        $this->writeComment('collision-thread/1700000100', [
            'id' => '1700000100', 'published' => true,
        ], body: 'root A');
        $this->writeComment('collision-thread/1700000100/replies/1700000101', [
            'id' => '1700000101', 'published' => true,
        ], body: 'reply under A');
        $this->writeComment('collision-thread/1700000101', [
            'id' => '1700000101', 'published' => true,
        ], body: 'root B');
        $this->writeComment('collision-thread/1700000102', [
            'id' => '1700000102', 'published' => true,
        ], body: 'root C');

        $this->sync();

        $this->assertDirectoryExists($this->mirrorRoot.'/collision-thread/1700000103');
        $this->assertDirectoryDoesNotExist($this->mirrorRoot.'/collision-thread/1700000101');
        $this->assertSame('root B', $this->findComment('collision-thread', '1700000103')->comment_text);
        $this->assertSame('root C', $this->findComment('collision-thread', '1700000102')->comment_text);

        $this->writeComment('collision-thread/1700000103', [
            'id' => '1700000103', 'published' => true,
        ], body: 'root B (edited)');

        $this->sync();

        $this->assertSame(4, Comment::query()->where('thread_id', 'collision-thread')->count());
        $this->assertSame('root B (edited)', $this->findComment('collision-thread', '1700000103')->comment_text);
        $this->assertSame('root C', $this->findComment('collision-thread', '1700000102')->comment_text);
    }

    #[Test]
    public function a_single_unimportable_file_is_skipped_without_aborting_the_run(): void
    {
        $this->writeComment('resilient-thread/1700000010', [
            'id' => '1700000010', 'published' => true,
        ], body: 'good comment one');

        // A raw invalid UTF-8 byte in a custom field survives YAML parsing but
        // fails JSON encoding when comment_data is persisted.
        $dir = $this->mirrorRoot.'/resilient-thread/1700000020';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/comment.md', "---\nid: '1700000020'\npublished: true\nbio: bad\xFFbyte\n---\nbad comment\n");

        $this->writeComment('resilient-thread/1700000030', [
            'id' => '1700000030', 'published' => true,
        ], body: 'good comment two');

        $result = $this->sync();

        $this->assertNotEmpty($result['errors']);
        $this->assertSame('good comment one', $this->findComment('resilient-thread', '1700000010')->comment_text);
        $this->assertSame('good comment two', $this->findComment('resilient-thread', '1700000030')->comment_text);
    }

    /** @return array{stats: array<string, int>, errors: list<array{file: string, error: string}>} */
    private function sync(): array
    {
        return (new FilesystemSync($this->mirrorRoot))->run();
    }

    private function findComment(string $threadId, string $timestampId): Comment
    {
        return Comment::query()->withTrashed()
            ->where('comments.thread_id', $threadId)
            ->where('comments.timestamp_id', $timestampId)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $frontmatter */
    private function writeComment(string $relativeDir, array $frontmatter, string $body = 'body'): void
    {
        $dir = $this->mirrorRoot.'/'.$relativeDir;
        File::ensureDirectoryExists($dir);
        $yaml = Yaml::dump($frontmatter, 2, 2);
        File::put($dir.'/comment.md', "---\n{$yaml}---\n{$body}");
    }
}
