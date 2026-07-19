<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Api;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PerPageCapTest extends TestCase
{
    #[Test]
    public function roots_pagination_clamps_large_values_honors_small_values_and_defaults_invalid_values(): void
    {
        config()->set('meerkat.api.per_page', 7);
        config()->set('meerkat.api.max_per_page', 20);
        $this->seedComments('pagination-thread', 30);

        $capped = $this->getJson('/api/meerkat/threads/pagination-thread/roots?per_page=999')->assertOk();
        $this->assertCount(20, $this->requireList($capped->json('data')));
        $this->assertSame(20, $capped->json('meta.per_page'));
        $this->assertCount(12, $this->requireList($this->getJson('/api/meerkat/threads/pagination-thread/roots?per_page=12')->assertOk()->json('data')));
        foreach ([0, -5] as $invalid) {
            $this->assertCount(7, $this->requireList($this->getJson('/api/meerkat/threads/pagination-thread/roots?per_page='.$invalid)->assertOk()->json('data')));
        }
    }

    #[Test]
    public function children_pagination_uses_the_same_cap(): void
    {
        config()->set('meerkat.api.max_per_page', 20);
        $parent = CommentFactory::new()->threadId('children-cap')->published()->create();
        for ($i = 1; $i <= 30; $i++) {
            CommentFactory::new()->threadId('children-cap')->parent($parent->id)->depth(1)->published()->create();
        }

        $rows = $this->requireList($this->getJson('/api/meerkat/threads/children-cap/children/'.$parent->id.'?per_page=999')->assertOk()->json('data'));
        $this->assertCount(20, $rows);
    }

    private function seedComments(string $thread, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            CommentFactory::new()->threadId($thread)->published()->create();
        }
    }
}
