<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\ReadableCollection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Override;

/**
 * A lazy collection that allows a fast count when using criteria object
 * Once count gets executed once without collection being initialized, result
 * is cached and returned on subsequent calls until collection gets loaded,
 * then returning the number of loaded results.
 *
 * @template TKey of array-key
 * @template TValue of object
 * @extends AbstractLazyCollection<TKey, TValue>
 * @implements Selectable<TKey, TValue>
 */
class LazyCriteriaCollection extends AbstractLazyCollection implements Selectable
{
    /** @var non-negative-int|null */
    private int|null $count = null;

    public function __construct(
        protected EntityPersister $entityPersister,
        protected Criteria $criteria,
    ) {
    }

    /**
     * Do an efficient count on the collection
     */
    #[Override]
    public function count(): int
    {
        if ($this->isInitialized()) {
            return $this->collection->count();
        }

        // Return cached result in case count query was already executed
        if ($this->count !== null) {
            return $this->count;
        }

        return $this->count = $this->entityPersister->count($this->criteria);
    }

    /**
     * check if collection is empty without loading it
     */
    #[Override]
    public function isEmpty(): bool
    {
        if ($this->isInitialized()) {
            return $this->collection->isEmpty();
        }

        return ! $this->count();
    }

    /**
     * Do an optimized search of an element
     *
     * @param mixed $element The element to search for.
     *
     * @return bool TRUE if the collection contains $element, FALSE otherwise.
     */
    #[Override]
    public function contains(mixed $element): bool
    {
        if ($this->isInitialized()) {
            return $this->collection->contains($element);
        }

        return $this->entityPersister->exists($element, $this->criteria);
    }

    /** @return ReadableCollection<TKey, TValue>&Selectable<TKey, TValue> */
    #[Override]
    public function matching(Criteria $criteria): ReadableCollection&Selectable
    {
        $this->initialize();

        return $this->collection->matching($criteria);
    }

    #[Override]
    protected function doInitialize(): void
    {
        $elements         = $this->entityPersister->loadCriteria($this->criteria);
        $this->collection = new ArrayCollection($elements);
    }
}
