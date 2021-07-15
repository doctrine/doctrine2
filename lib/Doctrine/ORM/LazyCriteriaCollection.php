<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Persisters\Entity\EntityPersister;

/**
 * A lazy collection that allows a fast count when using criteria object
 * Once count gets executed once without collection being initialized, result
 * is cached and returned on subsequent calls until collection gets loaded,
 * then returning the number of loaded results.
 */
class LazyCriteriaCollection extends AbstractLazyCollection implements Selectable
{
    /** @var EntityPersister */
    protected $entityPersister;

    /** @var Criteria */
    protected $criteria;

    /** @var int|null */
    private $count;

    public function __construct(EntityPersister $entityPersister, Criteria $criteria)
    {
        $this->entityPersister = $entityPersister;
        $this->criteria        = $criteria;
    }

    /**
     * Do an efficient count on the collection
     *
     * @return int
     */
    public function count()
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
     *
     * @return bool TRUE if the collection is empty, FALSE otherwise.
     */
    public function isEmpty()
    {
        if ($this->isInitialized()) {
            return $this->collection->isEmpty();
        }

        return ! $this->count();
    }

    /**
     * Do an optimized search of an element
     *
     * @param object $element
     *
     * @return bool
     */
    public function contains($element)
    {
        if ($this->isInitialized()) {
            return $this->collection->contains($element);
        }

        return $this->entityPersister->exists($element, $this->criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function matching(Criteria $criteria)
    {
        $this->initialize();

        return $this->collection->matching($criteria);
    }

    /**
     * {@inheritDoc}
     */
    protected function doInitialize()
    {
        $elements         = $this->entityPersister->loadCriteria($this->criteria);
        $this->collection = new ArrayCollection($elements);
    }
}
