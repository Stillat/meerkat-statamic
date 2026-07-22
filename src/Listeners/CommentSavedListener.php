<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Listeners;

use Stillat\Meerkat\Events\CommentSaved;
use Stillat\Meerkat\Services\ThreadMetricsManager;
use Stillat\Meerkat\Support\ThreadCache;

class CommentSavedListener
{
    public function __construct(
        private readonly ThreadMetricsManager $metrics,
    ) {}

    public function handle(CommentSaved $event): void
    {
        $comment = $event->comment;

        ThreadCache::invalidate($comment->thread_id);
        $this->metrics->recalculateThread($comment->thread_id);
    }
}
