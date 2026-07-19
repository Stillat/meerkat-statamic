<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Concerns;

use Stillat\Meerkat\Database\CommentQueryBuilder;

trait CleansCommentData
{
    private const RESERVED_DATA_KEYS = [
        'is_removed', 'removed_at', 'removed_by', 'removed_reason',
        'user_ip', 'user_agent', 'referer',
        'timestamp_id', 'replies_count',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function cleanCommentData(array $data): array
    {
        $reserved = array_merge(CommentQueryBuilder::COLUMNS, self::RESERVED_DATA_KEYS);

        return array_filter(array_diff_key($data, array_flip($reserved)), fn($key) => ! str_ends_with((string) $key, '___preview'), ARRAY_FILTER_USE_KEY);
    }
}
