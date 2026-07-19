<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Maintenance;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Tests\TestCase;

class SyncTitlesCommandTest extends TestCase
{
    #[Test]
    public function unchanged_titles_do_not_prevent_other_thread_metadata_from_refreshing(): void
    {
        $entry = $this->createEntry([
            'id' => 'sync-thread-metadata',
            'title' => 'Stable title',
        ]);

        Thread::query()->create([
            'thread_id' => $entry->id(),
            'entry_id' => 'missing-entry',
            'cached_title' => 'Stable title',
            'site' => 'stale-site',
            'collection' => 'stale-collection',
        ]);

        $this->pendingArtisan('meerkat:sync-titles', ['--chunk' => 0])->assertSuccessful();

        $thread = Thread::query()->where('thread_id', $entry->id())->firstOrFail();

        $this->assertSame($entry->id(), $thread->entry_id);
        $this->assertSame($entry->site()->handle(), $thread->site);
        $this->assertSame($entry->collection()->handle(), $thread->collection);
        $this->assertSame('Stable title', $thread->cached_title);
    }
}
