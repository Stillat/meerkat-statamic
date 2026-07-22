<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Database\QueryBuilder;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class HierarchyScopesTest extends TestCase
{
    protected string $threadId = 'test-thread';

    #[Test]
    public function it_retrieves_only_root_comments(): void
    {
        $root = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => null,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => $root->id,
        ]);

        $roots = Comments::query()
            ->forThread($this->threadId)
            ->roots()
            ->get();

        $this->assertCount(1, $roots);
        $this->assertNull($this->requireValue($roots->first())->parent_id);
    }

    #[Test]
    public function it_retrieves_only_leaf_comments(): void
    {
        $root = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => null,
        ]);

        $child = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => $root->id,
        ]);

        $leaves = Comments::query()
            ->forThread($this->threadId)
            ->leaves()
            ->get();

        $this->assertCount(1, $leaves);
        $this->assertSame($child->id, $this->requireValue($leaves->first())->id);
    }

    #[Test]
    public function it_retrieves_comments_at_specific_depth(): void
    {
        $root = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => null,
            'depth' => 0,
        ]);

        $child = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => $root->id,
            'depth' => 1,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'parent_id' => $child->id,
            'depth' => 2,
        ]);

        $atDepthOne = Comments::query()
            ->forThread($this->threadId)
            ->atDepth(1)
            ->get();

        $this->assertCount(1, $atDepthOne);
        $this->assertSame($child->id, $this->requireValue($atDepthOne->first())->id);
    }

    #[Test]
    public function it_retrieves_comments_within_depth_range(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 0,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 1,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 2,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'depth' => 3,
        ]);

        $inRange = Comments::query()
            ->forThread($this->threadId)
            ->depthBetween(1, 2)
            ->get();

        $this->assertCount(2, $inRange);
    }

    #[Test]
    public function it_retrieves_all_descendants_of_comment(): void
    {
        $root = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '1',
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '1.2',
            'parent_id' => $root->id,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '1.2.3',
            'parent_id' => $root->id,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '4',
        ]);

        $descendants = Comments::query()
            ->descendantsOf($root->id)
            ->get();

        $this->assertCount(2, $descendants);
    }

    #[Test]
    public function it_retrieves_subtree_including_root(): void
    {
        $root = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '1',
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '1.2',
            'parent_id' => $root->id,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'path' => '3',
        ]);

        $subtree = Comments::query()
            ->subtreeOf($root->id)
            ->get();

        $this->assertCount(2, $subtree);
    }

    #[Test]
    public function it_retrieves_comments_with_replies(): void
    {
        $withReplies = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'replies_count' => 3,
        ]);

        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'replies_count' => 0,
        ]);

        $comments = Comments::query()
            ->forThread($this->threadId)
            ->withReplies()
            ->get();

        $this->assertCount(1, $comments);
        $this->assertSame($withReplies->id, $this->requireValue($comments->first())->id);
    }

    #[Test]
    public function it_retrieves_comments_without_replies(): void
    {
        CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'replies_count' => 3,
        ]);

        $withoutReplies = CommentFactory::new()->create([
            'thread_id' => $this->threadId,
            'replies_count' => 0,
        ]);

        $comments = Comments::query()
            ->forThread($this->threadId)
            ->withoutReplies()
            ->get();

        $this->assertCount(1, $comments);
        $this->assertSame($withoutReplies->id, $this->requireValue($comments->first())->id);
    }
}
