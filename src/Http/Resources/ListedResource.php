<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Stillat\Meerkat\Data\RetrievesDataValue;

abstract class ListedResource extends JsonResource
{
    protected Blueprint $blueprint;

    protected Columns $columns;

    public function blueprint(Blueprint $blueprint): static
    {
        $this->blueprint = $blueprint;

        return $this;
    }

    public function columns(Columns $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /** @return array<string, mixed> */
    abstract public function values(Request $request): array;

    /** @return array<int|string, mixed>|Arrayable<int|string, mixed> */
    public function toArray(Request $request): array|Arrayable
    {
        return [
            $this->merge($this->values($request)),
            $this->merge($this->resourceValues()),
        ];
    }

    /** @return Collection<string, mixed> */
    protected function resourceValues(): Collection
    {
        return $this->columns->mapWithKeys(function (mixed $column): array {
            if (! $column instanceof Column || ! is_string($column->field)) {
                return [];
            }

            $key = $column->field;
            $field = $this->blueprint->field($key);
            $value = $this->valueFor($key);

            if (! $field instanceof Field) {
                return [$key => $value];
            }

            $field->setValue($value);
            $field->setParent($this->resource);
            $field->preProcessIndex();
            $value = $field->value();

            return [$key => $value];
        });
    }

    protected function valueFor(string $key): mixed
    {
        if ($this->resource instanceof RetrievesDataValue && $this->resource->hasDataValue($key)) {
            return $this->resource->getDataValue($key);
        }

        return $this->resource->{$key};
    }
}
