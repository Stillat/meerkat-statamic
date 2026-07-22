<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RepliesCountTransitionsTest extends TestCase
{
    #[Test]
    public function soft_deleting_then_force_deleting_a_published_reply_decrements_the_parent_exactly_once(): void
    {
        [$parent, $child] = $this->tree('soft-then-force');

        $child->delete();
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        $this->assertTrue(Comments::forceDeleteComment($child->id));
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function force_deleting_a_live_published_reply_decrements_the_parent_once(): void
    {
        [$parent, $child] = $this->tree('live-force');

        $this->assertTrue(Comments::forceDeleteComment($child->id));
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function tombstoning_and_restoring_a_published_reply_adjusts_the_parent_count(): void
    {
        [$parent, $child] = $this->tree('tombstone-restore');

        Comments::deleteComment($child->id);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        Comments::restoreComment($child->id);
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function publishing_a_tombstoned_reply_does_not_increment_the_parent(): void
    {
        $parent = CommentFactory::new()->threadId('publish-tombstone')->depth(0)->published()->create();
        $child = CommentFactory::new()
            ->threadId('publish-tombstone')
            ->parent($parent->id)
            ->depth(1)
            ->unpublished()
            ->removed('tombstoned')
            ->create();
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        Comments::publish($child->id);

        $this->assertTrue($this->requireValue($child->fresh())->is_published);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function unpublishing_and_republishing_a_tombstoned_reply_nets_zero(): void
    {
        [$parent, $child] = $this->tree('republish-tombstone');

        Comments::deleteComment($child->id);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        Comments::unpublish($child->id);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        Comments::publish($child->id);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function remove_subtree_decrements_the_counters_of_every_affected_parent(): void
    {
        $root = CommentFactory::new()->threadId('subtree-counts')->depth(0)->published()->create();
        $middle = CommentFactory::new()->threadId('subtree-counts')->parent($root->id)->depth(1)->published()->create();
        CommentFactory::new()->threadId('subtree-counts')->parent($middle->id)->depth(2)->published()->create();
        $this->assertSame(1, $this->requireValue($root->fresh())->replies_count);
        $this->assertSame(1, $this->requireValue($middle->fresh())->replies_count);

        $this->assertSame(2, Comments::removeSubtree($middle->id));

        $this->assertSame(0, $this->requireValue($root->fresh())->replies_count);
        $this->assertSame(0, $this->requireValue($middle->fresh())->replies_count);
    }

    /** @return array{Comment, Comment} */
    private function tree(string $threadId): array
    {
        $parent = CommentFactory::new()->threadId($threadId)->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId($threadId)->parent($parent->id)->depth(1)->published()->create();
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);

        return [$parent, $child];
    }
}
