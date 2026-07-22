<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    #[Test]
    public function it_passes_when_blueprint_and_tables_are_present(): void
    {
        $this->pendingArtisan('meerkat:health')
            ->expectsOutputToContain('Meerkat is healthy.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_fails_when_a_required_table_is_missing(): void
    {
        DB::connection('meerkat')->statement('DROP TABLE meerkat_comments');

        $this->pendingArtisan('meerkat:health')
            ->expectsOutputToContain('Meerkat is not fully installed.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_when_a_required_column_is_missing(): void
    {
        Schema::connection('meerkat')->table('comments', function (Blueprint $table): void {
            $table->dropIndex(['is_removed']);
            $table->dropColumn('is_removed');
        });

        $this->pendingArtisan('meerkat:health')
            ->expectsOutputToContain('comments.is_removed')
            ->expectsOutputToContain('Meerkat is not fully installed.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_when_a_required_index_is_missing(): void
    {
        Schema::connection('meerkat')->table(
            'comments',
            fn (Blueprint $table) => $table->dropUnique('meerkat_comments_thread_timestamp_unique'),
        );

        $this->pendingArtisan('meerkat:health')
            ->expectsOutputToContain('comments.meerkat_comments_thread_timestamp_unique')
            ->expectsOutputToContain('Meerkat is not fully installed.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_reports_a_bad_mirror_path_as_a_configuration_failure(): void
    {
        $file = $this->temporaryFilePath('meerkat-mirror-file-');
        file_put_contents($file, 'not a directory');

        config()->set('meerkat.mirror.enabled', true);
        config()->set('meerkat.mirror.path', $file);

        $this->pendingArtisan('meerkat:health')
            ->expectsOutputToContain('exists but is not a directory')
            ->expectsOutputToContain('some configuration checks failed')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_reports_an_undefined_queue_connection(): void
    {
        config()->set('meerkat.jobs.connection', 'does-not-exist');

        $this->pendingArtisan('meerkat:health')
            ->expectsOutputToContain('is not defined in config/queue.php')
            ->assertExitCode(1);
    }
}
