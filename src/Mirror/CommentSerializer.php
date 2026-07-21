<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Mirror;

use Stillat\Meerkat\Database\Models\Comment;
use Symfony\Component\Yaml\Yaml;

class CommentSerializer
{
    public const RESERVED_KEYS = [
        'id', 'name', 'email', 'user_ip', 'user_agent', 'referer',
        'published', 'spam', 'ham', 'checked_for_spam', 'is_deleted',
        'removed_at', 'removed_by', 'removed_reason', 'trashed', 'trashed_at',
        'authenticated_user', 'moderation_status', 'moderation_reason',
        'moderation_notes', 'moderated_by', 'moderated_at', 'comment',
        'internal_author_has_name', 'internal_author_has_email',
    ];

    public static function toString(Comment $comment): string
    {
        $frontmatter = self::frontmatter($comment);

        return "---\n".self::dumpYaml($frontmatter)."---\n".self::body($comment);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function dumpYaml(array $data): string
    {
        $lines = '';
        foreach ($data as $key => $value) {
            $lines .= $key.': '.self::renderScalar($value)."\n";
        }

        return $lines;
    }

    private static function renderScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '~';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {

            if ($value === []) {
                return '{  }';
            }

            return rtrim(Yaml::dump($value, 100, 2, Yaml::DUMP_NULL_AS_TILDE));
        }

        if (is_string($value) || $value instanceof \Stringable) {
            return "'".str_replace("'", "''", (string) $value)."'";
        }

        $encoded = json_encode($value);

        return "'".str_replace("'", "''", is_string($encoded) ? $encoded : '')."'";
    }

    /**
     * @return array<string, mixed>
     */
    private static function frontmatter(Comment $comment): array
    {
        $name = $comment->author_name;
        $email = $comment->author_email;

        $base = [
            'id' => self::timestampId($comment),
            'name' => $name ?? '',
            'email' => $email ?? '',
            'user_ip' => $comment->user_ip,
            'user_agent' => $comment->user_agent,
            'referer' => $comment->referer,
            'published' => (bool) $comment->is_published,
            'internal_author_has_name' => $name !== null && $name !== '',
            'internal_author_has_email' => $email !== null && $email !== '',
            'spam' => (bool) $comment->is_spam,
        ];

        $extras = [];
        foreach ((array) $comment->comment_data as $key => $value) {
            if (in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }
            $extras[$key] = $value;
        }

        $head = [
            'id' => $base['id'],
            'name' => $base['name'],
            'email' => $base['email'],
            'user_ip' => $base['user_ip'],
            'user_agent' => $base['user_agent'],
            'referer' => $base['referer'],
        ];

        if ($comment->author_id !== null && $comment->author_id !== '') {
            $head['authenticated_user'] = (string) $comment->author_id;
        }

        $tail = [
            'published' => $base['published'],
            'internal_author_has_name' => $base['internal_author_has_name'],
            'internal_author_has_email' => $base['internal_author_has_email'],
            'spam' => $base['spam'],

            // Compatibility fields for use with the sync command.
            'ham' => (bool) $comment->is_ham,
            'checked_for_spam' => (bool) $comment->checked_for_spam,
            'moderation_status' => $comment->moderation_status,
            'moderation_reason' => $comment->moderation_reason,
            'moderation_notes' => $comment->moderation_notes,
            'moderated_by' => $comment->moderated_by,
            'moderated_at' => $comment->moderated_at?->getTimestamp(),
        ];

        if ((bool) ($comment->is_removed ?? false)) {
            $tail['is_deleted'] = true;
            $tail['removed_at'] = $comment->removed_at?->getTimestamp();
            $tail['removed_by'] = $comment->removed_by;
            $tail['removed_reason'] = $comment->removed_reason;
        }

        if ($comment->deleted_at !== null) {
            $tail['trashed'] = true;
            $tail['trashed_at'] = $comment->deleted_at->getTimestamp();
        }

        return array_merge($head, $extras, $tail);
    }

    private static function timestampId(Comment $comment): string
    {
        if ($comment->timestamp_id !== null && $comment->timestamp_id !== '') {
            return $comment->timestamp_id;
        }

        return (string) ($comment->created_at?->getTimestamp() ?? time());
    }

    private static function body(Comment $comment): string
    {
        return (string) ($comment->comment_text ?? '');
    }
}
