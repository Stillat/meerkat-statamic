<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Commands;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class SyncCommandTest extends TestCase
{
    #[Test]
    public function it_tells_the_user_to_install_before_syncing_when_tables_are_missing(): void
    {
        DB::connection('meerkat')->statement('DROP TABLE meerkat_comments');

        $this->pendingArtisan('meerkat:sync')
            ->expectsOutputToContain('meerkat:install')
            ->assertExitCode(1);
    }
}
