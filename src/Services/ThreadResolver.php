<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services;

use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Fields\Value;
use Stillat\Meerkat\Database\Models\Thread;

class ThreadResolver
{
    public function forEntry(Entry $entry): string
    {
        $entryId = $this->entryId($entry);

        return $this->resolveEntryThread($entry, $entryId, []);
    }

    public function resolveReference(mixed $reference): ?string
    {
        $reference = $this->normalizeReference($reference);

        if ($reference === null) {
            return null;
        }

        $entry = EntryFacade::find($reference);

        return $entry ? $this->forEntry($entry) : $reference;
    }

    public function resolveEntry(string $threadId): ?Entry
    {
        $thread = Thread::query()->where('thread_id', $threadId)->first();

        if ($thread?->entry_id) {
            return EntryFacade::find($thread->entry_id);
        }

        return EntryFacade::find($threadId);
    }

    /**
     * @param  array<string, true>  $visited
     */
    private function resolveEntryThread(Entry $entry, string $rootEntryId, array $visited): string
    {
        $entryId = $this->entryId($entry);

        if (isset($visited[$entryId])) {
            return $rootEntryId;
        }

        $visited[$entryId] = true;
        $shareField = config('meerkat.publishing.share_field');

        if (! is_string($shareField) || $shareField === '') {
            return $entryId;
        }

        $reference = $this->normalizeReference($entry->get($shareField));

        if ($reference === null || $reference === $entryId) {
            return $entryId;
        }

        $sharedEntry = EntryFacade::find($reference);

        return $sharedEntry
            ? $this->resolveEntryThread($sharedEntry, $rootEntryId, $visited)
            : $reference;
    }

    private function normalizeReference(mixed $reference): ?string
    {
        if ($reference instanceof Value) {
            $reference = $reference->value();
        }

        if ($reference instanceof Collection) {
            $reference = $reference->first();
        }

        if (is_array($reference)) {
            $reference = $reference[0] ?? null;
        }

        if ($reference instanceof Entry) {
            return $this->entryId($reference);
        }

        if (! is_scalar($reference)) {
            return null;
        }

        $reference = trim((string) $reference);

        return $reference === '' ? null : $reference;
    }

    private function entryId(Entry $entry): string
    {
        $id = $entry->id();

        if (! is_string($id) && ! is_int($id)) {
            throw new \UnexpectedValueException('Statamic entry IDs must be strings.');
        }

        return (string) $id;
    }
}
