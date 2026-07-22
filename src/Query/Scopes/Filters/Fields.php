<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Query\Scopes\Filters;

use Statamic\Query\Scopes\Filters\Fields as CoreFields;

class Fields extends CoreFields
{
    public function visibleTo(mixed $key): bool
    {
        return $key === 'meerkat.comments';
    }
}
