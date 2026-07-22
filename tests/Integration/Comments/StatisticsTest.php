<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class StatisticsTest extends TestCase
{
    #[Test]
    public function grouped_counts_compose_with_scopes_and_handle_empty_results(): void
    {
        CommentFactory::new()->count(3, [
            'thread_id' => 'stats-a',
            'author_name' => 'John',
            'depth' => 0,
            'is_published' => true,
        ]);
        CommentFactory::new()->count(2, [
            'thread_id' => 'stats-a',
            'author_name' => 'Jane',
            'depth' => 1,
            'is_published' => false,
        ]);
        CommentFactory::new()->create(['thread_id' => 'stats-b', 'author_name' => 'Elsewhere']);

        $this->assertSame(['stats-a' => 5, 'stats-b' => 1], Comments::query()->countByThread());
        $this->assertSame(
            ['John' => 3],
            Comments::query()->forThread('stats-a')->published()->countByAuthor(),
        );
        $this->assertSame([0 => 3, 1 => 2], Comments::query()->forThread('stats-a')->countByDepth());
        $this->assertSame([], Comments::query()->forThread('missing')->countByThread());
        $this->assertSame([], Comments::query()->forThread('missing')->countByAuthor());
        $this->assertSame([], Comments::query()->forThread('missing')->countByDepth());
    }

    #[Test]
    public function active_threads_include_order_counts_participants_activity_and_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        CommentFactory::new()->create([
            'thread_id' => 'active-a',
            'author_name' => 'John',
            'author_email' => 'john@example.com',
            'created_at' => now()->subDays(2),
        ]);
        CommentFactory::new()->create([
            'thread_id' => 'active-a',
            'author_name' => 'Jane',
            'author_email' => 'jane@example.com',
            'created_at' => now(),
        ]);
        CommentFactory::new()->create([
            'thread_id' => 'active-a',
            'author_name' => 'John',
            'author_email' => 'john@example.com',
            'created_at' => now()->subDay(),
        ]);
        CommentFactory::new()->create(['thread_id' => 'active-b']);

        $active = Comments::query()->mostActiveThreads(1);

        $this->assertCount(1, $active);
        $this->assertSame('active-a', $active[0]['thread_id']);
        $this->assertSame(3, $active[0]['comment_count']);
        $this->assertSame(2, $active[0]['participant_count']);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $active[0]['last_activity']);
    }

    #[Test]
    public function thread_statistics_describe_the_full_moderation_and_hierarchy_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        $root = CommentFactory::new()->create([
            'thread_id' => 'thread-stats',
            'parent_id' => null,
            'depth' => 0,
            'is_published' => true,
            'is_spam' => false,
            'author_email' => 'john@example.com',
            'created_at' => now()->subDays(3),
        ]);
        CommentFactory::new()->create([
            'thread_id' => 'thread-stats',
            'parent_id' => $root->id,
            'depth' => 1,
            'is_published' => true,
            'is_spam' => false,
            'author_email' => 'jane@example.com',
            'created_at' => now()->subDays(2),
        ]);
        CommentFactory::new()->create([
            'thread_id' => 'thread-stats',
            'parent_id' => null,
            'depth' => 0,
            'is_published' => false,
            'is_spam' => false,
            'author_email' => 'john@example.com',
            'created_at' => now()->subDay(),
        ]);
        CommentFactory::new()->create([
            'thread_id' => 'thread-stats',
            'parent_id' => $root->id,
            'depth' => 1,
            'is_published' => false,
            'is_spam' => true,
            'author_email' => 'spam@example.com',
            'created_at' => now(),
        ]);

        $stats = Comments::query()->threadStats('thread-stats');

        $this->assertSame(4, $stats['total_comments']);
        $this->assertSame(2, $stats['root_comments']);
        $this->assertSame(2, $stats['total_replies']);
        $this->assertSame(3, $stats['participants']);
        $this->assertSame(1, $stats['max_depth']);
        $this->assertSame(0.5, $stats['avg_depth']);
        $this->assertSame(2, $stats['published_count']);
        $this->assertSame(1, $stats['spam_count']);
        $this->assertNotNull($stats['first_comment']);
        $this->assertNotNull($stats['last_comment']);

        $empty = Comments::query()->threadStats('missing');
        $this->assertSame(0, $empty['total_comments']);
        $this->assertNull($empty['first_comment']);
        $this->assertNull($empty['last_comment']);
    }

    #[Test]
    public function top_contributors_include_order_thread_participation_dates_and_limit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));

        $first = CommentFactory::new()->create([
            'author_name' => 'John',
            'author_email' => 'john@example.com',
            'thread_id' => 'contributors-a',
            'created_at' => now()->subDays(10),
        ]);
        CommentFactory::new()->create([
            'author_name' => 'John',
            'author_email' => 'john@example.com',
            'thread_id' => 'contributors-b',
            'created_at' => now()->subDays(5),
        ]);
        $last = CommentFactory::new()->create([
            'author_name' => 'John',
            'author_email' => 'john@example.com',
            'thread_id' => 'contributors-a',
            'created_at' => now(),
        ]);
        CommentFactory::new()->count(2, [
            'author_name' => 'Jane',
            'author_email' => 'jane@example.com',
            'thread_id' => 'contributors-a',
        ]);

        $contributors = Comments::query()->topContributors(1);

        $this->assertCount(1, $contributors);
        $this->assertSame('John', $contributors[0]['name']);
        $this->assertSame(3, $contributors[0]['comment_count']);
        $this->assertSame(2, $contributors[0]['threads_participated']);
        $this->assertSame($this->requireValue($first->created_at)->format('Y-m-d H:i:s'), $contributors[0]['first_comment']);
        $this->assertSame($this->requireValue($last->created_at)->format('Y-m-d H:i:s'), $contributors[0]['last_comment']);
    }
}
