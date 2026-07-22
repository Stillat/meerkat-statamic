<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Services;

use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\CommentModerationAudit;

class ModerationAuditManager
{
    /** @param array<string, mixed> $details */
    public function log(Comment $comment, string $action, array $details = [], ?string $actorId = null): CommentModerationAudit
    {
        return CommentModerationAudit::query()->create([
            'comment_id' => $comment->id,
            'actor_id' => $actorId ?? auth()->id(),
            'action' => $action,
            'details' => $details,
        ]);
    }
}
