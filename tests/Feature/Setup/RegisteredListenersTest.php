<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Setup;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Database\Models\ThreadMetric;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Events\CommentSaved;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class RegisteredListenersTest extends TestCase
{
    #[Test]
    public function comment_saves_reach_the_registered_listener_and_update_metrics_and_the_thread_cache(): void
    {
        $this->assertTrue(app('events')->hasListeners(CommentSaved::class));

        $this->createEntry(['id' => 'listener-metrics']);
        CommentFactory::new()->threadId('listener-metrics')->published()->create();

        $metric = $this->requireValue(ThreadMetric::query()->where('thread_id', 'listener-metrics')->first());
        $this->assertSame(1, (int) $metric->total_comments);

        $this->assertCount(1, $this->repo()->thread('listener-metrics'));

        CommentFactory::new()->threadId('listener-metrics')->published()->create();

        $this->assertCount(2, $this->repo()->thread('listener-metrics'));
        $metric = $this->requireValue(ThreadMetric::query()->where('thread_id', 'listener-metrics')->first());
        $this->assertSame(2, (int) $metric->total_comments);
    }

    #[Test]
    public function deleting_an_entry_soft_deletes_its_thread_through_the_registered_listener(): void
    {
        $entry = $this->createEntry(['id' => 'listener-entry-delete']);
        $this->createThread('listener-entry-delete');

        $entry->delete();

        $thread = $this->requireValue(Thread::withTrashed()->where('thread_id', 'listener-entry-delete')->first());
        $this->assertTrue($thread->trashed());
    }

    #[Test]
    public function user_lifecycle_events_sync_users_meta_through_the_registered_listeners(): void
    {
        $user = $this->makeStatamicUser();
        $user->id('listener-lifecycle-user');
        $user->email('lifecycle@example.com');
        $user->data(['name' => 'Lifecycle User']);
        $user->save();

        $this->assertDatabaseHas('users_meta', [
            'user_id' => 'listener-lifecycle-user',
            'email' => 'lifecycle@example.com',
            'name' => 'Lifecycle User',
        ], 'meerkat');

        $user->delete();

        $meta = $this->requireValue(UserMeta::withTrashed()->where('user_id', 'listener-lifecycle-user')->first());
        $this->assertTrue($meta->trashed());
    }
}
