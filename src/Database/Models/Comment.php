<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Data\Augmentable;
use Statamic\Contracts\Data\Augmented;
use Statamic\Contracts\Data\BulkAugmentable;
use Statamic\Contracts\GraphQL\ResolvesValues as ResolvesValuesContract;
use Statamic\GraphQL\ResolvesValues;
use Statamic\Hooks\Payload;
use Statamic\Support\Traits\Hookable;
use Stillat\Meerkat\Concerns\CleansCommentData;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Data\AugmentedComment;
use Stillat\Meerkat\Data\HasAugmentedInstance;
use Stillat\Meerkat\Data\RetrievesDataValue;
use Stillat\Meerkat\Database\Models\Scopes\AuthorDetailsScope;
use Stillat\Meerkat\Events\CommentSaved;
use Stillat\Meerkat\Extractors\AuthorExtractor;
use Stillat\Meerkat\Mirror\Mirror;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Support\Identifiers;

/**
 * @property int $id
 * @property string $thread_id
 * @property string|null $timestamp_id
 * @property string|null $author_id
 * @property string $site
 * @property string|null $user_ip
 * @property string|null $user_agent
 * @property string|null $referer
 * @property string $collection
 * @property bool $is_published
 * @property bool $checked_for_spam
 * @property bool $is_spam
 * @property bool $is_ham
 * @property bool $is_removed
 * @property Carbon|null $removed_at
 * @property string|null $removed_by
 * @property string|null $removed_reason
 * @property string $name
 * @property string $email
 * @property string|null $author_name
 * @property string|null $author_email
 * @property int $depth
 * @property int|null $parent_id
 * @property int $replies_count
 * @property array<string, mixed> $comment_data
 * @property string $comment_text
 * @property string|null $path
 * @property string|null $visual_path
 * @property string $moderation_status
 * @property string|null $moderation_reason
 * @property string|null $moderation_notes
 * @property string|null $moderated_by
 * @property Carbon|null $moderated_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property array<int, Comment> $ancestors
 * @property Comment|null $parent
 * @property EloquentCollection<int, Comment> $children
 * @property EloquentCollection<int, Comment> $allChildren
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static> onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static> withoutTrashed()
 * @method bool restore()
 * @method bool|null forceDelete()
 * @method bool trashed()
 *
 * @mixin Builder<static>
 */
#[ScopedBy([AuthorDetailsScope::class])]
class Comment extends Model implements Augmentable, BulkAugmentable, ResolvesValuesContract, RetrievesDataValue
{
    use CleansCommentData,
        GetsMeerkatConfig,
        HasAugmentedInstance,
        HasTimestamps,
        Hookable,
        ResolvesValues,
        SoftDeletes;

    protected $fillable = [
        'thread_id',
        'timestamp_id',
        'is_published',
        'checked_for_spam',
        'is_spam',
        'is_ham',
        'is_removed',
        'removed_at',
        'removed_by',
        'removed_reason',
        'replies_count',
        'moderation_status',
        'moderation_reason',
        'moderation_notes',
        'moderated_at',
        'moderated_by',
        'last_activity_at',
        'published_at',
    ];

    /** @var list<string> */
    private array $cleanupDataFields = [
        'thread_id', 'author_id', 'created_at', 'id',
        'name', 'is_published', 'checked_for_spam',
        'is_spam', 'is_ham',
    ];

    protected $table = 'comments';

    public bool $skipHooks = false;

    protected static function booted()
    {
        static::created(function (Comment $comment) {
            if ($comment->parent_id && self::countsPublicly($comment)) {
                self::where('comments.id', $comment->parent_id)
                    ->increment('replies_count');
            }

            self::where('comments.id', $comment->id)->update([
                'last_activity_at' => $comment->created_at ?? now(),
                'published_at' => $comment->is_published ? ($comment->published_at ?? ($comment->created_at ?? now())) : $comment->published_at,
            ]);

            if ($comment->parent_id) {
                self::where('comments.id', $comment->parent_id)
                    ->update(['last_activity_at' => $comment->created_at ?? now()]);
            }
        });

        static::updated(function (Comment $comment) {
            $counted = self::countsPublicly($comment, original: true);
            $counts = self::countsPublicly($comment);

            if ($comment->parent_id && $counted !== $counts) {
                self::where('comments.id', $comment->parent_id)
                    ->{$counts ? 'increment' : 'decrement'}('replies_count');
            }

            if ($comment->isDirty('last_activity_at') && $comment->parent_id) {
                self::where('comments.id', $comment->parent_id)
                    ->update(['last_activity_at' => $comment->last_activity_at]);
            }
        });

        static::deleted(function (Comment $comment) {
            $alreadyCountedOut = $comment->isForceDeleting() && $comment->getOriginal('deleted_at') !== null;

            if ($comment->parent_id && ! $alreadyCountedOut && self::countsPublicly($comment)) {
                self::where('comments.id', $comment->parent_id)
                    ->decrement('replies_count');
            }

            self::touchThreadMetric($comment->thread_id);

            if (! $comment->isForceDeleting()) {
                Mirror::handleSaved($comment);
            }
        });

        static::restored(function (Comment $comment) {
            if ($comment->parent_id && self::countsPublicly($comment)) {
                self::where('comments.id', $comment->parent_id)
                    ->increment('replies_count');
            }

            self::touchThreadMetric($comment->thread_id);
        });

        static::forceDeleting(function (Comment $comment) {
            $comment->children()->withTrashed()->get()->each->forceDelete();

            CommentRevision::query()
                ->where('comment_id', $comment->id)
                ->delete();

            CommentModerationAudit::query()
                ->where('comment_id', $comment->id)
                ->delete();
        });

        static::saved(function (Comment $comment) {
            Mirror::handleSaved($comment);

            if (! $comment->skipHooks) {
                CommentSaved::dispatch($comment);
            }
        });

        static::forceDeleted(function (Comment $comment) {
            Mirror::handleForceDeleted($comment);
        });
    }

