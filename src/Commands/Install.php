<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Statamic\Console\RunsInPlease;

class Install extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:install
                            {--no-migrate : Publish only; skip running migrations}
                            {--force : Overwrite published files if they already exist}';

    protected $description = 'Publish Meerkat migrations and blueprint, then run migrations';

    public function handle(): int
    {
        $this->components->info('Publishing Meerkat blueprint and migrations...');

        Artisan::call('vendor:publish', array_filter([
            '--tag' => 'meerkat-blueprints',
            '--force' => $this->option('force') ?: null,
        ]));
        $this->line(Artisan::output());

        Artisan::call('vendor:publish', array_filter([
            '--tag' => 'meerkat-migrations',
            '--force' => $this->option('force') ?: null,
        ]));
        $this->line(Artisan::output());

        if ($this->option('no-migrate')) {
            $this->components->info('Skipping migrate. Run `php artisan migrate` when you are ready.');

            return self::SUCCESS;
        }

        $connection = config('meerkat.database.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $this->components->info(
            $connection
                ? "Running migrations on the [{$connection}] connection..."
                : 'Running migrations on the default connection...'
        );

        $exitCode = Artisan::call('migrate', array_filter([
            '--database' => $connection,
            '--force' => true,
        ]));

        $this->line(Artisan::output());

        if ($exitCode !== 0) {
            $this->components->error('Migrations failed. See output above.');

            return self::FAILURE;
        }

        $this->components->info('Meerkat installation complete.');

        return self::SUCCESS;
    }
}
