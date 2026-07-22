<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Support;

use Statamic\Statamic;

class Features
{
    /**
     * Whether comment revisions should be captured and exposed.
     */
    public static function revisions(): bool
    {
        return (bool) config('meerkat.revisions.enabled', false) && Statamic::pro();
    }

    /**
     * Whether Meerkat's GraphQL types and queries should be registered.
     */
    public static function graphql(): bool
    {
        return (bool) config('meerkat.graphql.enabled', false) && Statamic::pro();
    }
}
