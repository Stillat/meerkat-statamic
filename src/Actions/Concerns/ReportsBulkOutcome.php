<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Actions\Concerns;

use RuntimeException;

trait ReportsBulkOutcome
{
    protected function reportBulkOutcome(int $succeeded, int $total, string $successKey): string
    {
        if ($succeeded < $total) {
            throw new RuntimeException(
                __('meerkat::general.bulk_partial_failure', [
                    'succeeded' => $succeeded,
                    'total' => $total,
                ])
            );
        }

        return trans_choice($successKey, $succeeded);
    }
}
