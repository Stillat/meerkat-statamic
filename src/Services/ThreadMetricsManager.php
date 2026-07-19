<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Stillat\Meerkat\Database\Models\ThreadMetric;

class ThreadMetricsManager
{
    public function recalculateThread(string $threadId): ThreadMetric
    {
        $connection = (new ThreadMetric)->getConnectionName();

        $aggregate = DB::connection($connection)
            ->table('comments')
            ->where('thread_id', $threadId)
            ->whereNull('deleted_at')
            ->where('is_removed', false)
            ->selectRaw('
                MIN(created_at) as first_comment_at,
                MAX(COALESCE(last_activity_at, created_at)) as last_activity_at,
                MAX(depth) as max_depth,
                COUNT(*) as total_comments,
                SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published_comments,
                SUM(CASE WHEN moderation_status = ? THEN 1 ELSE 0 END) as pending_comments,
                SUM(CASE WHEN moderation_status = ? THEN 1 ELSE 0 END) as rejected_comments,
                SUM(CASE WHEN is_spam = 1 THEN 1 ELSE 0 END) as spam_comments,
                SUM(CASE WHEN parent_id IS NULL THEN 1 ELSE 0 END) as root_comments,
                SUM(CASE WHEN parent_id IS NOT NULL THEN 1 ELSE 0 END) as reply_comments,
                SUM(CASE WHEN checked_for_spam = 1 THEN 1 ELSE 0 END) as checked_for_spam,
                SUM(CASE WHEN author_id IS NULL THEN 1 ELSE 0 END) as guest_comments,
                SUM(CASE WHEN author_id IS NOT NULL THEN 1 ELSE 0 END) as authenticated_comments
            ', ['pending', 'rejected'])
            ->first();

        $participants = DB::connection($connection)
            ->table('comments')
            ->where('thread_id', $threadId)
            ->whereNull('deleted_at')
            ->where('is_removed', false)
            ->selectRaw('COUNT(DISTINCT COALESCE(author_id, author_email)) as aggregate_count')
            ->value('aggregate_count');

        $sample = DB::connection($connection)
            ->table('comments')
            ->where('thread_id', $threadId)
            ->whereNull('deleted_at')
            ->where('is_removed', false)
            ->select('site', 'collection')
            ->latest('created_at')
            ->first();

        $metric = ThreadMetric::query()->firstOrNew([
            'thread_id' => $threadId,
        ]);

        if ($sample) {
            $metric->site = $this->nullableString($sample->site);
            $metric->collection = $this->nullableString($sample->collection);
        }
        $metric->total_comments = $this->integerValue($aggregate?->total_comments);
        $metric->published_comments = $this->integerValue($aggregate?->published_comments);
        $metric->pending_comments = $this->integerValue($aggregate?->pending_comments);
        $metric->spam_comments = $this->integerValue($aggregate?->spam_comments);
        $metric->root_comments = $this->integerValue($aggregate?->root_comments);
        $metric->reply_comments = $this->integerValue($aggregate?->reply_comments);
        $metric->participants = $this->integerValue($participants);
        $metric->max_depth = $this->integerValue($aggregate?->max_depth);
        $metric->first_comment_at = $this->carbonValue($aggregate?->first_comment_at);
        $metric->last_activity_at = $this->carbonValue($aggregate?->last_activity_at);
        $metric->metadata = [
            'checked_for_spam' => $this->integerValue($aggregate?->checked_for_spam),
            'guests' => $this->integerValue($aggregate?->guest_comments),
            'authenticated' => $this->integerValue($aggregate?->authenticated_comments),
            'rejected_comments' => $this->integerValue($aggregate?->rejected_comments),
        ];
        $metric->save();

        $this->invalidateThread($threadId);

        return $metric;
    }

    private function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function carbonValue(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getThreadMetric(string $threadId): ThreadMetric
    {
        return Cache::remember(
            $this->cacheKey($threadId),
            now()->addMinutes(10),
            fn () => ThreadMetric::query()->firstOrNew(
                ['thread_id' => $threadId],
                $this->emptyMetricAttributes()
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetricAttributes(): array
    {
        return [
            'total_comments' => 0,
            'published_comments' => 0,
            'pending_comments' => 0,
            'spam_comments' => 0,
            'root_comments' => 0,
            'reply_comments' => 0,
            'participants' => 0,
            'max_depth' => 0,
            'metadata' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return Cache::remember('meerkat.metrics.summary', now()->addMinutes(10), fn () => [
            'threads' => (int) ThreadMetric::query()->count(),
            'comments' => (int) ThreadMetric::query()->sum('total_comments'),
            'published_comments' => (int) ThreadMetric::query()->sum('published_comments'),
            'pending_comments' => (int) ThreadMetric::query()->sum('pending_comments'),
            'spam_comments' => (int) ThreadMetric::query()->sum('spam_comments'),
            'participants' => (int) ThreadMetric::query()->sum('participants'),
            'last_activity_at' => ThreadMetric::query()->max('last_activity_at'),
        ]);
    }

    public function invalidateThread(string $threadId): void
    {
        Cache::forget($this->cacheKey($threadId));
        Cache::forget('meerkat.metrics.summary');
    }

    private function cacheKey(string $threadId): string
    {
        return 'meerkat.metrics.thread.'.$threadId;
    }
}
