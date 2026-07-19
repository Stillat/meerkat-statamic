<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Actions;

use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Stillat\Meerkat\Actions\CheckForSpam;
use Stillat\Meerkat\Actions\DeleteComment;
use Stillat\Meerkat\Actions\MarkAsHam;
use Stillat\Meerkat\Actions\MarkAsSpam;
use Stillat\Meerkat\Actions\Publish;
use Stillat\Meerkat\Actions\RejectComment;
use Stillat\Meerkat\Actions\RemoveCommentSubtree;
use Stillat\Meerkat\Actions\RestoreComment;
use Stillat\Meerkat\Actions\Unpublish;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class AuthorizationTest extends TestCase
{
    #[Test]
    public function bulk_visibility_is_driven_by_the_action_permission(): void
    {
        $cases = [
            [Publish::class, 'edit comments'],
            [Unpublish::class, 'edit comments'],
            [RejectComment::class, 'edit comments'],
            [DeleteComment::class, 'delete comments'],
            [CheckForSpam::class, 'check comment spam'],
            [MarkAsSpam::class, 'report comment spam'],
            [MarkAsHam::class, 'report comment spam'],
            [RemoveCommentSubtree::class, 'delete comments'],
        ];

        foreach ($cases as [$action, $permission]) {
            $this->actingAs($this->userWithPermissions($permission));
            $this->assertTrue(
                app($action)->visibleToBulk($this->emptyCommentCollection()),
                "{$action} should be visible with [{$permission}].",
            );

            $this->actingAs($this->userWithPermissions('view comments'));
            $this->assertFalse(
                app($action)->visibleToBulk($this->emptyCommentCollection()),
                "{$action} should be hidden without [{$permission}].",
            );
        }

        $this->actingAs($this->userWithPermissions('delete comments'));
        $tombstoned = CommentFactory::new()->create();
        Comments::deleteComment($tombstoned->id);
        $live = CommentFactory::new()->create();

        $restore = app(RestoreComment::class);
        $this->assertTrue($restore->visibleToBulk(collect([$this->requireValue($tombstoned->fresh()), $live])));
        $this->assertFalse($restore->visibleToBulk(collect([$live])));

        $this->actingAs($this->userWithPermissions('edit comments'));
        $this->assertFalse($restore->visibleToBulk(collect([$this->requireValue($tombstoned->fresh())])));
    }

    #[Test]
    public function individual_visibility_is_driven_by_comment_state(): void
    {
        $this->actingAs($this->userWithPermissions(
            'edit comments',
            'delete comments',
            'check comment spam',
            'report comment spam',
        ));

        $published = CommentFactory::new()->published()->create();
        $unpublished = CommentFactory::new()->unpublished()->create();
        $approved = CommentFactory::new()->create(['moderation_status' => 'approved']);
        $rejected = CommentFactory::new()->create(['moderation_status' => 'rejected']);
        $unchecked = CommentFactory::new()->create(['checked_for_spam' => false, 'is_spam' => false]);
        $spam = CommentFactory::new()->spam()->create();
        $ham = CommentFactory::new()->ham()->create();
        $normal = CommentFactory::new()->create(['is_spam' => false, 'is_ham' => false]);

        $this->assertTrue(app(Publish::class)->visibleTo($unpublished));
        $this->assertFalse(app(Publish::class)->visibleTo($published));
        $this->assertFalse(app(Publish::class)->visibleTo(new stdClass));

        $this->assertTrue(app(Unpublish::class)->visibleTo($published));
        $this->assertFalse(app(Unpublish::class)->visibleTo($unpublished));

        $this->assertTrue(app(RejectComment::class)->visibleTo($approved));
        $this->assertFalse(app(RejectComment::class)->visibleTo($rejected));
        $this->assertTrue(app(DeleteComment::class)->visibleTo($normal));

        $this->assertTrue(app(CheckForSpam::class)->visibleTo($unchecked));
        $this->assertFalse(app(CheckForSpam::class)->visibleTo($spam));

        $this->assertTrue(app(MarkAsSpam::class)->visibleTo($normal));
        $this->assertFalse(app(MarkAsSpam::class)->visibleTo($spam));
        $this->assertFalse(app(MarkAsSpam::class)->visibleTo($ham));

        $this->assertTrue(app(MarkAsHam::class)->visibleTo($spam));
        $this->assertFalse(app(MarkAsHam::class)->visibleTo($normal));

        $tombstoned = CommentFactory::new()->create();
        Comments::deleteComment($tombstoned->id);
        $this->assertTrue(app(RestoreComment::class)->visibleTo($this->requireValue($tombstoned->fresh())));
        $this->assertFalse(app(RestoreComment::class)->visibleTo($normal));

        $parent = CommentFactory::new()->depth(0)->published()->create();
        CommentFactory::new()->parent($parent->id)->depth(1)->published()->create();
        $leaf = CommentFactory::new()->depth(0)->published()->create();

        $removeSubtree = app(RemoveCommentSubtree::class);
        $this->assertTrue($removeSubtree->visibleTo($this->requireValue($parent->fresh())));
        $this->assertFalse($removeSubtree->visibleTo($leaf));

        Comments::deleteComment($parent->id);
        $this->assertFalse($removeSubtree->visibleTo($this->requireValue($parent->fresh())));
    }

    #[Test]
    public function individual_visibility_is_denied_before_state_is_considered(): void
    {
        $this->actingAs($this->userWithPermissions('view comments'));

        $published = CommentFactory::new()->published()->create();
        $unpublished = CommentFactory::new()->unpublished()->create();
        $normal = CommentFactory::new()->create();
        $spam = CommentFactory::new()->spam()->create();

        $cases = [
            [Publish::class, $unpublished],
            [Unpublish::class, $published],
            [RejectComment::class, $normal],
            [DeleteComment::class, $normal],
            [CheckForSpam::class, $normal],
            [MarkAsSpam::class, $normal],
            [MarkAsHam::class, $spam],
            [RestoreComment::class, $normal],
            [RemoveCommentSubtree::class, $normal],
        ];

        foreach ($cases as [$action, $comment]) {
            $this->assertFalse(
                app($action)->visibleTo($comment),
                "{$action} should be hidden when its permission is missing.",
            );
        }
    }
}
