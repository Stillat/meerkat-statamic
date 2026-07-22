<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Mirror\Mirror;

/**
 * @property int $id
 * @property string $thread_id
 * @property string|null $entry_id
 * @property string|null $site
 * @property string|null $collection
 * @property string $cached_title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static static updateOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 * @method static static firstOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static> withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static> onlyTrashed()
 *
 * @mixin Builder<static>
 */
class Thread extends Model
{
    use GetsMeerkatConfig, HasTimestamps, SoftDeletes;

    protected $table = 'threads';

    protected $fillable = [
        'thread_id',
        'entry_id',
        'cached_title',
        'site',
        'collection',
    ];

    protected static function booted()
    {

        static::saved(fn (Thread $thread) => Mirror::handleThreadSaved($thread));
        static::deleted(fn (Thread $thread) => Mirror::handleThreadSaved($thread));
        static::forceDeleted(fn (Thread $thread) => Mirror::handleThreadForceDeleted($thread));
    }

    public function getConnectionName()
    {
        return $this->getDatabaseConnection();
    }
}
