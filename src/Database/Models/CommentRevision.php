<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Database\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stillat\Meerkat\Concerns\GetsMeerkatConfig;
use Stillat\Meerkat\Mirror\Mirror;

/**
 * @property int $id
 * @property int $comment_id
 * @property int $revision_number
 * @property string $comment_text
 * @property array<string, mixed>|null $comment_data
 * @property string|null $edited_by
 * @property string|null $edit_reason
 * @property Carbon $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<static>
 */
class CommentRevision extends Model
{
    use GetsMeerkatConfig, HasTimestamps;

    protected $table = 'comment_revisions';

    protected $fillable = [
        'comment_id',
        'revision_number',
        'comment_text',
        'comment_data',
        'edited_by',
        'edit_reason',
        'edited_at',
    ];

    protected static function booted()
    {

        static::created(fn (CommentRevision $revision) => Mirror::handleRevisionCreated($revision));
    }

    public function getConnectionName()
    {
        return $this->getDatabaseConnection();
    }

    protected function casts()
    {
        return [
            'comment_data' => 'array',
            'edited_at' => 'datetime',
            'revision_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