    private static function countsPublicly(Comment $comment, bool $original = false): bool
    {
        if ($original) {
            return (bool) $comment->getOriginal('is_published')
                && ! (bool) $comment->getOriginal('is_removed')
                && ! (bool) $comment->getOriginal('is_spam');
        }

        return $comment->is_published && ! $comment->is_removed && ! $comment->is_spam;
    }

    private static function touchThreadMetric(?string $threadId): void
    {
        if (! $threadId) {
            return;
        }

        try {
            app(ThreadMetricsManager::class)
                ->recalculateThread($threadId);
        } catch (\Throwable $e) {
            Log::warning('Meerkat: Failed to recalculate thread metrics.', [
                'thread_id' => $threadId,
                'exception' => $e,
            ]);
        }
    }

    public function getConnectionName()
    {
        return $this->getDatabaseConnection();
    }

    protected function casts()
    {
        return [
            'comment_data' => 'array',
            'is_published' => 'boolean',
            'checked_for_spam' => 'boolean',
            'is_spam' => 'boolean',
            'is_ham' => 'boolean',
            'is_removed' => 'boolean',
            'removed_at' => 'datetime',
            'moderated_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function newAugmentedInstance(): Augmented
    {
        return new AugmentedComment(
            $this,
            $this->getBlueprint(),
        );
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')
            ->orderBy('comments.created_at')
            ->orderBy('comments.id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    /**
     * @return BelongsTo<UserMeta, $this>
     */
    public function userMeta(): BelongsTo
    {
        return $this->belongsTo(UserMeta::class, 'author_id', 'user_id');
    }

    /**
     * @return HasMany<CommentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class, 'comment_id')
            ->orderByDesc('revision_number');
    }

    public function latestRevision(): ?CommentRevision
    {
        return $this->revisions()->first();
    }

    public function revision(int $number): ?CommentRevision
    {
        return $this->revisions()->where('revision_number', $number)->first();
    }

    public function resolvedName(): string
    {
        if (is_string($scopeName = $this->getAttribute('name')) && $scopeName !== '') {
            return $scopeName;
        }

        if ($this->author_name !== null && $this->author_name !== '') {
            return $this->author_name;
        }

        if ($this->author_id !== null && ($metaName = $this->userMeta?->name) !== null && $metaName !== '') {
            return $metaName;
        }

        return $this->getDefaultAuthorName();
    }

    public function resolvedEmail(): string
    {
        if (is_string($scopeEmail = $this->getAttribute('email')) && $scopeEmail !== '') {
            return $scopeEmail;
        }

        if ($this->author_email !== null && $this->author_email !== '') {
            return $this->author_email;
        }

        if ($this->author_id !== null && ($metaEmail = $this->userMeta?->email) !== null && $metaEmail !== '') {
            return $metaEmail;
        }

        return $this->getDefaultAuthorEmail();
    }

    /**
     * The resolved email, or null when it falls back to the anonymous placeholder.
     */
    public function publicEmail(): ?string
    {
        $email = $this->resolvedEmail();

        return $email === $this->getDefaultAuthorEmail() ? null : $email;
    }

    public function gravatarUrl(int $size = 80): string
    {
        $hash = md5(strtolower(trim($this->resolvedEmail())));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=mp";
    }

    public function stampModeration(string $status): void
    {
        $this->moderation_status = $status;
        $this->moderated_at = now();
        $actorId = auth()->id();
        $this->moderated_by = is_string($actorId) || is_int($actorId) ? (string) $actorId : null;
    }

    /**
     * Build this comment's path/visual_path from its parent (or null for a root).
     */
    public function materializePath(?Comment $parent): void
    {
        $visualId = Identifiers::visualId($this->id);

        if (! $parent instanceof Comment) {
            $this->path = (string) $this->id;
            $this->visual_path = $visualId;

            return;
        }

        $this->path = $parent->path.'.'.$this->id;
        $this->visual_path = $parent->visual_path.'.'.$visualId;
    }

    /** @return Builder<Comment> */
    public function descendants(): Builder
    {
        return self::where('path', 'like', $this->path.'.%');
    }

    public function hasDataValue(string $key): bool
    {
        return array_key_exists($key, $this->comment_data ?? []);
    }

    public function getDataValue(string $key): mixed
    {
        return ($this->comment_data ?? [])[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function toDataArray(): array
    {
        $data = $this->comment_data ?? [];

        foreach ($this->cleanupDataFields as $field) {
            unset($data[$field]);
        }

        return array_merge($data, [
            'id' => $this->id,
            'comment_text' => $this->comment_text,
            'author_name' => $this->author_name,
            'author_email' => $this->author_email,
            'name' => $this->name,
            'email' => $this->email,
            'is_published' => $this->is_published,
            'checked_for_spam' => $this->checked_for_spam,
            'is_spam' => $this->is_spam,
            'is_ham' => $this->is_ham,
            'moderation_status' => $this->moderation_status,
            'moderation_reason' => $this->moderation_reason,
            'moderation_notes' => $this->moderation_notes,
            'moderated_at' => $this->moderated_at,
            'moderated_by' => $this->moderated_by,
            'created_at' => $this->created_at,
        ]);
    }

    /** @return array<string, mixed> */
    public function toExportArray(): array
    {
        $data = $this->comment_data ?? [];

        foreach ($this->cleanupDataFields as $field) {
            unset($data[$field]);
        }

        return [
            'id' => $this->id,
            'thread_id' => $this->thread_id,
            'parent_id' => $this->parent_id,

            'depth' => $this->depth,
            'path' => $this->path,
            'visual_path' => $this->visual_path,
            'timestamp_id' => $this->timestamp_id,
            'replies_count' => $this->replies_count,
            'comment_text' => $this->comment_text,
            'author_id' => $this->author_id,
            'author_name' => $this->author_name,
            'author_email' => $this->author_email,
            'is_published' => (bool) $this->is_published,
            'is_spam' => (bool) $this->is_spam,
            'is_ham' => (bool) $this->is_ham,
            'checked_for_spam' => (bool) $this->checked_for_spam,
            'moderation_status' => $this->moderation_status,
            'moderation_reason' => $this->moderation_reason,
            'moderation_notes' => $this->moderation_notes,
            'moderated_at' => $this->moderated_at?->toIso8601String(),
            'moderated_by' => $this->moderated_by,

            'is_removed' => (bool) $this->is_removed,
            'removed_at' => $this->removed_at?->toIso8601String(),
            'removed_by' => $this->removed_by,
            'removed_reason' => $this->removed_reason,
            'site' => $this->site,
            'collection' => $this->collection,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'published_at' => $this->published_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'comment_data' => $data,
        ];
    }

    public function save(array $options = [])
    {
        $wasCreated = ! $this->exists;

        if (! $this->skipHooks) {
            $this->runModelHooksWithAliases('saving', 'before-saving-comment-model', [
                'attributes' => $this->getAttributes(),
                'model' => $this,
                'is_creating' => $wasCreated,
            ]);
        }

        unset(
            $this['name'],
            $this['email'],
        );

        $this->comment_data = $this->cleanCommentData($this->comment_data);

        $this->author_email = AuthorExtractor::normalizeEmail($this->author_email);
        $this->author_name = AuthorExtractor::normalizeName($this->author_name);

        if ($this->author_id === null) {

            $defaultName = mb_strtolower(trim($this->getDefaultAuthorName()));
            $defaultEmail = mb_strtolower(trim($this->getDefaultAuthorEmail()));

            if (mb_strtolower(trim($this->author_name ?? '')) === $defaultName) {
                $this->author_name = null;
            }

            if (mb_strtolower(trim($this->author_email ?? '')) === $defaultEmail) {
                $this->author_email = null;
            }
        } else {
            $this->author_name = $this->author_email = null;
        }

        if (! $this->moderation_status) {
            if ($this->is_spam) {
                $this->moderation_status = 'spam';
            } elseif ($this->is_published) {
                $this->moderation_status = 'approved';
            } else {
                $this->moderation_status = 'pending';
            }
        }

        if ($this->is_published && $this->published_at === null) {
            $this->published_at = now();
        }

        if ($this->last_activity_at === null) {
            $this->last_activity_at = $this->created_at ?? now();
        }

        $result = parent::save($options);

        if (! $this->skipHooks) {
            $this->runModelHooksWithAliases('saved', 'after-saved-comment-model', [
                'model' => $this,
                'was_created' => $wasCreated,
            ]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runModelHooksWithAliases(string $name, string $legacyName, array $payload): Payload
    {
        /** @var Payload $result */
        $result = $this->runHooksWith($legacyName, $payload);

        /** @var Payload $result */
        $result = $this->runHooks($name, $result);

        return $result;
    }

    public function getBulkAugmentationReferenceKey(): ?string
    {
        return static::class.$this->id;
    }
}
