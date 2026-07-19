<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RepliesCountTest extends TestCase
{
    #[Test]
    public function published_replies_increment_only_their_direct_parent(): void
    {
        $root = CommentFactory::new()->threadId('nested-counts')->depth(0)->published()->create();
        $firstChild = CommentFactory::new()
            ->threadId('nested-counts')
            ->parent($root->id)
            ->depth(1)
            ->published()
            ->create();
        $secondChild = CommentFactory::new()
            ->threadId('nested-counts')
            ->parent($root->id)
            ->depth(1)
            ->published()
            ->create();
        $grandchild = CommentFactory::new()
            ->threadId('nested-counts')
            ->parent($firstChild->id)
            ->depth(2)
            ->published()
            ->create();

        $this->assertSame(2, $this->requireValue($root->fresh())->replies_count);
        $this->assertSame(1, $this->requireValue($firstChild->fresh())->replies_count);
        $this->assertSame(0, $this->requireValue($secondChild->fresh())->replies_count);
        $this->assertSame(0, $this->requireValue($grandchild->fresh())->replies_count);
    }

    #[Test]
    public function publication_transitions_update_the_parent_count_once(): void
    {
        $parent = CommentFactory::new()->threadId('publication-counts')->depth(0)->published()->create();
        $child = CommentFactory::new()
            ->threadId('publication-counts')
            ->parent($parent->id)
            ->depth(1)
            ->unpublished()
            ->create();

        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);

        Comments::publish($child->id);
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);

        $child->refresh();
        $child->comment_text = 'Unrelated update';
        $child->save();
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);

        Comments::unpublish($child->id);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function counts_are_isolated_between_parents(): void
    {
        $first = CommentFactory::new()->threadId('isolated-counts')->depth(0)->published()->create();
        $second = CommentFactory::new()->threadId('isolated-counts')->depth(0)->published()->create();

        CommentFactory::new()->threadId('isolated-counts')->parent($first->id)->depth(1)->published()->create();
        CommentFactory::new()->threadId('isolated-counts')->parent($first->id)->depth(1)->published()->create();
        CommentFactory::new()->threadId('isolated-counts')->parent($second->id)->depth(1)->published()->create();

        $this->assertSame(2, $this->requireValue($first->fresh())->replies_count);
        $this->assertSame(1, $this->requireValue($second->fresh())->replies_count);
    }
}
