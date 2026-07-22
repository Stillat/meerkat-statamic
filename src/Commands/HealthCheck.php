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

    /** @var list<string> */
    private const INSTALL_CHECKS = ['connection', 'blueprint', 'tables', 'columns', 'indexes'];

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

        $installFailed = collect($statuses)
            ->filter(fn ($r, $check) => in_array($check, self::INSTALL_CHECKS, true))
            ->contains(fn ($r) => $r !== true);

        if ($installFailed) {
            $this->components->warn('Meerkat is not fully installed. Run `php artisan meerkat:install` (or `vendor:publish --tag=meerkat-migrations` + `migrate`) to set things up.');

            return self::FAILURE;
        }

        if (collect($statuses)->contains(fn ($r) => $r !== true)) {
            $this->components->warn('Meerkat is installed but some configuration checks failed. Review the entries above.');

            return self::FAILURE;
        }

        $this->components->info('Meerkat is healthy.');

        return self::SUCCESS;
    }
}
