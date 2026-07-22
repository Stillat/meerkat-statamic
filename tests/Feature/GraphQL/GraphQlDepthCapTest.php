<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\GraphQL;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\GraphQL\Concerns\InteractsWithCommentVisibility;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class GraphQlDepthCapTest extends TestCase
{
    private function seedChain(string $threadId, int $depth): void
    {
        $parent = CommentFactory::new()->threadId($threadId)->text('depth 0')->data(['comment' => 'depth 0'])->published()->create();

        for ($i = 1; $i <= $depth; $i++) {
            $parent = CommentFactory::new()->replyTo($parent)->text('depth '.$i)->data(['comment' => 'depth '.$i])->published()->create();
        }
    }

    private function loadedDepth(string $threadId): int
    {
        $harness = new class
        {
            use InteractsWithCommentVisibility;

            public function constraint(): Closure
            {
                return $this->publicChildrenConstraint([]);
            }
        };

        $node = Comment::query()
            ->where('thread_id', $threadId)
            ->whereNull('parent_id')
            ->with(['allChildren' => $harness->constraint()])
            ->first();

        $this->assertNotNull($node);

        $depth = 0;

        while ($node->relationLoaded('allChildren') && ($child = $node->allChildren->first()) !== null) {
            $node = $child;
            $depth++;
        }

        return $depth;
    }

    #[Test]
    public function reply_hydration_stops_at_the_graphql_depth_cap(): void
    {
        config()->set('meerkat.graphql.max_depth', 3);
        $this->seedChain('gql-deep', 6);

        $this->assertSame(3, $this->loadedDepth('gql-deep'));
    }

    #[Test]
    public function a_write_time_reply_depth_cap_bounds_hydration_instead(): void
    {
        config()->set('meerkat.publishing.max_reply_depth', 2);
        config()->set('meerkat.graphql.max_depth', 10);
        $this->seedChain('gql-write-cap', 6);

        $this->assertSame(2, $this->loadedDepth('gql-write-cap'));
    }

    #[Test]
    public function unconfigured_installs_default_to_a_depth_of_twenty_five(): void
    {
        $this->seedChain('gql-default-cap', 6);

        // Default cap (25) comfortably exceeds this chain: everything loads.
        $this->assertSame(6, $this->loadedDepth('gql-default-cap'));
    }
}
