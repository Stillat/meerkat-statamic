<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Mirror;

use Illuminate\Support\Facades\File;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentRevision;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Exceptions\MirrorWriteException;
use Symfony\Component\Yaml\Yaml;

class MirrorWriter
{
    private static bool $suppressed = false;

    public function __construct(private readonly MirrorPathResolver $paths) {}

    public static function suppress(callable $callback): mixed
    {
        $prior = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $prior;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed;
    }

    public function write(Comment $comment): void
    {
        if (self::$suppressed) {
            return;
        }

        $this->ensureTimestampId($comment);

        $file = $this->paths->fileFor($comment);

        $this->ensureDirectory(dirname($file));
        $this->putFile($file, CommentSerializer::toString($comment));
    }

    public function remove(Comment $comment): void
    {
        if (self::$suppressed) {
            return;
        }

        if ($comment->timestamp_id === null || $comment->timestamp_id === '') {
            return;
        }

        $file = $this->paths->fileFor($comment);

        if (File::exists($file)) {
            File::delete($file);
        }

        $directory = dirname($file);

        $revisionsFile = $directory.'/.revisions';
        if (File::exists($revisionsFile)) {
            File::delete($revisionsFile);
        }

        if (File::isDirectory($directory) && $this->isEmptyDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    private function ensureTimestampId(Comment $comment): void
    {
        if ($comment->timestamp_id !== null && $comment->timestamp_id !== '') {
            return;
        }

        $base = $comment->created_at?->getTimestamp() ?? time();
        $candidate = (string) $base;

        while ($this->isTaken($comment->thread_id, $candidate, $comment->id)) {
            $base++;
            $candidate = (string) $base;
        }

        $comment->timestamp_id = $candidate;
        Comment::query()
            ->where('comments.id', $comment->id)
            ->update(['timestamp_id' => $candidate]);
    }

    private function isTaken(string $threadId, string $candidate, int $selfId): bool
    {
        return Comment::query()
            ->where('comments.thread_id', $threadId)
            ->where('comments.timestamp_id', $candidate)
            ->where('comments.id', '!=', $selfId)
            ->exists();
    }

    private function isEmptyDirectory(string $directory): bool
    {
        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return false;
            }
        }

        return true;
    }

    public function writeThreadMeta(Thread $thread): void
    {
        if (self::$suppressed) {
            return;
        }

        $threadDir = $this->paths->threadDirectory($thread->thread_id);

        $this->ensureDirectory($threadDir);

        $meta = [
            'trashed' => $thread->deleted_at !== null,
            'created' => $thread->created_at?->getTimestamp() ?? time(),

            'attributes' => [],
        ];

        $this->putFile($threadDir.'/.meta', Yaml::dump($meta, 2, 2));
    }

    public function removeThread(Thread $thread): void
    {
        if (self::$suppressed) {
            return;
        }

        $threadDir = $this->paths->threadDirectory($thread->thread_id);

        if (File::isDirectory($threadDir)) {
            File::deleteDirectory($threadDir);
        }
    }

    public function writeRevisions(Comment $comment): void
    {
        if (self::$suppressed) {
            return;
        }

        if ($comment->timestamp_id === null || $comment->timestamp_id === '') {
            return;
        }

        $revisions = CommentRevision::query()
            ->where('comment_id', $comment->id)
            ->orderBy('revision_number')
            ->get([
                'revision_number', 'edited_at',
                'edited_by', 'edit_reason',
            ]);

        if ($revisions->isEmpty()) {
            return;
        }

        $payload = [
            'revision' => (int) $revisions->last()->revision_number,
            'changes' => $revisions->map(fn (CommentRevision $r) => [
                'revision' => (int) $r->revision_number,
                'edited_at' => $r->edited_at->toIso8601String(),
                'edited_by' => $r->edited_by,
                'edit_reason' => $r->edit_reason,
            ])->all(),
        ];

        $directory = $this->paths->directoryFor($comment);

        $this->ensureDirectory($directory);
        $this->putFile(
            $directory.'/.revisions',
            Yaml::dump($payload, 4, 2, Yaml::DUMP_NULL_AS_TILDE),
        );
    }

    private function ensureDirectory(string $directory): void
    {
        if (! File::isDirectory($directory) && ! File::makeDirectory($directory, 0o755, recursive: true)) {
            throw new MirrorWriteException("Unable to create mirror directory: {$directory}");
        }
    }

    private function putFile(string $file, string $contents): void
    {
        if (File::put($file, $contents) === false) {
            throw new MirrorWriteException("Unable to write mirror file: {$file}");
        }
    }
}
