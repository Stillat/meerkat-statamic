<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Hooks\Payload;
use Stillat\Meerkat\Data\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RepliesCountConsistencyTest extends TestCase
{
    #[Test]
    public function spam_auto_unpublish_decrements_the_direct_parent_count(): void
    {
        config(['meerkat.spam.guards' => []]);
        $this->createEntry(['id' => 'spam-count']);
        $parent = CommentFactory::new()->threadId('spam-count')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('spam-count')->parent($parent->id)->depth(1)->published()->create();
        CommentRepository::hook('after-spam-determined', function (mixed $payload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The spam hook did not receive a payload.');
            }

            $payload->is_spam = true;

            return $payload;
        });
        CommentRepository::hook('spam-action-decided', function (mixed $payload) {
            if (! $payload instanceof Payload) {
                throw new LogicException('The spam action hook did not receive a payload.');
            }

            $payload->should_delete = false;
            $payload->should_unpublish = true;

            return $payload;
        });

        app(CommentRepository::class)->checkForSpam($child->id);

        $this->assertFalse($this->requireValue($child->fresh())->is_published);
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function tombstoning_individual_bulk_and_middle_nodes_preserves_structural_reply_counts(): void
    {
        $root = CommentFactory::new()->threadId('tombstone-count')->depth(0)->published()->create();
        $middle = CommentFactory::new()->threadId('tombstone-count')->parent($root->id)->depth(1)->published()->create();
        $siblings = collect([
            CommentFactory::new()->threadId('tombstone-count')->parent($root->id)->depth(1)->published()->create(),
            CommentFactory::new()->threadId('tombstone-count')->parent($root->id)->depth(1)->published()->create(),
        ]);
        CommentFactory::new()->threadId('tombstone-count')->parent($middle->id)->depth(2)->published()->create();
        CommentFactory::new()->threadId('tombstone-count')->parent($middle->id)->depth(2)->published()->create();
        $rootCount = $this->requireValue($root->fresh())->replies_count;
        $middleCount = $this->requireValue($middle->fresh())->replies_count;

        Comments::deleteComment($middle->id);
        Comments::bulkDelete($this->requireIntegerList($siblings->pluck('id')->all()));

        $this->assertSame($rootCount, $this->requireValue($root->fresh())->replies_count);
        $this->assertSame($middleCount, $this->requireValue($middle->fresh())->replies_count);
    }

    #[Test]
    public function unpublishing_a_reply_decrements_the_parent_count(): void
    {
        $parent = CommentFactory::new()->threadId('unpublish-count')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('unpublish-count')->parent($parent->id)->depth(1)->published()->create();

        Comments::unpublish($child->id);

        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
    }

    #[Test]
    public function soft_delete_and_restore_remove_and_reinstate_the_parent_count(): void
    {
        $parent = CommentFactory::new()->threadId('soft-count')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('soft-count')->parent($parent->id)->depth(1)->published()->create();

        $child->delete();
        $this->assertSame(0, $this->requireValue($parent->fresh())->replies_count);
        Comment::withTrashed()->findOrFail($child->id)->restore();
        $this->assertSame(1, $this->requireValue($parent->fresh())->replies_count);
    }
}
