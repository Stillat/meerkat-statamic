<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Testing\Factories;

use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;

class CommentFactory
{
    /** @var array<string, mixed> */
    protected array $attributes = [];

    protected static int $pathCounter = 1;

    public static function new(): self
    {
        return new self;
    }

    public function forThread(string|Thread $thread): static
    {
        return $thread instanceof Thread
            ? $this->threadId($thread->thread_id)
            : $this->threadId($thread);
    }

    public function threadId(string $threadId): static
    {
        $this->attributes['thread_id'] = $threadId;

        return $this;
    }

    public function site(string $site): static
    {
        $this->attributes['site'] = $site;

        return $this;
    }

    public function collection(string $collection): static
    {
        $this->attributes['collection'] = $collection;

        return $this;
    }

    public function published(bool $published = true): static
    {
        $this->attributes['is_published'] = $published;
        unset($this->attributes['moderation_status']);

        return $this;
    }

    public function unpublished(): static
    {
        return $this->published(false);
    }

    public function pending(): static
    {
        $this->attributes['is_published'] = false;
        $this->attributes['moderation_status'] = 'pending';

        return $this;
    }

    public function approved(): static
    {
        $this->attributes['is_published'] = true;
        $this->attributes['moderation_status'] = 'approved';

        return $this;
    }

    public function rejected(?string $reason = null): static
    {
        $this->attributes['is_published'] = false;
        $this->attributes['moderation_status'] = 'rejected';
        $this->attributes['moderation_reason'] = $reason;

        return $this;
    }

    public function spam(bool $spam = true): static
    {
        $this->attributes['is_spam'] = $spam;
        $this->attributes['checked_for_spam'] = true;

        if ($spam) {
            $this->attributes['moderation_status'] = 'spam';
        } else {
            unset($this->attributes['moderation_status']);
        }

        return $this;
    }

    public function ham(bool $ham = true): static
    {
        $this->attributes['is_ham'] = $ham;

        return $this;
    }

    public function removed(?string $reason = null, ?string $moderator = null): static
    {
        $this->attributes['is_removed'] = true;
        $this->attributes['removed_at'] = now();
        $this->attributes['removed_reason'] = $reason;
        $this->attributes['removed_by'] = $moderator;

        return $this;
    }

    public function author(string $name, ?string $email = null): static
    {
        $this->attributes['author_name'] = $name;
        $this->attributes['author_email'] = $email ?? strtolower(str_replace(' ', '.', $name)).'@example.com';

        return $this;
    }

    public function guestAuthor(string $name = 'Test Author', ?string $email = 'test@example.com'): static
    {
        $this->attributes['author_id'] = null;

        return $this->author($name, $email);
    }

    public function authorId(string $authorId): static
    {
        $this->attributes['author_id'] = $authorId;

        return $this;
    }

    public function authenticatedAuthor(string $authorId): static
    {
        return $this->authorId($authorId);
    }

    public function text(string $text): static
    {
        $this->attributes['comment_text'] = $text;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function data(array $data): static
    {
        $this->attributes['comment_data'] = $data;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function withData(array $data): static
    {
        return $this->data($data);
    }

    public function requestMetadata(
        ?string $ip = '127.0.0.1',
        ?string $userAgent = 'Meerkat Test Agent',
        ?string $referer = 'https://example.com'
    ): static {
        $this->attributes['user_ip'] = $ip;
        $this->attributes['user_agent'] = $userAgent;
        $this->attributes['referer'] = $referer;

        return $this;
    }

    public function replyTo(Comment $parent): static
    {
        $this->attributes['thread_id'] = $parent->thread_id;
        $this->attributes['site'] = $parent->site;
        $this->attributes['collection'] = $parent->collection;
        $this->attributes['parent_id'] = $parent->id;
        $this->attributes['depth'] = $parent->depth + 1;

        return $this;
    }

    public function depth(int $depth): static
    {
        $this->attributes['depth'] = $depth;

        return $this;
    }

    public function parent(?int $parentId): static
    {
        $this->attributes['parent_id'] = $parentId;

        return $this;
    }

    public function path(string $path): static
    {
        $this->attributes['path'] = $path;

        return $this;
    }

    public function visualPath(string $visualPath): static
    {
        $this->attributes['visual_path'] = $visualPath;

        return $this;
    }

    /** @param array<string, mixed> $overrides */
    public function create(array $overrides = []): Comment
    {
        $explicit = array_merge($this->attributes, $overrides);
        $attributes = array_merge($this->defaults(), $this->attributes, $overrides);

        $parentId = $attributes['parent_id'] ?? null;
        $parent = is_string($parentId) || is_int($parentId)
            ? Comment::query()->find($parentId)
            : null;

        if ($parent) {
            if (! array_key_exists('thread_id', $explicit)) {
                $attributes['thread_id'] = $parent->thread_id;
            }
            if (! array_key_exists('site', $explicit)) {
                $attributes['site'] = $parent->site;
            }
            if (! array_key_exists('collection', $explicit)) {
                $attributes['collection'] = $parent->collection;
            }
            if (! array_key_exists('depth', $explicit)) {
                $attributes['depth'] = $parent->depth + 1;
            }
        }

        Thread::query()->firstOrCreate(
            ['thread_id' => $attributes['thread_id']],
            [
                'entry_id' => $attributes['thread_id'],
                'site' => $attributes['site'],
                'collection' => $attributes['collection'],
                'cached_title' => 'Test Entry',
            ]
        );

        $segment = (string) self::$pathCounter++;
        $attributes['path'] ??= $parent ? $parent->path.'.'.$segment : $segment;
        $attributes['visual_path'] ??= $parent
            ? $parent->visual_path.'.'.str_pad($segment, 6, '0', STR_PAD_LEFT)
            : str_pad($segment, 6, '0', STR_PAD_LEFT);

        $comment = (new Comment)->forceFill($attributes);
        $comment->save();

        return $comment;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return list<Comment>
     */
    public function count(int $count, array $overrides = []): array
    {
        $comments = [];

        for ($i = 0; $i < $count; $i++) {
            $comments[] = $this->create($overrides);
        }

        return $comments;
    }

    public static function resetCounter(): void
    {
        self::$pathCounter = 1;
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [
            'thread_id' => 'test-entry-id',
            'site' => 'default',
            'collection' => 'blog',
            'is_published' => true,
            'checked_for_spam' => false,
            'is_spam' => false,
            'is_ham' => false,
            'is_removed' => false,
            'author_name' => 'Test Author',
            'author_email' => 'test@example.com',
            'depth' => 0,
            'replies_count' => 0,
            'comment_text' => 'This is a test comment.',
            'comment_data' => [],
        ];
    }
}
