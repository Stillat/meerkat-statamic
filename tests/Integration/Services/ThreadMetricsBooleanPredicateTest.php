<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Services;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ThreadMetricsBooleanPredicateTest extends TestCase
{
    #[Test]
    public function recalculation_pins_every_counter_using_bare_boolean_predicates(): void
    {
        $threadId = 'metrics-boolean-thread';

        CommentFactory::new()->threadId($threadId)->author('A', 'a@e.com')->depth(0)->published()
            ->create(['checked_for_spam' => true]);
        $parent = CommentFactory::new()->threadId($threadId)->author('B', 'b@e.com')->depth(0)->published()->create();
        CommentFactory::new()->threadId($threadId)->author('C', 'c@e.com')->parent($parent->id)->depth(1)->pending()->create();
        CommentFactory::new()->threadId($threadId)->author('D', 'd@e.com')->depth(0)->spam()
            ->create(['is_published' => false]);

        $metric = app(ThreadMetricsManager::class)->recalculateThread($threadId);

        $this->assertSame(4, $metric->total_comments);
        $this->assertSame(2, $metric->published_comments);
        $this->assertSame(1, $metric->pending_comments);
        $this->assertSame(1, $metric->spam_comments);
        $this->assertSame(3, $metric->root_comments);
        $this->assertSame(1, $metric->reply_comments);
        $this->assertSame(4, $metric->participants);
        $this->assertSame(1, $metric->max_depth);
        $this->assertSame([
            'checked_for_spam' => 2,
            'guests' => 4,
            'authenticated' => 0,
            'rejected_comments' => 0,
        ], $metric->metadata);
    }
}
