<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Statamic\Console\RunsInPlease;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Stillat\Meerkat\Concerns\ExtractsFields;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\UserMeta;

use function Laravel\Prompts\progress;

class SyncUsersMeta extends Command
{
    use ExtractsFields, RunsInPlease;

    protected $signature = 'meerkat:sync-users-meta {--chunk=100 : Number of records to upsert per batch}';

    protected $description = 'Refresh the users_meta table for every author_id referenced by a comment.';

    public function handle(): int
    {
        $this->components->info('Syncing user metadata...');

        $chunkOption = $this->option('chunk');
        $chunkSize = max(1, is_string($chunkOption) && is_numeric($chunkOption) ? (int) $chunkOption : 100);

        $this->components->info('Loading comment authors...');
        $authorIds = Comment::whereNotNull('author_id')
            ->distinct()
            ->pluck('author_id')
            ->flip();

        if ($authorIds->isEmpty()) {
            $this->components->warn('No authenticated comment authors found to sync.');

            return self::SUCCESS;
        }

        $this->components->info("Found {$authorIds->count()} unique comment authors.");

        $this->components->info('Loading existing user metadata...');
        $existingUserMeta = UserMeta::whereIn('user_id', $authorIds->keys()->toArray())
            ->get()
            ->keyBy('user_id');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $processed = 0;
        $upsertData = [];

        $this->components->info('Processing Statamic users...');

        User::all()
            ->each(function (mixed $user) use (
                $authorIds,
                $existingUserMeta,
                &$created,
                &$updated,
                &$skipped,
                &$processed,
                &$upsertData
            ) {
                if (! $user instanceof StatamicUser) {
                    return;
                }

                $userId = $user->id();

                if ((! is_string($userId) && ! is_int($userId)) || ! $authorIds->has($userId)) {
                    return;
                }

                $processed++;

                $details = $this->authorDetailsFromUser($user);

                $newEmail = $details['email'] ?? null;
                $newName = $details['name'] ?? null;

                $existingMeta = $existingUserMeta->get($userId);

                if (! $existingMeta) {
                    $now = now();
                    $upsertData[] = [
                        'user_id' => $userId,
                        'email' => $newEmail,
                        'name' => $newName,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ];
                    $created++;

                    return;
                }

                $needsUpdate = $existingMeta->email !== $newEmail ||
                               $existingMeta->name !== $newName;

                if (! $needsUpdate) {
                    $skipped++;

                    return;
                }

                $upsertData[] = [
                    'user_id' => $userId,
                    'email' => $newEmail,
                    'name' => $newName,
                    'created_at' => $existingMeta->created_at,
                    'updated_at' => now(),
                    'deleted_at' => null,
                ];
                $updated++;
            });

        $notFound = $authorIds->count() - $processed;

        if ($upsertData !== []) {
            $this->components->info('Upserting '.count($upsertData)." records in batches of {$chunkSize}...");

            $connection = (new UserMeta)->getConnectionName();
            $chunks = array_chunk($upsertData, $chunkSize);

            progress(
                label: 'Performing bulk upserts',
                steps: $chunks,
                callback: function (array $chunk) use ($connection) {
                    DB::connection($connection)
                        ->table('users_meta')
                        ->upsert(
                            $chunk,
                            ['user_id'],
                            ['email', 'name', 'updated_at', 'deleted_at']
                        );
                }
            );
        }

        $this->newLine();
        $this->components->info("Created: {$created} user meta records");
        $this->components->info("Updated: {$updated} user meta records");
        $this->components->info("Skipped (unchanged): {$skipped} records");

        if ($notFound > 0) {
            $this->components->warn("Users not found: {$notFound} (these may have been deleted)");
        }

        return self::SUCCESS;
    }
}
