<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Jobs\Concerns\DispatchesMeerkatJobs;

class CheckForSpam implements ShouldQueue
{
    use DispatchesMeerkatJobs;

    public function __construct(
        public int $id,
    ) {}

    public function handle(CommentRepository $comments): void
    {
        $comments->checkForSpam($this->id);
    }
}
