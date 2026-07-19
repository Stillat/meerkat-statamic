<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http;

use Illuminate\Contracts\Support\Arrayable;
use Statamic\Fields\Blueprint;

/** @implements Arrayable<string, mixed> */
class ValuesResponse implements Arrayable
{
    public function __construct(
        protected Blueprint $blueprint,
        /** @var array<string, mixed> */
        protected array $data,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $fields = $this->blueprint
            ->fields()
            ->addValues($this->data)
            ->preProcess();

        return [
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
        ];
    }
}
