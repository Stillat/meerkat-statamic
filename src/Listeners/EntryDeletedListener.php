<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Statamic\Contracts\Entries\Entry;
use Statamic\Events\EntryDeleted;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Services\ThreadResolver;

class EntryDeletedListener
{
    public function handle(EntryDeleted $event): void
    {
        $entry = $event->entry;

        if (! $entry instanceof Entry) {
            throw new \UnexpectedValueException('EntryDeleted supplied an invalid entry.');
        }

        /** @var Thread|null $thread */
        $thread = Thread::query()
            ->where('thread_id', app(ThreadResolver::class)->forEntry($entry))
            ->first();

        if ($thread) {
            $thread->delete();
        }
    }
}
