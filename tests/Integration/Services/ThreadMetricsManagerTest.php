<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Services;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ThreadMetricsManagerTest extends TestCase
{
    #[Test]
    public function it_recalculates_thread_counters(): void
    {
        $threadId = 'metrics-thread';

        CommentFactory::new()->threadId($threadId)->author('A', 'a@e.com')->depth(0)->published()->create();
        $parent = CommentFactory::new()->threadId($threadId)->author('B', 'b@e.com')->depth(0)->published()->create();
        CommentFactory::new()->threadId($threadId)->author('C', 'c@e.com')->parent($parent->id)->depth(1)->published()->create();

        $metric = app(ThreadMetricsManager::class)->recalculateThread($threadId);

        $this->assertSame($threadId, $metric->thread_id);
        $this->assertSame(3, $metric->total_comments);
        $this->assertSame(3, $metric->participants);
    }

    #[Test]
    public function it_excludes_tombstoned_comments_from_the_counters(): void
    {
        $threadId = 'metrics-tombstone-thread';

        CommentFactory::new()->threadId($threadId)->author('A', 'a@e.com')->depth(0)->published()->create();
        $tombstoned = CommentFactory::new()->threadId($threadId)->author('B', 'b@e.com')->depth(0)->published()->create();

        Comments::deleteComment($tombstoned->id);

        $metric = app(ThreadMetricsManager::class)->recalculateThread($threadId);

        $this->assertSame(1, $metric->total_comments);
        $this->assertSame(1, $metric->published_comments);
        $this->assertSame(1, $metric->root_comments);
        $this->assertSame(1, $metric->participants);
    }
}
