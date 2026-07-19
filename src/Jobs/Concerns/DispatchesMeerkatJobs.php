<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Jobs\Concerns;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

trait DispatchesMeerkatJobs
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static function dispatchMeerkatJob(int $id): void
    {
        $connectionValue = config('meerkat.jobs.connection');
        $connection = is_string($connectionValue) && $connectionValue !== '' ? $connectionValue : null;
        $queueValue = config('meerkat.jobs.queue');
        $queue = is_string($queueValue) && $queueValue !== '' ? $queueValue : 'default';

        if (! $connection) {
            self::dispatchSync($id);

            return;
        }

        self::dispatch($id)
            ->onConnection($connection)
            ->onQueue($queue);
    }
}
