<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Statamic\Console\RunsInPlease;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Mirror\Mirror;

class PurgeCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'meerkat:purge
        {--tombstones : Purge comments tombstoned via the default Delete action}
        {--spam : Purge comments with is_spam=true}
        {--rejected : Purge comments with moderation_status=rejected}
        {--anonymize-request-metadata : Null user_ip / user_agent / referer on old comments (data minimization, not deletion)}
        {--older-than= : Required. Number of days; rows older than this are eligible}
        {--collection= : Restrict to this collection handle (comment targets only)}
        {--site= : Restrict to this site handle (comment targets only)}
        {--thread= : Restrict to this thread id}
        {--dry-run : Show what would be purged without making changes}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently remove tombstoned / spam / stale rows from Meerkat tables.';

    public function handle(): int
    {
        $targets = collect(['tombstones', 'spam', 'rejected', 'anonymize-request-metadata'])
            ->filter(fn (string $target): bool => (bool) $this->option($target))
            ->values();

        if ($targets->count() !== 1) {
            $this->components->error(
                $targets->isEmpty()
                    ? 'Pick exactly one target: --tombstones, --spam, --rejected, or --anonymize-request-metadata.'
                    : 'Pick exactly ONE target per invocation. Got: '.$targets->implode(', '),
            );

            return self::FAILURE;
        }

        $daysOption = $this->option('older-than');
        $days = is_int($daysOption)
            ? $daysOption
            : (is_string($daysOption) && is_numeric($daysOption) ? (int) $daysOption : 0);
        if ($days < 1) {
            $this->components->error('--older-than is required and must be at least 1 (in days).');

            return self::FAILURE;
        }

        $target = $targets->first();
        $cutoff = now()->subDays($days);

        $this->components->info("Resolving {$target} rows older than {$cutoff->toDateTimeString()}...");

        $ids = match ($target) {
            'tombstones' => $this->commentIdsMatching(fn (Builder $query) => $query->where('is_removed', true)->where('removed_at', '<', $cutoff)),
            'spam' => $this->commentIdsMatching(fn (Builder $query) => $query->where('is_spam', true)->where('comments.created_at', '<', $cutoff)),
            'rejected' => $this->commentIdsMatching(fn (Builder $query) => $query->where('moderation_status', 'rejected')->where('comments.updated_at', '<', $cutoff)),
            'anonymize-request-metadata' => $this->anonymizableMetadataIds($cutoff),
            default => throw new \UnexpectedValueException("Unsupported purge target [{$target}]."),
        };

        $count = count($ids);

        if ($count === 0) {
            $this->components->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $verb = $target === 'anonymize-request-metadata' ? 'anonymize' : 'permanently delete';
        $this->components->info("Found {$count} row(s) eligible to {$verb}.");

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run — no rows were modified.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->components->confirm(ucfirst($verb)." {$count} row(s)?")) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        if (in_array($target, ['tombstones', 'spam', 'rejected'], true)) {
            $affected = $this->purgeComments($ids);
        } elseif ($target === 'anonymize-request-metadata') {
            $affected = $this->anonymizeRequestMetadata($ids);
        } else {
            throw new \UnexpectedValueException("Unsupported purge target [{$target}].");
        }

        $past = $target === 'anonymize-request-metadata' ? 'Anonymized' : 'Purged';
        $this->components->info("{$past} {$affected} row(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function commentIdsMatching(\Closure $constraint): array
    {
        $query = Comment::query()->withTrashed();
        $constraint($query);
        $this->applyCommentScoping($query);

        return $this->integerIds($query->pluck('comments.id')->all());
    }

    /** @param Builder<Comment> $query */
    private function applyCommentScoping(Builder $query): void
    {
        if ($collection = $this->option('collection')) {
            $query->where('collection', $collection);
        }

        if ($site = $this->option('site')) {
            $query->where('site', $site);
        }

        if ($thread = $this->option('thread')) {
            $query->where('thread_id', $thread);
        }
    }

    /**
     * @param  list<int>  $ids
     */
    private function purgeComments(array $ids): int
    {
        $deleted = 0;

        foreach ($ids as $id) {

            if (! Comment::withTrashed()->whereKey($id)->exists()) {
                continue;
            }

            if (Comments::forceDeleteComment($id)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<int>
     */
    private function anonymizableMetadataIds(Carbon $cutoff): array
    {
        $query = Comment::query()->withTrashed()
            ->where('comments.created_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('user_ip')
                    ->orWhereNotNull('user_agent')
                    ->orWhereNotNull('referer');
            });

        $this->applyCommentScoping($query);

        return $this->integerIds($query->pluck('comments.id')->all());
    }

    /**
     * @param  list<int>  $ids
     */
    private function anonymizeRequestMetadata(array $ids): int
    {
        $count = Comment::query()->withTrashed()
            ->whereIn('comments.id', $ids)
            ->update([
                'user_ip' => null,
                'user_agent' => null,
                'referer' => null,
            ]);

        Mirror::rewrite($ids);

        return $count;
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<int>
     */
    private function integerIds(iterable $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $ids[] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }
}
