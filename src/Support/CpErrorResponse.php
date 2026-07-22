<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class CpErrorResponse
{
    public static function fromMissingTable(QueryException $e): JsonResponse
    {
        $message = strtolower($e->getMessage());

        $isMissing = str_contains($message, 'no such table')
            || str_contains($message, "doesn't exist")
            || str_contains($message, 'does not exist');

        if (! $isMissing || app()->hasDebugModeEnabled()) {
            throw $e;
        }

        return response()->json([
            'message' => __('meerkat::errors.unavailable_pending_migration'),
        ], 503);
    }
}
