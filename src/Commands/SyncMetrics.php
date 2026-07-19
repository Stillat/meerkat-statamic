<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Services\ThreadMetricsManager;

use function Laravel\Prompts\progress;

class SyncMetrics extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:sync-metrics {--chunk=100 : Number of threads to process per batch}';

    protected $description = 'Rebuild thread_metrics rows from the live comments table.';

    public function handle(ThreadMetricsManager $metrics): int
    {
        $this->components->info('Rebuilding thread metrics...');

        $connection = (new ThreadMetric)->getConnectionName();

        $threadIds = DB::connection($connection)
            ->table('comments')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('thread_id');

        if ($threadIds->isEmpty()) {
            $this->components->warn('No threads with live comments found.');

            return self::SUCCESS;
        }

        $chunkOption = $this->option('chunk');
        $chunkSize = max(1, is_string($chunkOption) && is_numeric($chunkOption) ? (int) $chunkOption : 100);
        $chunks = $threadIds
            ->filter(fn (mixed $threadId): bool => is_string($threadId))
            ->chunk($chunkSize)
            ->all();

        progress(
            label: 'Recalculating thread metrics',
            steps: $chunks,
            callback: function (iterable $chunk) use ($metrics) {
                foreach ($chunk as $threadId) {
                    $metrics->recalculateThread($threadId);
                }
            }
        );

        $this->newLine();
        $this->components->info("Refreshed {$threadIds->count()} threads.");

        return self::SUCCESS;
    }
}
