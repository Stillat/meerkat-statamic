<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Actions\DeleteComment;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class ActionAuthorizationTest extends TestCase
{
    #[Test]
    public function actions_reject_users_without_the_action_permission(): void
    {
        $comment = $this->makeBlogComment();

        $this->actingAs($this->userWithPermissions('view comments', 'view blog entries', 'access default site'));

        $this->postJson(cp_route('meerkat.comments.actions.run'), [
            'action' => DeleteComment::handle(),
            'selections' => [$comment->id],
            'context' => [],
            'values' => [],
        ])->assertForbidden();

        $fresh = $this->requireValue(Comment::query()->withTrashed()->find($comment->id));
        $this->assertFalse((bool) $fresh->is_removed);
    }

    #[Test]
    public function actions_run_for_users_with_the_action_permission(): void
    {
        $comment = $this->makeBlogComment();

        $this->actingAs($this->userWithPermissions('view comments', 'delete comments', 'view blog entries', 'access default site'));

        $this->postJson(cp_route('meerkat.comments.actions.run'), [
            'action' => DeleteComment::handle(),
            'selections' => [$comment->id],
            'context' => [],
            'values' => [],
        ])->assertOk()->assertJson(['success' => true]);

        $fresh = $this->requireValue(Comment::query()->withTrashed()->find($comment->id));
        $this->assertTrue((bool) $fresh->is_removed);
    }

    #[Test]
    public function the_action_routes_require_the_view_comments_permission(): void
    {
        $comment = $this->makeBlogComment();

        $this->actingAs($this->userWithPermissions('delete comments', 'view blog entries', 'access default site'));

        $this->postJson(cp_route('meerkat.comments.actions.run'), [
            'action' => DeleteComment::handle(),
            'selections' => [$comment->id],
            'context' => [],
            'values' => [],
        ])->assertForbidden();

        $this->postJson(cp_route('meerkat.comments.actions.bulk'), [
            'selections' => [$comment->id],
        ])->assertForbidden();
    }

    #[Test]
    public function authorization_checks_the_provided_user_rather_than_the_authenticated_user(): void
    {
        $comment = $this->makeBlogComment();
        $privileged = $this->userWithPermissions('delete comments');
        $unprivileged = $this->userWithPermissions('view comments');

        $this->actingAs($unprivileged);
        $this->assertTrue(app(DeleteComment::class)->authorize($privileged, $comment));

        $this->actingAs($privileged);
        $this->assertFalse(app(DeleteComment::class)->authorize($unprivileged, $comment));
    }

    private function makeBlogComment(): Comment
    {
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->save();

        return CommentFactory::new()
            ->threadId('action-auth-thread')
            ->collection('blog')
            ->author('Author', 'author@example.com')
            ->text('body')
            ->data(['comment' => 'body'])
            ->published()
            ->create();
    }
}
