<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Mirror;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class FilesystemSyncDryRunTest extends TestCase
{
    private string $mirrorRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mirrorRoot = $this->temporaryDirectory('meerkat-mirror-dry-run-');
    }

    #[Test]
    public function the_command_dry_run_reports_stats_without_writing_rows_or_renaming_directories(): void
    {
        $this->writeComment('thread-a/1700000100', ['id' => '1700000100', 'published' => true]);
        $this->writeComment('_thread-b/1700000200', ['id' => '1700000200', 'published' => true]);

        $this->pendingArtisan('meerkat:sync', ['--path' => $this->mirrorRoot, '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Comments created')
            ->assertExitCode(0);

        $this->assertSame(0, Comment::query()->withTrashed()->count());
        $this->assertSame(0, Thread::query()->withTrashed()->count());

        // The legacy `_thread-b` directory must be left exactly as it was.
        $this->assertDirectoryExists($this->mirrorRoot.'/_thread-b');
        $this->assertDirectoryDoesNotExist($this->mirrorRoot.'/thread-b');
    }

    #[Test]
    public function the_dry_run_flag_skips_the_legacy_rename_but_still_imports_under_the_real_id(): void
    {
        $this->writeComment('_thread-c/1700000300', ['id' => '1700000300', 'published' => true]);

        $result = (new FilesystemSync($this->mirrorRoot, dryRun: true))->run();

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['stats']['comments_created']);
        $this->assertSame(1, $result['stats']['legacy_dirs_would_rename']);
        $this->assertDirectoryExists($this->mirrorRoot.'/_thread-c');

        $thread = Thread::query()->withTrashed()->where('thread_id', 'thread-c')->firstOrFail();
        $this->assertNotNull($thread->deleted_at);
        $this->assertSame(1, Comment::query()->withTrashed()->where('thread_id', 'thread-c')->count());
    }

    #[Test]
    public function the_dry_run_does_not_recalculate_metrics(): void
    {
        $this->writeComment('thread-d/1700000400', ['id' => '1700000400', 'published' => true]);

        $metrics = new class extends ThreadMetricsManager
        {
            public int $calls = 0;

            public function recalculateThread(string $threadId): ThreadMetric
            {
                $this->calls++;

                return new ThreadMetric;
            }
        };

        (new FilesystemSync($this->mirrorRoot, $metrics, dryRun: true))->run();

        $this->assertSame(0, $metrics->calls);
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
