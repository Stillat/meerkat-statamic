<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PublishIdempotencyTest extends TestCase
{
    #[Test]
    public function publishing_twice_increments_the_parent_exactly_once(): void
    {
        $parent = CommentFactory::new()->threadId('publish-once')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('publish-once')->parent($parent->id)->depth(1)->unpublished()->create();

        $this->assertTrue(Comments::publish($child->id));
        $this->assertTrue(Comments::publish($child->id));

        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function unpublishing_twice_decrements_the_parent_exactly_once(): void
    {
        $parent = CommentFactory::new()->threadId('unpublish-once')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('unpublish-once')->parent($parent->id)->depth(1)->published()->create();
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);

        $this->assertTrue(Comments::unpublish($child->id));
        $this->assertTrue(Comments::unpublish($child->id));

        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }
}
