<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Mirror\FilesystemSync;
use Stillat\Meerkat\Mirror\Mirror;
use Stillat\Meerkat\Services\ThreadMetricsManager;

class Sync extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:sync
        {--path= : Override the configured mirror path}
        {--dry-run : Preview the sync without writing to the database or renaming directories}';

    protected $description = 'Hydrate the comments + threads tables from the filesystem mirror.';

    public function handle(ThreadMetricsManager $metrics): int
    {
        $connection = (new Comment)->getConnectionName();

        if (! Schema::connection($connection)->hasTable('comments')) {
            $this->components->error("Meerkat's tables do not exist yet. Run `php artisan meerkat:install` first.");

            return self::FAILURE;
        }

        $path = $this->option('path');

        if (! is_string($path) || $path === '') {
            $path = Mirror::root();
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info("Syncing comments from: {$path}");

        $sync = new FilesystemSync($path, $metrics, dryRun: $dryRun);

        if ($dryRun) {
            $connection = DB::connection((new Comment)->getConnectionName());

            $connection->beginTransaction();

            try {
                $result = $sync->run();
            } finally {
                $connection->rollBack();
            }
        } else {
            $result = $sync->run();
        }

        $this->components->twoColumnDetail('Threads touched', (string) $result['stats']['threads']);
        $this->components->twoColumnDetail('Threads resolved to entries', (string) ($result['stats']['threads_resolved'] ?? 0));
        $this->components->twoColumnDetail('Threads marked soft-deleted', (string) ($result['stats']['threads_soft_deleted'] ?? 0));
        $this->components->twoColumnDetail('Comments created', (string) $result['stats']['comments_created']);
        $this->components->twoColumnDetail('Comments updated', (string) $result['stats']['comments_updated']);
        $this->components->twoColumnDetail('Comment ids corrected', (string) ($result['stats']['comment_ids_corrected'] ?? 0));
        $this->components->twoColumnDetail('Users meta backfilled', (string) ($result['stats']['users_meta_created'] ?? 0));
        $this->components->twoColumnDetail('Files skipped', (string) $result['stats']['files_skipped']);

        if ($dryRun) {
            $this->components->twoColumnDetail('Legacy directories pending rename', (string) ($result['stats']['legacy_dirs_would_rename'] ?? 0));
            $this->newLine();
            $this->components->warn('DRY RUN: no changes were made.');
        }

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
