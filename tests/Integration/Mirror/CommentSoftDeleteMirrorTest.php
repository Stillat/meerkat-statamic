<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Mirror\CommentParser;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class CommentSoftDeleteMirrorTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-comment-trash-');

        config(['meerkat.mirror.enabled' => true, 'meerkat.mirror.path' => $this->mirrorRoot]);
    }

    #[Test]
    public function soft_deleting_a_comment_rewrites_the_file_with_a_trashed_flag(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
            'comment_text' => 'now you see me',
        ]);

        $comment->delete();

        $file = $this->mirrorRoot.'/thread-abc/1779039555/comment.md';
        $this->assertFileExists($file);

        $parsed = CommentParser::parse(File::get($file));
        $this->assertTrue($parsed['frontmatter']['trashed']);
        $this->assertSame(
            $this->requireValue(Comment::query()->withTrashed()->find($comment->id))->deleted_at?->getTimestamp(),
            $parsed['frontmatter']['trashed_at'],
        );
    }

    #[Test]
    public function restoring_a_comment_rewrites_the_file_without_the_trashed_flag(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
        ]);

        $comment->delete();
        $comment->restore();

        $parsed = CommentParser::parse(File::get($this->mirrorRoot.'/thread-abc/1779039555/comment.md'));

        $this->assertArrayNotHasKey('trashed', $parsed['frontmatter']);
        $this->assertArrayNotHasKey('trashed_at', $parsed['frontmatter']);
    }

    #[Test]
    public function replies_of_a_soft_deleted_parent_keep_their_nested_mirror_path(): void
    {
        $parent = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
        ]);
        $reply = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039600',
            'parent_id' => $parent->id,
            'depth' => 1,
        ]);

        $parent->delete();

        $reply->comment_text = 'still nested';
        $reply->save();

        $this->assertFileExists($this->mirrorRoot.'/thread-abc/1779039555/replies/1779039600/comment.md');
        $this->assertFileDoesNotExist($this->mirrorRoot.'/thread-abc/1779039600/comment.md');
    }

    #[Test]
    public function force_deleting_a_comment_still_removes_the_file(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
        ]);

        $comment->forceDelete();

        $this->assertDirectoryDoesNotExist($this->mirrorRoot.'/thread-abc/1779039555');
    }

    #[Test]
    public function sync_imports_a_trashed_comment_as_soft_deleted(): void
    {
        $this->writeComment('sync-thread', '1700000010', [
            'trashed' => true,
            'trashed_at' => 1700000500,
        ]);

        $this->sync();

        $comment = $this->requireValue(
            Comment::query()->withTrashed()
                ->where('comments.thread_id', 'sync-thread')
                ->where('comments.timestamp_id', '1700000010')
                ->first()
        );

        $this->assertSame(1700000500, $comment->deleted_at?->getTimestamp());
        $this->assertArrayNotHasKey('trashed', (array) $comment->comment_data);
        $this->assertArrayNotHasKey('trashed_at', (array) $comment->comment_data);
    }

    #[Test]
    public function sync_falls_back_to_the_comment_timestamp_when_trashed_at_is_missing(): void
    {
        $this->writeComment('sync-thread', '1700000010', ['trashed' => true]);

        $this->sync();

        $comment = $this->requireValue(
            Comment::query()->withTrashed()
                ->where('comments.thread_id', 'sync-thread')
                ->where('comments.timestamp_id', '1700000010')
                ->first()
        );

        $this->assertSame(1700000010, $comment->deleted_at?->getTimestamp());
    }

    #[Test]
    public function sync_restores_a_comment_when_the_file_has_no_trashed_flag(): void
    {
        $this->writeComment('sync-thread', '1700000010');

        $this->sync();

        $comment = $this->requireValue(
            Comment::query()
                ->where('comments.thread_id', 'sync-thread')
                ->where('comments.timestamp_id', '1700000010')
                ->first()
        );
        $comment->delete();

        $this->writeComment('sync-thread', '1700000010');
        $this->sync();

        $this->assertNull(
            $this->requireValue(Comment::query()->withTrashed()->find($comment->id))->deleted_at
        );
    }

    #[Test]
    public function a_soft_deleted_comment_survives_a_round_trip_through_sync(): void
    {
        $comment = $this->createComment([
            'thread_id' => 'thread-abc',
            'timestamp_id' => '1779039555',
        ]);

        $comment->delete();
        $deletedAt = $this->requireValue(
            Comment::query()->withTrashed()->find($comment->id)
        )->deleted_at?->getTimestamp();

        $this->sync();

        $fresh = $this->requireValue(Comment::query()->withTrashed()->find($comment->id));

        $this->assertSame($deletedAt, $fresh->deleted_at?->getTimestamp());
    }

    /** @return array{stats: array<string, int>, errors: list<array{file: string, error: string}>} */
    private function sync(): array
    {
        return (new FilesystemSync($this->mirrorRoot))->run();
    }

    /** @param array<string, mixed> $extraFrontmatter */
    private function writeComment(string $threadDirName, string $timestampId, array $extraFrontmatter = []): void
    {
        $dir = $this->mirrorRoot.'/'.$threadDirName.'/'.$timestampId;
        File::ensureDirectoryExists($dir);
        $yaml = Yaml::dump(array_merge([
            'id' => $timestampId,
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'published' => true,
            'spam' => false,
        ], $extraFrontmatter), 2, 2);
        File::put($dir.'/comment.md', "---\n{$yaml}---\nbody\n");
    }
}
