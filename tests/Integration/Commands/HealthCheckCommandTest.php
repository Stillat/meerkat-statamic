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
            ->expectsOutputToContain('Meerkat health check failed.')
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
            ->expectsOutputToContain('Meerkat health check failed.')
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
            ->expectsOutputToContain('Meerkat health check failed.')
            ->assertExitCode(1);
    }
}
