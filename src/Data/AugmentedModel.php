<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Data;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Data\AbstractAugmented;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;
use Statamic\Fields\Value;
use Statamic\Support\Arr;
use Stillat\Meerkat\Database\Models\Comment;

class AugmentedModel extends AbstractAugmented
{
    /** @var Comment */
    protected $data;

    /** @var Collection<string, mixed> */
    protected $supplements;

    public function __construct(Comment $data, protected Blueprint $blueprint)
    {
        parent::__construct($data);

        $this->supplements = collect();
    }

    /** @param Collection<string, mixed> $data */
    public function supplement(Collection $data): static
    {
        $this->supplements = $data;

        return $this;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(collect([])
            ->merge($this->modelAttributes()->keys())
            ->merge($this->appendedAttributes()->values())
            ->merge($this->blueprintFields()->keys())
            ->unique()
            ->filter(fn (mixed $key): bool => is_string($key))
            ->sort()
            ->values()
            ->all());
    }

    /** @return Collection<string, mixed> */
    protected function modelAttributes(): Collection
    {
        return collect($this->data->getAttributes());
    }

    /** @return Collection<int, string> */
    protected function appendedAttributes(): Collection
    {
        return collect($this->data->getAppends())
            ->filter(fn (mixed $attribute): bool => is_string($attribute))
            ->values();
    }

    /** @return Collection<string, Field> */
    public function blueprintFields(): Collection
    {
        $fields = [];

        foreach ($this->blueprint->fields()->all() as $handle => $field) {
            if (is_string($handle)) {
                $fields[$handle] = $field;
            }
        }

        return collect($fields);
    }

    /** @param string $handle */
    protected function getFromData($handle): mixed
    {
        return $this->supplements->get($handle) ?? data_get($this->data, $handle);
    }

    /** @param string $handle */
    public function get($handle): Value
    {
        if ($this->hasModelAccessor($handle)) {
            $value = $this->wrapModelAccessor($handle);
            $resolved = $value->resolve();

            if (! $resolved instanceof Value) {
                throw new \LogicException('Statamic returned an invalid resolved augmented value.');
            }

            return $resolved;
        }

        if ($this->data->hasDataValue($handle)) {
            return new Value(
                $this->data->getDataValue($handle),
                $handle,
                $this->fieldtype($handle),
                $this->data
            );
        }

        return parent::get($handle);
    }

    private function hasModelAccessor(string $handle): bool
    {
        $method = Str::camel($handle);

        if (! method_exists($this->data, $method)) {
            return false;
        }

        $returnType = (new \ReflectionMethod($this->data, $method))->getReturnType();

        return $returnType instanceof \ReflectionNamedType
            && $returnType->getName() === Attribute::class;
    }

    private function wrapModelAccessor(string $handle): Value
    {
        return new Value(
            function () use ($handle) {
                $method = Str::camel($handle);

                $attribute = invade($this->data)->$method();

                if (! $attribute instanceof Attribute) {
                    throw new \LogicException("The [{$method}] model accessor did not return an Attribute instance.");
                }

                $getter = (new \ReflectionProperty(Attribute::class, 'get'))->getValue($attribute);

                if (! is_callable($getter)) {
                    return $this->data->getAttribute($handle);
                }

                $attributes = $this->data->getAttributes();

                return $getter(Arr::get($attributes, $handle), $attributes);
            },
            $handle,
            $this->fieldtype($handle),
            $this->data
        );
    }

    private function fieldtype(string $handle): ?Fieldtype
    {
        $field = $this->blueprintFields()->get($handle);

        if (! $field instanceof Field) {
            return null;
        }

        $fieldtype = $field->fieldtype();

        return $fieldtype instanceof Fieldtype ? $fieldtype : null;
    }
}
