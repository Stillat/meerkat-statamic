<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Statamic\Contracts\Entries\Entry;
use Statamic\Entries\Collection;
use Statamic\Events\EntrySaved;
use Statamic\Sites\Site;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Services\ThreadResolver;

class EntrySavedListener
{
    public function handle(EntrySaved $event): void
    {
        $entry = $event->entry;

        if (! $entry instanceof Entry) {
            throw new \UnexpectedValueException('EntrySaved supplied an invalid entry.');
        }

        $threadId = app(ThreadResolver::class)->forEntry($entry);

        /** @var Thread|null $thread */
        $thread = Thread::withTrashed()->where('thread_id', $threadId)->first();

        if ($thread) {
            $thread->thread_id = $threadId;
            $thread->entry_id = $this->stringValue($entry->id(), 'entry ID');
            $title = $entry->get('title');
            $thread->cached_title = is_scalar($title) ? (string) $title : '';
            $thread->site = $this->siteHandle($entry->site());
            $thread->collection = $this->collectionHandle($entry->collection());
            if ($thread->trashed()) {
                $thread->restore();
            }
            $thread->saveQuietly();
        }
    }

    private function siteHandle(mixed $site): ?string
    {
        if ($site === null) {
            return null;
        }

        if (! $site instanceof Site) {
            throw new \UnexpectedValueException('Entry site must be a Statamic site.');
        }

        return $this->stringValue($site->handle(), 'site handle');
    }

    private function collectionHandle(mixed $collection): ?string
    {
        if ($collection === null) {
            return null;
        }

        if (! $collection instanceof Collection) {
            throw new \UnexpectedValueException('Entry collection must be a Statamic collection.');
        }

        return $this->stringValue($collection->handle(), 'collection handle');
    }

    private function stringValue(mixed $value, string $description): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new \UnexpectedValueException("Entry {$description} must be a string.");
        }

        return (string) $value;
    }
}
