<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class FilesystemSyncThreadSoftDeleteTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-trash-');
    }

    #[Test]
    public function legacy_underscore_directories_import_the_real_soft_deleted_thread_and_are_renamed(): void
    {
        $threadId = 'legacy-thread';
        $this->writeComment('_'.$threadId, '1700000001');

        $result = $this->sync();
        $thread = Thread::query()->withTrashed()->where('thread_id', $threadId)->first();

        $this->assertSame(1, $result['stats']['threads_soft_deleted']);
        $this->assertNotNull($thread?->deleted_at);
        $this->assertNull(Thread::query()->withTrashed()->where('thread_id', '_'.$threadId)->first());
        $this->assertNotNull(Comment::query()->where('comments.thread_id', $threadId)->first());
        $this->assertDirectoryExists($this->mirrorRoot.'/'.$threadId);
        $this->assertDirectoryDoesNotExist($this->mirrorRoot.'/_'.$threadId);
    }

    #[Test]
    public function legacy_rename_collisions_are_reported_without_overwriting_either_directory(): void
    {
        $threadId = 'collision-thread';
        $this->writeComment('_'.$threadId, '1700000099');
        $this->writeComment($threadId, '1700000100');

        $result = $this->sync();

        $this->assertDirectoryExists($this->mirrorRoot.'/'.$threadId);
        $this->assertDirectoryExists($this->mirrorRoot.'/_'.$threadId);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('both legacy', $result['errors'][0]['error']);
    }

    #[Test]
    public function legacy_soft_deleted_threads_stay_trashed_on_resync(): void
    {
        $threadId = 'legacy-stays-trashed';
        $this->writeComment('_'.$threadId, '1700000042');

        $this->sync();
        $firstPass = $this->requireValue(Thread::query()->withTrashed()->where('thread_id', $threadId)->first());
        $this->assertNotNull($firstPass->deleted_at);

        $this->sync();

        $secondPass = $this->requireValue(Thread::query()->withTrashed()->where('thread_id', $threadId)->first());
        $this->assertNotNull(
            $secondPass->deleted_at,
            'A second sync must not restore a thread soft-deleted via the legacy prefix.'
        );
        $this->assertFileExists($this->mirrorRoot.'/'.$threadId.'/.meta');
    }

    #[Test]
    public function meta_trashed_state_soft_deletes_and_restores_a_thread_on_resync(): void
    {
        $threadId = 'meta-thread';
        $this->writeComment($threadId, '1700000002');
        $this->writeThreadMeta($threadId, ['trashed' => true, 'created' => 1700000000]);

        $result = $this->sync();
        $this->assertSame(1, $result['stats']['threads_soft_deleted']);
        $this->assertNotNull(Thread::query()->withTrashed()->where('thread_id', $threadId)->first()?->deleted_at);

        $this->writeThreadMeta($threadId, ['trashed' => false, 'created' => 1700000000]);
        $this->sync();
        $this->assertNull(Thread::query()->where('thread_id', $threadId)->first()?->deleted_at);
    }

    #[Test]
    public function unreadable_meta_is_reported_without_aborting_comment_import(): void
    {
        $threadId = 'bad-meta-thread';
        $this->writeComment($threadId, '1700000006');
        File::put($this->mirrorRoot.'/'.$threadId.'/.meta', "this:\n  :is\n  not: valid: yaml: at: all\n");

        $result = $this->sync();

        $this->assertNotNull(Thread::query()->where('thread_id', $threadId)->first());
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('unreadable thread .meta', $result['errors'][0]['error']);
    }

    /** @return array{stats: array<string, int>, errors: list<array{file: string, error: string}>} */
    private function sync(): array
    {
        return (new FilesystemSync($this->mirrorRoot))->run();
    }

    private function writeComment(string $threadDirName, string $timestampId): void
    {
        $dir = $this->mirrorRoot.'/'.$threadDirName.'/'.$timestampId;
        File::ensureDirectoryExists($dir);
        $yaml = Yaml::dump([
            'id' => $timestampId,
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'published' => true,
            'spam' => false,
        ], 2, 2);
        File::put($dir.'/comment.md', "---\n{$yaml}---\nbody\n");
    }

    /** @param array<string, mixed> $meta */
    private function writeThreadMeta(string $threadDirName, array $meta): void
    {
        File::put(
            $this->mirrorRoot.'/'.$threadDirName.'/.meta',
            Yaml::dump($meta, 2, 2),
        );
    }
}
