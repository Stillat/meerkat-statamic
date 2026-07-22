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
 * @property int $comment_id
 * @property string|null $actor_id
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<static>
 */
class CommentModerationAudit extends Model
{
    use GetsMeerkatConfig, HasTimestamps;

    protected $table = 'comment_moderation_audits';

    protected $fillable = [
        'comment_id',
        'actor_id',
        'action',
        'details',
    ];

    protected function casts()
    {
        return [
            'details' => 'array',
        ];
    }

    public function getConnectionName()
    {
        return $this->getDatabaseConnection();
    }
}
