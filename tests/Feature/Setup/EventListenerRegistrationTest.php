<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Setup;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class EventListenerRegistrationTest extends TestCase
{
    #[Test]
    public function entry_saved_events_update_existing_thread_metadata_through_registered_listeners(): void
    {
        $entry = $this->createEntry([
            'id' => 'listener-entry',
            'title' => 'Original Title',
        ]);

        $this->createThread('listener-entry', 'Stale Title');

        $entry->set('title', 'Updated Title');
        $entry->save();

        $this->assertDatabaseHas('threads', [
            'thread_id' => 'listener-entry',
            'entry_id' => 'listener-entry',
            'cached_title' => 'Updated Title',
        ], 'meerkat');
    }
}
