<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Guard;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry;
use Stillat\Meerkat\Concerns\GetsCommentDetails;
use Stillat\Meerkat\Contracts\SpamGuard;
use Stillat\Meerkat\Database\Models\Comment;
use Throwable;

class Manager
{
    use GetsCommentDetails;

    /**
     * @return SpamGuard[]
     */
    protected function guards(): array
    {
        $configured = config('meerkat.spam.guards', []);

        $guards = [];

        foreach (is_array($configured) ? $configured : [] as $abstract) {
            if (! is_string($abstract)) {
                continue;
            }

            $guard = app($abstract);

            if ($guard instanceof SpamGuard) {
                $guards[] = $guard;
            }
        }

        return $guards;
    }

    public function isSpam(Entry $entry, Comment $comment): bool
    {
        $firstFailure = null;

        foreach ($this->guards() as $guard) {
            try {
                if ($guard->isSpam($entry, $comment)) {
                    return true;
                }
            } catch (Throwable $e) {
                $firstFailure ??= $e;

                Log::warning('Meerkat spam guard isSpam failed; continuing with remaining guards.', [
                    'guard' => $guard::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if ($firstFailure instanceof Throwable) {
            throw $firstFailure;
        }

        return false;
    }

    public function reportSpamById(int $id): void
    {
        $details = $this->getCommentDetails($id);

        if (! $details) {
            return;
        }

        $this->reportSpam($details[0], $details[1]);
    }

    public function reportHamById(int $id): void
    {
        $details = $this->getCommentDetails($id);

        if (! $details) {
            return;
        }

        $this->reportHam($details[0], $details[1]);
    }

    public function reportSpam(Entry $entry, Comment $comment): void
    {
        foreach ($this->guards() as $guard) {
            try {
                $guard->reportSpam($entry, $comment);
            } catch (Throwable $e) {
                $this->logReportFailure('reportSpam', $guard, $comment, $e);
            }
        }
    }

    public function reportHam(Entry $entry, Comment $comment): void
    {
        foreach ($this->guards() as $guard) {
            try {
                $guard->reportHam($entry, $comment);
            } catch (Throwable $e) {
                $this->logReportFailure('reportHam', $guard, $comment, $e);
            }
        }
    }

    protected function logReportFailure(string $operation, SpamGuard $guard, Comment $comment, Throwable $e): void
    {
        Log::warning('Meerkat spam guard '.$operation.' failed; continuing with remaining guards.', [
            'guard' => $guard::class,
            'comment_id' => $comment->id,
            'exception' => $e->getMessage(),
        ]);
    }
}
