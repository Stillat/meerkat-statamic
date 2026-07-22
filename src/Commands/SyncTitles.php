<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Statamic\Console\RunsInPlease;
use Statamic\Entries\Entry as EntryModel;
use Statamic\Facades\Entry;
use Statamic\Query\Builder;
use Stillat\Meerkat\Database\Models\Thread;

use function Laravel\Prompts\progress;

class SyncTitles extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:sync-titles {--chunk=100 : Number of records to update per batch}';

    protected $description = 'Refresh each thread\'s cached entry metadata from its linked Statamic entry.';

    public function handle(): int
    {
        $this->components->info('Syncing thread metadata...');

        $chunkSize = max(1, (int) $this->option('chunk'));

        $threads = Thread::query()->get()->keyBy('thread_id');

        if ($threads->isEmpty()) {
            $this->components->warn('No threads found to sync.');

            return self::SUCCESS;
        }

        $this->components->info("Found {$threads->count()} threads to process.");

        $this->components->info('Loading Statamic entries...');
        $entryIds = $threads
            ->flatMap(fn (Thread $thread) => [$thread->entry_id, $thread->thread_id])
            ->filter()
            ->unique()
            ->values();
        $entries = $this->entriesById($entryIds->all());
        $synced = 0;
        $skipped = 0;
        $noPublishedEntry = 0;
        $updates = [];

        $this->components->info('Preparing updates...');
        foreach ($threads as $thread) {
            $entry = $entries->get($thread->entry_id) ?? $entries->get($thread->thread_id);

            if (! $entry || $entry->status() !== 'published') {
                $noPublishedEntry++;

                continue;
            }

            $attributes = [
                'entry_id' => $entry->id(),
                'cached_title' => $this->entryTitle($entry),
                'site' => $entry->site()->handle(),
                'collection' => $entry->collection()->handle(),
            ];

            $unchanged = collect($attributes)->every(
                fn ($value, $key) => $thread->{$key} === $value
            );

            if ($unchanged) {
                $skipped++;

                continue;
            }

            $updates[] = [
                'id' => $thread->id,
                'thread_id' => $thread->thread_id,
                ...$attributes,
                'created_at' => $thread->created_at,
                'updated_at' => now(),
            ];
            $synced++;
        }

        if ($updates !== []) {
            $this->components->info("Updating {$synced} threads in batches of {$chunkSize}...");

            $connection = (new Thread)->getConnectionName();
            $chunks = array_chunk($updates, $chunkSize);

            progress(
                label: 'Performing bulk updates',
                steps: $chunks,
                callback: function ($chunk) use ($connection) {
                    DB::connection($connection)
                        ->table('threads')
                        ->upsert(
                            $chunk,
                            ['thread_id'],
                            ['entry_id', 'cached_title', 'site', 'collection', 'updated_at']
                        );
                }
            );
        }

        $this->newLine();
        $this->components->info("Synced: {$synced} threads");
        $this->components->info("Skipped (unchanged): {$skipped} threads");
        $this->components->info("Skipped (no published entry): {$noPublishedEntry} threads");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return Collection<string, EntryModel>
     */
    private function entriesById(array $ids): Collection
    {
        $query = Entry::query();

        if (! $query instanceof Builder) {
            throw new \LogicException('Statamic returned an invalid entry query builder.');
        }

        $query = $query->whereIn('id', $ids);

        if (! $query instanceof Builder) {
            throw new \LogicException('Statamic returned an invalid constrained entry query builder.');
        }

        $entries = $query->get();

        if (! $entries instanceof Collection) {
            throw new \LogicException('Statamic returned an invalid entry collection.');
        }

        return $entries
            ->filter(fn (mixed $entry): bool => $entry instanceof EntryModel)
            ->keyBy(fn (EntryModel $entry): string => $entry->id());
    }

    private function entryTitle(EntryModel $entry): string
    {
        $title = $entry->get('title');

        return is_scalar($title) ? (string) $title : '';
    }
}
