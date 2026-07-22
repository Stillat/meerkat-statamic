<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Data;

use Statamic\Contracts\Data\Augmented;
use Statamic\Data\AbstractAugmented;
use Statamic\Data\AugmentedCollection;
use Statamic\Fields\Value;
use Statamic\Support\Traits\Hookable;

trait HasAugmentedInstance
{
    use Hookable;

    /** @param string $key */
    public function augmentedValue($key): Value
    {
        return $this->augmented()->get($key);
    }

    /**
     * @param  array<int, string>|string|null  $keys
     * @param  mixed  $fields
     */
    private function toAugmentedCollectionWithFields($keys, $fields = null): AugmentedCollection
    {
        $augmented = $this->augmented();

        if (! $augmented instanceof AbstractAugmented) {
            throw new \LogicException('The augmented hook must return an AbstractAugmented instance.');
        }

        $augmented = $augmented->withRelations($this->defaultAugmentedRelations());

        if (! $augmented instanceof AbstractAugmented) {
            throw new \LogicException('Statamic returned an invalid augmented instance after adding relations.');
        }

        $augmented = $augmented->withBlueprintFields($fields);

        if (! $augmented instanceof AbstractAugmented) {
            throw new \LogicException('Statamic returned an invalid augmented instance after adding fields.');
        }

        $collection = $augmented->select($keys ?? $this->defaultAugmentedArrayKeys());

        if (! $collection instanceof AugmentedCollection) {
            throw new \LogicException('Statamic returned an invalid augmented collection.');
        }

        return $collection;
    }

    /** @param array<int, string>|string|null $keys */
    public function toAugmentedCollection($keys = null): AugmentedCollection
    {
        return $this->toAugmentedCollectionWithFields($keys);
    }

    /**
     * @param  array<int, string>|string|null  $keys
     * @return array<string, mixed>
     */
    public function toAugmentedArray($keys = null): array
    {
        return $this->stringKeyedArray($this->toAugmentedCollection($keys)->all());
    }

    /**
     * @param  array<int, string>|string|null  $keys
     * @return array<string, mixed>
     */
    public function toDeferredAugmentedArray($keys = null): array
    {
        return $this->stringKeyedArray($this->toAugmentedCollectionWithFields($keys)->deferredAll());
    }

    /**
     * @param  array<int, string>|string|null  $keys
     * @param  mixed  $fields
     * @return array<string, mixed>
     */
    public function toDeferredAugmentedArrayUsingFields($keys, $fields): array
    {
        return $this->stringKeyedArray($this->toAugmentedCollectionWithFields($keys, $fields)->deferredAll());
    }

    public function toShallowAugmentedCollection(): AugmentedCollection
    {
        $collection = $this->toAugmentedCollection($this->shallowAugmentedArrayKeys())->withShallowNesting();

        if (! $collection instanceof AugmentedCollection) {
            throw new \LogicException('Statamic returned an invalid shallow augmented collection.');
        }

        return $collection;
    }

    /** @return array<string, mixed> */
    public function toShallowAugmentedArray(): array
    {
        return $this->stringKeyedArray($this->toShallowAugmentedCollection()->all());
    }

    public function augmented(): Augmented
    {
        $augmented = $this->runHooks('augmented', $this->newAugmentedInstance());

        if (! $augmented instanceof Augmented) {
            throw new \LogicException('The augmented hook must return an Augmented instance.');
        }

        return $augmented;
    }

    abstract public function newAugmentedInstance(): Augmented;

    /** @return list<string>|null */
    protected function defaultAugmentedArrayKeys(): ?array
    {
        return null;
    }

    /** @return list<string> */
    public function shallowAugmentedArrayKeys(): array
    {
        return ['id', 'title', 'api_url'];
    }

    /** @return list<string> */
    protected function defaultAugmentedRelations(): array
    {
        return [];
    }

    /**
     * @param  array<int, string>|string|null  $keys
     * @return array<string, mixed>
     */
    public function toEvaluatedAugmentedArray($keys = null): array
    {
        $collection = $this->toAugmentedCollection($keys);

        if ($exceptions = $this->excludedEvaluatedAugmentedArrayKeys()) {
            $filtered = $collection->except($exceptions);

            $filtered = $filtered->withRelations($collection->getRelations());

            if (! $filtered instanceof AugmentedCollection) {
                throw new \LogicException('Statamic returned an invalid augmented collection after adding relations.');
            }

            $collection = $filtered;
        }

        $collection = $collection->withEvaluation();

        if (! $collection instanceof AugmentedCollection) {
            throw new \LogicException('Statamic returned an invalid evaluated augmented collection.');
        }

        return $this->stringKeyedArray($collection->toArray());
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $values): array
    {
        if (! is_array($values)) {
            throw new \UnexpectedValueException('Statamic augmentation must return an array.');
        }

        $result = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                throw new \UnexpectedValueException('Statamic augmentation keys must be strings.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /** @return list<string>|null */
    protected function excludedEvaluatedAugmentedArrayKeys(): ?array
    {
        return null;
    }
}
