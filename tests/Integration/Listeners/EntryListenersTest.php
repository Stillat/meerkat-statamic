<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Listeners;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Listeners\EntryDeletedListener;
use Stillat\Meerkat\Listeners\EntrySavedListener;
use Stillat\Meerkat\Tests\TestCase;

class EntryListenersTest extends TestCase
{
    #[Test]
    public function saved_listener_updates_existing_thread_without_creating_missing_threads(): void
    {
        $existing = $this->createEntry(['id' => 'existing-entry', 'title' => 'Original']);
        Thread::create(['thread_id' => $existing->id(), 'cached_title' => 'Original']);
        $existing->set('title', 'Updated');
        $listener = new EntrySavedListener;
        $listener->handle(new EntrySaved($existing));
        $this->assertSame('Updated', Thread::query()->where('thread_id', 'existing-entry')->value('cached_title'));

        $missing = $this->createEntry(['id' => 'missing-thread-entry', 'title' => 'No Thread']);
        $listener->handle(new EntrySaved($missing));
        $this->assertFalse(Thread::query()->where('thread_id', 'missing-thread-entry')->exists());
    }

    #[Test]
    public function deleted_listener_soft_deletes_only_the_matching_thread_and_preserves_its_data(): void
    {
        $entry = $this->createEntry(['id' => 'deleted-entry', 'title' => 'Deleted Entry']);
        $other = $this->createEntry(['id' => 'other-entry', 'title' => 'Other Entry']);
        $thread = Thread::create(['thread_id' => $entry->id(), 'cached_title' => 'Deleted Entry']);
        Thread::create(['thread_id' => $other->id(), 'cached_title' => 'Other Entry']);

        (new EntryDeletedListener)->handle(new EntryDeleted($entry));

        $deleted = Thread::withTrashed()->findOrFail($thread->id);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame('deleted-entry', $deleted->thread_id);
        $this->assertSame('Deleted Entry', $deleted->cached_title);
        $this->assertNull(Thread::query()->where('thread_id', 'other-entry')->firstOrFail()->deleted_at);
    }
}
