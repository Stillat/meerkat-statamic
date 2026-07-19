<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Support\Installer;

class HealthCheck extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:health';

    protected $description = 'Check that Meerkat is installed and reachable';

    public function handle(): int
    {
        $connection = Installer::connectionName();

        $this->components->info("Running health check on the [{$connection}] connection...");

        $statuses = Installer::statuses();

        foreach ($statuses as $check => $result) {
            $this->components->twoColumnDetail(
                $check,
                $result === true ? '<info>OK</info>' : '<error>'.$result.'</error>',
            );
        }

        $allOk = collect($statuses)->every(fn ($r) => $r === true);

        if (! $allOk) {
            $this->components->warn('Meerkat health check failed. Run `php artisan meerkat:install` (or `vendor:publish --tag=meerkat-migrations` + `migrate`) to set things up.');

            return self::FAILURE;
        }

        $this->components->info('Meerkat is healthy.');

        return self::SUCCESS;
    }
}
