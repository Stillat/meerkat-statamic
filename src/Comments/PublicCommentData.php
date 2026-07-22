<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Comments;

use Statamic\Fields\Value;
use Stillat\Meerkat\Database\Models\Comment;

class PublicCommentData
{
    /** @var list<string> */
    public const GUARDED_KEYS = [
        'user_ip', 'user_agent', 'referer',
        'email', 'author_email',
        'moderation_status', 'moderation_reason', 'moderation_notes',
        'moderated_by', 'moderated_at',
        'removed_by', 'removed_reason', 'removed_at',
        'checked_for_spam',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function guard(array $data): array
    {
        foreach (self::GUARDED_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $raw = $data[$key];

            $data[$key] = new GuardedValue(
                fn (): mixed => $raw instanceof Value ? $raw->value() : $raw,
                $key,
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function privilegedFields(Comment $comment): array
    {
        return [
            'is_published' => (bool) $comment->is_published,
            'is_spam' => (bool) $comment->is_spam,
            'is_ham' => (bool) $comment->is_ham,
            'is_removed' => (bool) $comment->is_removed,
            'removed_at' => $comment->removed_at?->toIso8601ZuluString('millisecond'),
            'removed_by' => $comment->removed_by,
            'removed_reason' => $comment->removed_reason,
            'checked_for_spam' => (bool) $comment->checked_for_spam,
            'moderation_status' => $comment->moderation_status,
            'moderation_reason' => $comment->moderation_reason,
            'moderation_notes' => $comment->moderation_notes,
            'moderated_by' => $comment->moderated_by,
            'moderated_at' => $comment->moderated_at?->toIso8601ZuluString('millisecond'),
            'published_at' => $comment->published_at?->toIso8601ZuluString('millisecond'),
            'last_activity_at' => $comment->last_activity_at?->toIso8601ZuluString('millisecond'),
            'updated_at' => $comment->updated_at?->toIso8601ZuluString('millisecond'),
            'user_ip' => $comment->user_ip,
            'user_agent' => $comment->user_agent,
            'referer' => $comment->referer,
        ];
    }
}
