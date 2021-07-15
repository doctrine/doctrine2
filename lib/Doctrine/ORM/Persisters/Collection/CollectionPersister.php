<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Collection;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\PersistentCollection;

/**
 * Define the behavior that should be implemented by all collection persisters.
 */
interface CollectionPersister
{
    /**
     * Deletes the persistent state represented by the given collection.
     *
     * @return void
     */
    public function delete(PersistentCollection $collection);

    /**
     * Updates the given collection, synchronizing its state with the database
     * by inserting, updating and deleting individual elements.
     *
     * @return void
     */
    public function update(PersistentCollection $collection);

    /**
     * Counts the size of this persistent collection.
     *
     * @return int
     */
    public function count(PersistentCollection $collection);

    /**
     * Slices elements.
     *
     * @param int $offset
     * @param int $length
     *
     * @return mixed[]
     */
    public function slice(PersistentCollection $collection, $offset, $length = null);

    /**
     * Checks for existence of an element.
     *
     * @param object $element
     *
     * @return bool
     */
    public function contains(PersistentCollection $collection, $element);

    /**
     * Checks for existence of a key.
     *
     * @param mixed $key
     *
     * @return bool
     */
    public function containsKey(PersistentCollection $collection, $key);

    /**
     * Gets an element by key.
     *
     * @param mixed $index
     *
     * @return mixed
     */
    public function get(PersistentCollection $collection, $index);

    /**
     * Loads association entities matching the given Criteria object.
     *
     * @return mixed[]
     */
    public function loadCriteria(PersistentCollection $collection, Criteria $criteria);
}
