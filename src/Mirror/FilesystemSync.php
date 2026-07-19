<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Mirror;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Symfony\Component\Yaml\Yaml;

class FilesystemSync
{
    /**
     * @var array<string, int>
     */
    private array $idMap = [];

    private const LEGACY_THREAD_SOFT_DELETE_PREFIX = '_';

    private const THREAD_META_FILE = '.meta';

    /** @var array<string, int> per-run counts */
    private array $stats = [
        'threads' => 0,
        'threads_resolved' => 0,
        'threads_soft_deleted' => 0,
        'comments_created' => 0,
        'comments_updated' => 0,
        'users_meta_created' => 0,
        'files_skipped' => 0,
    ];

    /** @var list<array{file: string, error: string}> */
    private array $errors = [];

    /**
     * @var array<string, array{site: ?string, collection: ?string}>
     */
    private array $threadEntryCache = [];

    /**
     * @var array<string, true>
     */
    private array $userMetaCache = [];

    public function __construct(
        private readonly string $root,
        private readonly ?ThreadMetricsManager $metrics = null,
    ) {}

    /**
     * @return array{stats: array<string, int>, errors: list<array{file: string, error: string}>}
     */
    public function run(): array
    {
        if (! File::isDirectory($this->root)) {
            return [
                'stats' => $this->stats,
                'errors' => [['file' => $this->root, 'error' => 'Mirror root does not exist']],
            ];
        }

        MirrorWriter::suppress(function () {
            foreach (File::directories($this->root) as $threadDir) {
                if (is_string($threadDir)) {
                    $this->syncThread($threadDir);
                }
            }
        });

        $touchedThreadIds = array_unique(array_map(
            fn ($k) => explode(':', $k, 2)[0],
            array_keys($this->idMap),
        ));

        foreach ($touchedThreadIds as $threadId) {
            $this->recomputeRepliesCounts($threadId);
        }

        if ($this->metrics instanceof ThreadMetricsManager) {
            foreach ($touchedThreadIds as $threadId) {
                $this->metrics->recalculateThread($threadId);
            }
        }

        return ['stats' => $this->stats, 'errors' => $this->errors];
    }

    private function recomputeRepliesCounts(string $threadId): void
    {
        Comment::withoutTimestamps(function () use ($threadId) {

            Comment::query()
                ->withoutGlobalScopes()
                ->where('thread_id', $threadId)
                ->update(['replies_count' => 0]);

            $rows = Comment::query()
                ->withoutGlobalScopes()
                ->where('thread_id', $threadId)
                ->where('is_published', true)
                ->whereNotNull('parent_id')
                ->selectRaw('parent_id, COUNT(*) as reply_total')
                ->groupBy('parent_id')
                ->get();

            foreach ($rows as $row) {
                $replyTotal = $this->integerValue($row->getAttribute('reply_total'));

                Comment::query()
                    ->withoutGlobalScopes()
                    ->where('comments.id', $row->getAttribute('parent_id'))
                    ->update(['replies_count' => $replyTotal]);
            }
        });
    }

