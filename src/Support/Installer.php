<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Statamic\Facades\Blueprint;
use Stillat\Meerkat\Mirror\Mirror;
use Throwable;

class Installer
{
    /**
     * @var list<string>
     */
    public const REQUIRED_TABLES = [
        'comments',
        'threads',
        'users_meta',
        'thread_metrics',
        'comment_moderation_audits',
        'comment_revisions',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const REQUIRED_COLUMNS = [
        'comments' => [
            'id', 'thread_id', 'timestamp_id', 'author_id', 'site', 'user_ip', 'user_agent', 'referer',
            'collection', 'is_published', 'checked_for_spam', 'is_spam', 'is_ham', 'is_removed',
            'removed_at', 'removed_by', 'removed_reason', 'moderation_status', 'moderation_reason',
            'moderation_notes', 'moderated_at', 'moderated_by', 'author_name', 'author_email', 'depth',
            'parent_id', 'replies_count', 'comment_data', 'comment_text', 'path', 'visual_path',
            'last_activity_at', 'published_at', 'created_at', 'updated_at', 'deleted_at',
        ],
        'threads' => ['id', 'thread_id', 'entry_id', 'site', 'collection', 'cached_title', 'created_at', 'updated_at', 'deleted_at'],
        'users_meta' => ['id', 'user_id', 'email', 'name', 'created_at', 'updated_at', 'deleted_at'],
        'thread_metrics' => [
            'id', 'thread_id', 'site', 'collection', 'total_comments', 'published_comments',
            'pending_comments', 'spam_comments', 'root_comments', 'reply_comments', 'participants',
            'max_depth', 'first_comment_at', 'last_activity_at', 'metadata', 'created_at', 'updated_at',
        ],
        'comment_moderation_audits' => ['id', 'comment_id', 'actor_id', 'action', 'details', 'created_at', 'updated_at'],
        'comment_revisions' => [
            'id', 'comment_id', 'revision_number', 'comment_text', 'comment_data', 'edited_by',
            'edit_reason', 'edited_at', 'created_at', 'updated_at',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const REQUIRED_INDEXES = [
        'comments' => [
            'meerkat_comments_thread_timestamp_unique',
            'meerkat_comments_thread_publish_parent_idx',
            'meerkat_comments_thread_created_idx',
            'meerkat_comments_author_created_idx',
            'meerkat_comments_site_collection_created_idx',
            'meerkat_comments_thread_moderation_idx',
        ],
        'threads' => ['meerkat_threads_thread_id_unique'],
        'users_meta' => ['meerkat_users_meta_user_id_unique'],
    ];

    /**
     * @return array<string, string|true>
     */
    public static function statuses(): array
    {
        return [
            'connection' => self::checkConnection(),
            'blueprint' => self::checkBlueprint(),
            'tables' => self::checkTables(),
            'columns' => self::checkColumns(),
            'indexes' => self::checkIndexes(),
            'mirror' => self::checkMirror(),
            'queue' => self::checkQueue(),
        ];
    }

    public static function checkMirror(): string|true
    {
        if (config('meerkat.mirror.enabled', true) !== true) {
            return true;
        }

        $root = Mirror::root();

        if (file_exists($root) && ! is_dir($root)) {
            return "Mirror path [{$root}] exists but is not a directory";
        }

        if (is_dir($root)) {
            return is_writable($root) ? true : "Mirror path [{$root}] is not writable";
        }

        // The mirror writer creates missing directories recursively, so the
        // nearest existing ancestor is what must be writable.
        $path = dirname($root);

        while (! is_dir($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }

        return is_dir($path) && is_writable($path)
            ? true
            : "Mirror path [{$root}] cannot be created; no writable parent directory exists";
    }

    public static function checkQueue(): string|true
    {
        $connection = config('meerkat.jobs.connection');

        if (! is_string($connection) || $connection === '') {
            return 'meerkat.jobs.connection is not set';
        }

        return config("queue.connections.{$connection}") !== null
            ? true
            : "Queue connection [{$connection}] is not defined in config/queue.php";
    }

    public static function connectionName(): string
    {
        $connection = self::configuredConnection();

        if ($connection !== null) {
            return $connection;
        }

        $default = config('database.default');

        return is_string($default) ? $default : '';
    }

    public static function checkConnection(): string|true
    {
        try {
            DB::connection(self::configuredConnection())->getPdo();

            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public static function checkBlueprint(): string|true
    {
        $handle = config('meerkat.form.blueprint', 'meerkat');

        if (! is_string($handle) || $handle === '') {
            return 'The configured blueprint handle must be a non-empty string';
        }

        return Blueprint::find($handle) === null
            ? "Blueprint [{$handle}] is not registered"
            : true;
    }

    public static function checkTables(): string|true
    {
        try {
            $schema = self::schema();

            $missing = array_filter(
                self::REQUIRED_TABLES,
                fn (string $table) => ! $schema->hasTable($table),
            );

            if ($missing !== []) {
                return 'Missing tables: '.implode(', ', $missing);
            }

            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public static function checkColumns(): string|true
    {
        try {
            $schema = self::schema();
            $missing = [];

            foreach (self::REQUIRED_COLUMNS as $table => $columns) {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        $missing[] = "{$table}.{$column}";
                    }
                }
            }

            return $missing === [] ? true : 'Missing columns: '.implode(', ', $missing);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public static function checkIndexes(): string|true
    {
        try {
            $schema = self::schema();
            $missing = [];

            foreach (self::REQUIRED_INDEXES as $table => $required) {
                if (! $schema->hasTable($table)) {
                    continue;
                }

                $indexes = array_column($schema->getIndexes($table), 'name');

                foreach ($required as $index) {
                    if (! in_array($index, $indexes, true)) {
                        $missing[] = "{$table}.{$index}";
                    }
                }
            }

            return $missing === [] ? true : 'Missing indexes: '.implode(', ', $missing);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function schema(): SchemaBuilder
    {
        $connection = self::configuredConnection();
        $schema = $connection !== null ? Schema::connection($connection) : Schema::getFacadeRoot();

        if (! $schema instanceof SchemaBuilder) {
            throw new RuntimeException('Unable to resolve the database schema builder.');
        }

        return $schema;
    }

    private static function configuredConnection(): ?string
    {
        $connection = config('meerkat.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
