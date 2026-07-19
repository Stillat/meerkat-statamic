<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Mirror\Mirror;
use Stillat\Meerkat\Services\ThreadMetricsManager;

class Sync extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:sync {--path= : Override the configured mirror path}';

    protected $description = 'Hydrate the comments + threads tables from the filesystem mirror.';

    public function handle(ThreadMetricsManager $metrics): int
    {
        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $path = Mirror::root();
        }

        $this->components->info("Syncing comments from: {$path}");

        $sync = new FilesystemSync($path, $metrics);
        $result = $sync->run();

        $this->components->twoColumnDetail('Threads touched', (string) $result['stats']['threads']);
        $this->components->twoColumnDetail('Threads resolved to entries', (string) ($result['stats']['threads_resolved'] ?? 0));
        $this->components->twoColumnDetail('Threads marked soft-deleted', (string) ($result['stats']['threads_soft_deleted'] ?? 0));
        $this->components->twoColumnDetail('Comments created', (string) $result['stats']['comments_created']);
        $this->components->twoColumnDetail('Comments updated', (string) $result['stats']['comments_updated']);
        $this->components->twoColumnDetail('Users meta backfilled', (string) ($result['stats']['users_meta_created'] ?? 0));
        $this->components->twoColumnDetail('Files skipped', (string) $result['stats']['files_skipped']);

        if (! empty($result['errors'])) {
            $this->newLine();
            $this->components->warn('Errors:');
            foreach ($result['errors'] as $error) {
                $this->line("  {$error['file']}: {$error['error']}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
