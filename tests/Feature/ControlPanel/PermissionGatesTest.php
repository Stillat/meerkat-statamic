<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\ControlPanel;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PermissionGatesTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('meerkat.revisions.enabled', true);
    }

    #[Test]
    public function control_panel_routes_enforce_their_declared_permissions(): void
    {
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->save();

        $comment = CommentFactory::new()
            ->threadId('permission-thread')
            ->collection('blog')
            ->author('Parent', 'parent@example.com')
            ->text('parent')
            ->data(['comment' => 'parent'])
            ->published()
            ->create();

        $collectionAccess = ['view blog entries', 'access default site'];
        $payload = ['comment' => 'edited', 'name' => 'A', 'email' => 'a@example.com'];

        $checks = [
            'dashboard' => [
                fn () => $this->get(cp_route('meerkat.cp.dashboard')),
                ['edit comments'],
                ['view comments'],
            ],
            'comment index' => [
                fn () => $this->getJson(cp_route('meerkat.cp.comments.index')),
                ['edit comments'],
                ['view comments'],
            ],
            'thread view' => [
                fn () => $this->getJson(cp_route('meerkat.comments.thread', ['threadId' => $comment->thread_id])),
                ['submit comments', ...$collectionAccess],
                ['view comments', ...$collectionAccess],
            ],
            'comment values' => [
                fn () => $this->getJson(cp_route('meerkat.comment.get', ['id' => $comment->id])),
                ['view blog entries'],
                ['view comments', ...$collectionAccess],
            ],
            'comment history' => [
                fn () => $this->getJson(cp_route('meerkat.comment.history', ['id' => $comment->id])),
                ['view blog entries'],
                ['view comments', ...$collectionAccess],
            ],
            'comment revisions' => [
                fn () => $this->getJson(cp_route('meerkat.comment.revisions', ['id' => $comment->id])),
                ['view blog entries'],
                ['view comments', ...$collectionAccess],
            ],
            'export' => [
                fn () => $this->get(cp_route('meerkat.comments.export')),
                ['edit comments'],
                ['view comments'],
            ],
            'spam scan' => [
                fn () => $this->post(cp_route('meerkat.comments.check-outstanding-for-spam')),
                ['view comments', 'edit comments'],
                ['check comment spam'],
            ],
            'reply data' => [
                fn () => $this->getJson(cp_route('meerkat.comment.reply-data', ['parent' => $comment->id])),
                ['view comments', ...$collectionAccess],
                ['submit comments', ...$collectionAccess],
            ],
            'reply submission' => [
                fn () => $this->postJson(cp_route('meerkat.comment.reply', ['parent' => $comment->id]), $payload),
                ['view comments', ...$collectionAccess],
                ['submit comments', ...$collectionAccess],
            ],
            'comment update' => [
                fn () => $this->putJson(cp_route('meerkat.comment.update', ['id' => $comment->id]), $payload),
                ['view comments', ...$collectionAccess],
                ['edit comments', ...$collectionAccess],
            ],
        ];

        foreach ($checks as $route => [$request, $deniedPermissions, $grantedPermissions]) {
            $this->actingAs($this->userWithPermissions(...$deniedPermissions));
            $request()->assertForbidden();

            $this->actingAs($this->userWithPermissions(...$grantedPermissions));
            $this->assertNotSame(403, $request()->getStatusCode(), "{$route} rejected its required permission");
        }
    }
}
