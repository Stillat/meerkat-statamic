<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Comments;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stillat\Meerkat\Actions\DeleteComment;
use Stillat\Meerkat\Actions\RejectComment;
use Stillat\Meerkat\Actions\RemoveCommentSubtree;
use Stillat\Meerkat\Actions\RestoreComment;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class DeleteActionsTest extends TestCase
{
    #[Test]
    public function reject_action_collects_and_persists_a_reason(): void
    {
        $this->assertArrayHasKey('reason', app(RejectComment::class)->fieldItems());
        $comment = CommentFactory::new()->published()->create();

        app(RejectComment::class)->run(collect([$this->requireValue($comment->fresh())]), ['reason' => 'Off-topic']);

        $this->assertSame('rejected', $this->requireValue($comment->fresh())->moderation_status);
        $this->assertSame('Off-topic', $this->requireValue($comment->fresh())->moderation_reason);
    }

    #[Test]
    public function delete_action_tombstones_valid_rows_without_cascading_and_surfaces_partial_failure(): void
    {
        [$parent, $childA, $childB] = $this->tree('delete-action');
        $ghost = CommentFactory::new()->create();
        Comments::forceDeleteComment($ghost->id);

        try {
            app(DeleteComment::class)->run(collect([$this->requireValue($parent->fresh()), $ghost]), []);
            $this->fail('Expected partial failure to be surfaced.');
        } catch (RuntimeException) {
            $this->assertTrue($this->requireValue($parent->fresh())->is_removed);
            $this->assertNull($this->requireValue($parent->fresh())->deleted_at);
            $this->assertFalse($this->requireValue($childA->fresh())->is_removed);
            $this->assertFalse($this->requireValue($childB->fresh())->is_removed);
        }
    }

    #[Test]
    public function restore_action_restores_tombstones_and_ignores_live_rows(): void
    {
        $tombstone = CommentFactory::new()->create();
        Comments::deleteComment($tombstone->id, 'mistake');
        $live = CommentFactory::new()->create();

        $message = app(RestoreComment::class)->run(collect([$this->requireValue($tombstone->fresh()), $this->requireValue($live->fresh())]), []);

        $this->assertFalse($this->requireValue($tombstone->fresh())->is_removed);
        $this->assertNull($this->requireValue($tombstone->fresh())->removed_at);
        $this->assertFalse($this->requireValue($live->fresh())->is_removed);
        $this->assertStringContainsString('restored', $message);
    }

    #[Test]
    public function remove_subtree_action_tombstones_every_descendant(): void
    {
        [$parent, $childA, $childB] = $this->tree('subtree-action');
        $grandchild = CommentFactory::new()->threadId('subtree-action')->parent($childA->id)->depth(2)->published()->create();

        $message = app(RemoveCommentSubtree::class)->run(collect([$this->requireValue($parent->fresh())]), []);

        foreach ([$parent, $childA, $childB, $grandchild] as $comment) {
            $this->assertTrue($this->requireValue($comment->fresh())->is_removed);
        }
        $this->assertStringContainsString('4 comments were removed', $message);
    }

    /** @return array{Comment, Comment, Comment} */
    private function tree(string $threadId): array
    {
        $parent = CommentFactory::new()->threadId($threadId)->depth(0)->published()->create();
        $childA = CommentFactory::new()->threadId($threadId)->parent($parent->id)->depth(1)->published()->create();
        $childB = CommentFactory::new()->threadId($threadId)->parent($parent->id)->depth(1)->published()->create();

        return [$parent, $childA, $childB];
    }
}
