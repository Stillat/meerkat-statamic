<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;

/**
 * @property int $id
 * @property string $user_id
 * @property string|null $email
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static> onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static> withoutTrashed()
 *
 * @mixin Builder<static>
 */
class UserMeta extends Model
{
    use GetsMeerkatConfig, HasTimestamps, SoftDeletes;

    protected $table = 'users_meta';

    protected $fillable = [
        'user_id',
        'name',
        'email',
    ];

    public function getConnectionName()
    {
        return $this->getDatabaseConnection();
    }
}
