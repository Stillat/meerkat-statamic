<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Columns;
use Statamic\Fields\Blueprint;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

abstract class ListedResourceCollection extends ResourceCollection
{
    use HasRequestedColumns;

    protected Blueprint $blueprint;

    protected ?string $columnPreferenceKey = null;

    protected Columns $columns;

    public function columnPreferenceKey(string $key): static
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    public function blueprint(Blueprint $blueprint): static
    {
        $this->blueprint = $blueprint;

        return $this;
    }

    protected function setColumns(): void
    {
        $this->columns = $this->blueprint->columns();

        if ($this->columnPreferenceKey) {
            $this->columns->setPreferred($this->columnPreferenceKey);
        }

        $this->columns = $this->columns->rejectUnlisted()->values();
    }

    /** @return array<int|string, mixed>|Arrayable<int|string, mixed> */
    public function toArray($request): array|Arrayable
    {
        $this->setColumns();
        $requestedColumns = $this->requestedColumns();

        if (! $requestedColumns instanceof Columns) {
            $requestedColumns = $this->columns;
        }

        return ($this->collection ?? collect())->each(function (mixed $resource) use ($requestedColumns): void {
            if ($resource instanceof ListedResource) {
                $resource
                    ->blueprint($this->blueprint)
                    ->columns($requestedColumns);
            }
        });
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