    private function syncThread(string $threadDir): void
    {
        $rawName = basename($threadDir);

        $legacyPrefixed = str_starts_with($rawName, self::LEGACY_THREAD_SOFT_DELETE_PREFIX);
        $threadId = $legacyPrefixed ? ltrim($rawName, self::LEGACY_THREAD_SOFT_DELETE_PREFIX) : $rawName;

        if ($legacyPrefixed) {
            $cleanDir = dirname($threadDir).'/'.$threadId;

            if (File::isDirectory($cleanDir)) {

                $this->errors[] = [
                    'file' => $threadDir,
                    'error' => "both legacy `_{$threadId}` and `{$threadId}` directories exist; not renaming",
                ];
            } elseif (rename($threadDir, $cleanDir)) {
                $threadDir = $cleanDir;
            } else {
                $this->errors[] = [
                    'file' => $threadDir,
                    'error' => "failed to rename legacy `_{$threadId}` directory to `{$threadId}`",
                ];
            }
        }

        $meta = $this->readThreadMeta($threadDir);
        $metaTrashed = $meta !== null && array_key_exists('trashed', $meta) ? (bool) $meta['trashed'] : null;
        $metaCreated = $meta !== null && isset($meta['created']) && is_numeric($meta['created'])
            ? Carbon::createFromTimestamp((int) $meta['created'])
            : null;

        $shouldBeTrashed = $legacyPrefixed || $metaTrashed === true;

        $entry = $this->resolveEntry($threadId);

        $thread = Thread::query()->withTrashed()->where('thread_id', $threadId)->first();

        if ($entry instanceof StatamicEntry) {
            $this->stats['threads_resolved']++;
            $site = $this->entrySite($entry);
            $collection = $this->entryCollection($entry);
            $this->threadEntryCache[$threadId] = [
                'site' => $site,
                'collection' => $collection,
            ];

            $title = $this->nullableScalarString($entry->get('title')) ?? $threadId;

            $attrs = [
                'thread_id' => $threadId,
                'entry_id' => $this->nullableScalarString($entry->id()),
                'site' => $site,
                'collection' => $collection,
                'cached_title' => $title,
            ];

            if ($thread === null) {
                $thread = Thread::query()->create($attrs);
                $this->stampThreadTimestamps($thread, $metaCreated);
            } else {
                $thread->fill($attrs);
                $thread->deleted_at = null;
                $thread->save();
            }
        } elseif ($thread === null) {

            $thread = Thread::query()->create([
                'thread_id' => $threadId,
                'cached_title' => $threadId,
            ]);
            $this->stampThreadTimestamps($thread, $metaCreated);
        } elseif ($thread->deleted_at !== null && ! $shouldBeTrashed) {

            $thread->deleted_at = null;
            $thread->save();
        }

        $this->stats['threads']++;

        if ($shouldBeTrashed && $thread->deleted_at === null) {
            $thread->delete();
            $this->stats['threads_soft_deleted']++;
        } elseif (! $shouldBeTrashed && $thread->deleted_at !== null) {

            $thread->deleted_at = null;
            $thread->save();
        }

        foreach (File::directories($threadDir) as $rootCommentDir) {
            if (is_string($rootCommentDir)) {
                $this->syncComment($rootCommentDir, $threadId, parentId: null, depth: 0);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readThreadMeta(string $threadDir): ?array
    {
        $metaFile = $threadDir.'/'.self::THREAD_META_FILE;

        if (! File::exists($metaFile)) {
            return null;
        }

        try {
            $meta = Yaml::parse(File::get($metaFile));
        } catch (\Throwable $e) {
            $this->errors[] = ['file' => $metaFile, 'error' => 'unreadable thread .meta: '.$e->getMessage()];

            return null;
        }

        if (! is_array($meta)) {
            return null;
        }

        return array_filter($meta, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    private function syncComment(string $commentDir, string $threadId, ?int $parentId, int $depth): void
    {
        $file = $commentDir.'/comment.md';

        if (! File::exists($file)) {
            $this->stats['files_skipped']++;
            $this->errors[] = ['file' => $file, 'error' => 'comment.md missing'];

            return;
        }

        try {
            $parsed = CommentParser::parse(File::get($file));
        } catch (\Throwable $e) {
            $this->stats['files_skipped']++;
            $this->errors[] = ['file' => $file, 'error' => $e->getMessage()];

            return;
        }

        $row = $this->hydrate($parsed['frontmatter'], $parsed['body'], $threadId, $parentId, $depth, basename($commentDir));

        if (! $row instanceof Comment) {
            return;
        }

        $this->idMap[$threadId.':'.$row->timestamp_id] = $row->id;

        $repliesDir = $commentDir.'/replies';

        if (File::isDirectory($repliesDir)) {
            foreach (File::directories($repliesDir) as $childDir) {
                if (is_string($childDir)) {
                    $this->syncComment($childDir, $threadId, $row->id, $depth + 1);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function hydrate(array $frontmatter, string $body, string $threadId, ?int $parentId, int $depth, string $directoryName): ?Comment
    {
        $timestampId = $this->nullableScalarString($frontmatter['id'] ?? null) ?? $directoryName;

        if ($timestampId === '' || ! is_numeric($timestampId)) {
            $this->stats['files_skipped']++;
            $this->errors[] = ['file' => $directoryName, 'error' => 'comment id must be a numeric timestamp'];

            return null;
        }

        $createdAt = Carbon::createFromTimestamp((int) $timestampId);

        $existing = Comment::query()
            ->withTrashed()
            ->where('comments.thread_id', $threadId)
            ->where('comments.timestamp_id', $timestampId)
            ->first();

        $isNew = $existing === null;
        $comment = $existing ?? new Comment;

        if ($existing !== null && $existing->deleted_at !== null) {
            $comment->deleted_at = null;
        }

        $threadMeta = $this->threadEntryCache[$threadId] ?? ['site' => null, 'collection' => null];

        $comment->thread_id = $threadId;
        $comment->timestamp_id = $timestampId;
        $comment->parent_id = $parentId;
        $comment->depth = $depth;

        $comment->site = $threadMeta['site'] ?? $comment->site ?? '';
        $comment->collection = $threadMeta['collection'] ?? $comment->collection ?? '';
        $comment->author_name = $this->nonEmptyScalarString($frontmatter['name'] ?? null);
        $comment->author_email = $this->nonEmptyScalarString($frontmatter['email'] ?? null);
        $comment->user_ip = $this->nullableScalarString($frontmatter['user_ip'] ?? null);
        $comment->user_agent = $this->nullableScalarString($frontmatter['user_agent'] ?? null);
        $comment->referer = $this->nullableScalarString($frontmatter['referer'] ?? null);
        $comment->is_published = (bool) ($frontmatter['published'] ?? true);
        $comment->is_spam = (bool) ($frontmatter['spam'] ?? false);

        $comment->is_ham = array_key_exists('ham', $frontmatter)
            ? (bool) $frontmatter['ham']
            : (array_key_exists('is_ham', $frontmatter) // legacy key
                ? (bool) $frontmatter['is_ham']
                : (bool) ($comment->is_ham ?? false));
        $comment->checked_for_spam = array_key_exists('checked_for_spam', $frontmatter)
            ? (bool) $frontmatter['checked_for_spam']
            : (bool) ($comment->checked_for_spam ?? false);

        if (array_key_exists('moderation_status', $frontmatter) && $frontmatter['moderation_status'] !== null) {
            $moderationStatus = $this->nullableScalarString($frontmatter['moderation_status']);

            if ($moderationStatus !== null) {
                $comment->moderation_status = $moderationStatus;
            }
        }
        if (array_key_exists('moderation_reason', $frontmatter)) {
            $comment->moderation_reason = $this->nullableScalarString($frontmatter['moderation_reason']);
        }
        if (array_key_exists('moderation_notes', $frontmatter)) {
            $comment->moderation_notes = $this->nullableScalarString($frontmatter['moderation_notes']);
        }
        if (array_key_exists('moderated_by', $frontmatter)) {
            $comment->moderated_by = $this->nullableScalarString($frontmatter['moderated_by']);
        }
        if (array_key_exists('moderated_at', $frontmatter) && is_numeric($frontmatter['moderated_at'])) {
            $comment->moderated_at = Carbon::createFromTimestamp((int) $frontmatter['moderated_at']);
        }

        if (array_key_exists('is_deleted', $frontmatter)) {
            $isDeleted = (bool) $frontmatter['is_deleted'];
            $comment->is_removed = $isDeleted;

            if ($isDeleted) {
                if (array_key_exists('removed_at', $frontmatter) && is_numeric($frontmatter['removed_at'])) {
                    $comment->removed_at = Carbon::createFromTimestamp((int) $frontmatter['removed_at']);
                } elseif ($comment->removed_at === null) {
                    $comment->removed_at = $createdAt;
                }

                if (array_key_exists('removed_by', $frontmatter)) {
                    $comment->removed_by = $this->nullableScalarString($frontmatter['removed_by']);
                }
                if (array_key_exists('removed_reason', $frontmatter)) {
                    $comment->removed_reason = $this->nullableScalarString($frontmatter['removed_reason']);
                }
            }
        }

        $authId = null;
        if (array_key_exists('authenticated_user', $frontmatter)
            && $frontmatter['authenticated_user'] !== ''
            && $frontmatter['authenticated_user'] !== null) {
            $authId = $this->nullableScalarString($frontmatter['authenticated_user']);

            if ($authId === null) {
                $this->errors[] = ['file' => $directoryName, 'error' => 'authenticated_user must be a scalar identifier'];

                return null;
            }
            $comment->author_id = $authId;

            $this->ensureUserMeta(
                $authId,
                $comment->author_name,
                $comment->author_email,
            );
        }

        $comment->comment_text = $body;

        $extras = $this->extras($frontmatter);
        $extras['comment'] = $body;
        $comment->comment_data = $extras;

        if ($isNew) {
            $comment->created_at = $createdAt;
            $comment->updated_at = $createdAt;

            if ($comment->is_published && $comment->published_at === null) {
                $comment->published_at = $createdAt;
            }
            if ($comment->last_activity_at === null) {
                $comment->last_activity_at = $createdAt;
            }
        }

        Comment::withoutTimestamps(function () use ($comment) {
            $comment->saveQuietly();
        });

        $parent = $parentId !== null
            ? Comment::query()->withTrashed()->where('comments.id', $parentId)->first()
            : null;

        $comment->materializePath($parent);

        Comment::withoutTimestamps(function () use ($comment) {
            $comment->saveQuietly();
        });

        if ($isNew) {
            $this->stats['comments_created']++;
        } else {
            $this->stats['comments_updated']++;
        }

        return $comment;
    }

    private function stampThreadTimestamps(Thread $thread, ?Carbon $metaCreated): void
    {
        if (! $metaCreated instanceof Carbon) {
            return;
        }

        Thread::query()
            ->where('thread_id', $thread->thread_id)
            ->update([
                'created_at' => $metaCreated,
                'updated_at' => $metaCreated,
            ]);

        $thread->created_at = $metaCreated;
        $thread->updated_at = $metaCreated;
    }

    private function resolveEntry(string $threadId): ?StatamicEntry
    {
        try {
            $entry = Entry::find($threadId);

            return $entry instanceof StatamicEntry ? $entry : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureUserMeta(string $userId, ?string $fallbackName, ?string $fallbackEmail): void
    {
        if (isset($this->userMetaCache[$userId])) {
            return;
        }

        $this->userMetaCache[$userId] = true;

        if (UserMeta::query()->where('user_id', $userId)->exists()) {
            return;
        }

        $name = $fallbackName;
        $email = $fallbackEmail;

        try {
            $user = User::find($userId);
            if ($user !== null) {
                $name = $this->nullableScalarString($user->get('name'))
                    ?? $this->nullableScalarString($user->name())
                    ?? $name;
                $email = $this->nullableScalarString($user->email()) ?? $email;
            }
        } catch (\Throwable) {
            // Retain the identity values stored in the mirror.
        }

        UserMeta::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
        ]);

        $this->stats['users_meta_created']++;
    }

    private function entrySite(StatamicEntry $entry): ?string
    {
        $site = $entry->site();

        return $this->nullableScalarString($site->handle());
    }

    private function entryCollection(StatamicEntry $entry): ?string
    {
        $collection = $entry->collection();

        return $this->nullableScalarString($collection->handle());
    }

    private function nullableScalarString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) || is_bool($value) ? (string) $value : null;
    }

    private function nonEmptyScalarString(mixed $value): ?string
    {
        $normalized = $this->nullableScalarString($value);

        return $normalized === null || $normalized === '' ? null : $normalized;
    }

    private function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return array<string, mixed>
     */
    private function extras(array $frontmatter): array
    {

        $reserved = [
            'id', 'name', 'email', 'user_ip', 'user_agent', 'referer',
            'published', 'spam', 'is_deleted', 'removed_at', 'removed_by', 'removed_reason',
            'authenticated_user',
            'internal_author_has_name', 'internal_author_has_email',
            'comment',
        ];

        $extras = [];
        foreach ($frontmatter as $key => $value) {
            if (in_array($key, $reserved, true)) {
                continue;
            }
            $extras[$key] = $value;
        }

        return $extras;
    }
}
