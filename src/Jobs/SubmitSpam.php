<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Stillat\Meerkat\Facades\SpamGuard;
use Stillat\Meerkat\Jobs\Concerns\DispatchesMeerkatJobs;

class SubmitSpam implements ShouldQueue
{
    use DispatchesMeerkatJobs;

    public function __construct(
        public int $id,
    ) {}

    public function handle(): void
    {
        SpamGuard::reportSpamById($this->id);
    }
}
