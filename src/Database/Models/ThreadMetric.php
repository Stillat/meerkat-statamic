<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;

/**
 * @property int $id
 * @property string $thread_id
 * @property string|null $site
 * @property string|null $collection
 * @property int $total_comments
 * @property int $published_comments
 * @property int $pending_comments
 * @property int $spam_comments
 * @property int $root_comments
 * @property int $reply_comments
 * @property int $participants
 * @property int $max_depth
 * @property Carbon|null $first_comment_at
 * @property Carbon|null $last_activity_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<static>
 */
class ThreadMetric extends Model
{
    use GetsMeerkatConfig, HasTimestamps;

    protected $table = 'thread_metrics';

    protected $fillable = [
        'thread_id',
        'site',
        'collection',
        'total_comments',
        'published_comments',
        'pending_comments',
        'spam_comments',
        'root_comments',
        'reply_comments',
        'participants',
        'max_depth',
        'first_comment_at',
        'last_activity_at',
        'metadata',
    ];

    protected function casts()
    {
        return [
            'first_comment_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function getConnectionName()
    {
        return $this->getDatabaseConnection();
    }
}
