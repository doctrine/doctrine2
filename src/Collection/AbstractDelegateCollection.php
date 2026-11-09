<?php

declare(strict_types=1);

namespace Doctrine\ORM\Collection;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\ReadableCollection;
use Doctrine\Common\Collections\Selectable;

/**
 * Convenience base class for custom collections that want to expose the full
 * {@see Collection} API in addition to their own methods.
 *
 * Every method of the Collection API that mutates state is final and forwards
 * to the underlying collection, so mutations are always visible to the ORM's
 * change tracking once the collection is managed. Implementing
 * {@see DelegateCollection} directly is the alternative for classes that do
 * not want to expose the Collection API at all.
 *
 * @phpstan-template TKey of array-key
 * @phpstan-template T
 * @template-extends AbstractLazyCollection<TKey,T>
 * @template-implements DelegateCollection<TKey,T>
 * @template-implements Selectable<TKey,T>
 */
abstract class AbstractDelegateCollection extends AbstractLazyCollection implements DelegateCollection, Selectable
{
    use DelegateCollectionImplementation;

    /** @phpstan-param Collection<TKey, T> $collection */
    public function __construct(Collection $collection = new ArrayCollection())
    {
        $this->collection  = $collection;
        $this->initialized = true;
    }

    /** @phpstan-return Collection<TKey, T> */
    final public function getDelegatee(): Collection
    {
        $this->initialize();

        return $this->collection;
    }

    protected function doInitialize(): void
    {
    }

    /** Lazy loading is delegated, so the underlying collection holds the actual state. */
    public function isInitialized(): bool
    {
        return ! $this->collection instanceof AbstractLazyCollection || $this->collection->isInitialized();
    }

    private function doAdd(mixed $value): void
    {
        $this->getDelegatee()->add($value);
    }

    /**
     * Declared here as AbstractLazyCollection only implements Selectable since doctrine/collections 2.6.
     *
     * @phpstan-return ReadableCollection<TKey, T>&Selectable<TKey, T>
     */
    public function matching(Criteria $criteria): ReadableCollection
    {
        return $this->getDelegatee()->matching($criteria);
    }

    final public function clear(): void
    {
        parent::clear();
    }

    final public function remove(string|int $key): mixed
    {
        return parent::remove($key);
    }

    final public function removeElement(mixed $element): bool
    {
        return parent::removeElement($element);
    }

    final public function set(string|int $key, mixed $value): void
    {
        parent::set($key, $value);
    }

    final public function offsetSet(mixed $offset, mixed $value): void
    {
        parent::offsetSet($offset, $value);
    }

    final public function offsetUnset(mixed $offset): void
    {
        parent::offsetUnset($offset);
    }

    public function __clone()
    {
        if ($this->collection !== null) {
            $this->collection = clone $this->collection;
        }
    }
}
