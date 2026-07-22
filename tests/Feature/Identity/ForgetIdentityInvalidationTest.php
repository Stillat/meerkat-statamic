<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Identity;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ForgetIdentityInvalidationTest extends TestCase
{
    #[Test]
    public function tombstone_mode_refreshes_metrics_reply_counters_and_the_thread_cache(): void
    {
        $this->createEntry(['id' => 'forget-invalidation']);
        $parent = CommentFactory::new()->threadId('forget-invalidation')->author('Keeper', 'keeper@example.com')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('forget-invalidation')->parent($parent->id)->depth(1)->author('Subject', 'subject@example.com')->published()->create();
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);

        $primed = $this->requireRows($this->repo()->thread('forget-invalidation'));
        $this->assertNotEmpty($this->requireList($primed[0]['children']));

        $this->pendingArtisan('meerkat:forget-identity', [
            '--email' => 'subject@example.com',
            '--mode' => 'tombstone',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue($this->requireValue($child->fresh())->is_removed);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        $metric = $this->requireValue(ThreadMetric::query()->where('thread_id', 'forget-invalidation')->first());
        $this->assertSame(1, (int) $metric->total_comments);

        $refreshed = $this->requireRows($this->repo()->thread('forget-invalidation'));
        $this->assertSame([], $this->requireList($refreshed[0]['children']));
    }
}
