<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Data;

use Statamic\Fields\Value;

class AugmentedComment extends AugmentedModel
{
    /** @param string $handle */
    public function get($handle): Value
    {
        if ($handle === 'author_name') {
            return new Value(
                $this->data->resolvedName(),
                $handle,
                $this->blueprintFields()->get($handle)?->fieldtype(),
                $this->data
            );
        }

        if ($handle === 'author_email') {
            return new Value(
                $this->data->resolvedEmail(),
                $handle,
                $this->blueprintFields()->get($handle)?->fieldtype(),
                $this->data
            );
        }

        return parent::get($handle);
    }
}
