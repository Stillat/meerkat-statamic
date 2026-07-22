<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Threads;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class MetricDriftTest extends TestCase
{
    #[Test]
    public function recalculation_excludes_soft_deleted_rows_and_preserves_dimensions_when_none_remain(): void
    {
        $comment = CommentFactory::new()->threadId('metric-recalculate')->site('default')->collection('blog')->published()->create();
        app(ThreadMetricsManager::class)->recalculateThread('metric-recalculate');
        $comment->delete();

        $metric = app(ThreadMetricsManager::class)->recalculateThread('metric-recalculate');

        $this->assertSame(0, $metric->total_comments);
        $this->assertSame(0, $metric->published_comments);
        $this->assertSame('default', $metric->site);
        $this->assertSame('blog', $metric->collection);
    }

    #[Test]
    public function soft_delete_and_restore_refresh_materialized_metrics(): void
    {
        CommentFactory::new()->threadId('metric-lifecycle')->published()->create();
        $toggle = CommentFactory::new()->threadId('metric-lifecycle')->published()->create();

        $toggle->delete();
        $this->assertSame(1, ThreadMetric::query()->where('thread_id', 'metric-lifecycle')->value('total_comments'));
        $toggle->restore();
        $this->assertSame(2, ThreadMetric::query()->where('thread_id', 'metric-lifecycle')->value('total_comments'));
    }

    #[Test]
    public function sync_command_repairs_drifted_counts(): void
    {
        CommentFactory::new()->threadId('metric-sync')->published()->create();
        $metric = ThreadMetric::query()->updateOrCreate(['thread_id' => 'metric-sync'], ['total_comments' => 999, 'published_comments' => 999]);

        $this->pendingArtisan('meerkat:sync-metrics')->assertExitCode(0);

        $metric->refresh();
        $this->assertSame(1, $metric->total_comments);
        $this->assertSame(1, $metric->published_comments);
    }
}
