<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Exporters;

use Stillat\Meerkat\Database\Models\Thread;

class JsonExporter extends Exporter
{
    public function export(): string
    {
        $threadIds = collect($this->comments)->pluck('thread_id')->unique()->values();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'threads' => Thread::query()
                ->whereIn('thread_id', $threadIds)
                ->get()
                ->map(fn ($thread) => $thread->toArray())
                ->values()
                ->all(),
            'comments' => collect($this->comments)
                ->map(fn ($comment) => $comment->toExportArray())
                ->values()
                ->all(),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    public function extension(): string
    {
        return 'json';
    }
}
