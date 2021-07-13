<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Collection;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Collection\CollectionPersister;

/**
 * Interface for second level cache collection persisters.
 */
interface CachedCollectionPersister extends CachedPersister, CollectionPersister
{
    /**
     * @return ClassMetadata
     */
    public function getSourceEntityMetadata();

    /**
     * @return ClassMetadata
     */
    public function getTargetEntityMetadata();

    /**
     * Loads a collection from cache
     *
     * @return PersistentCollection|null
     */
    public function loadCollectionCache(PersistentCollection $collection, CollectionCacheKey $key);

    /**
     * Stores a collection into cache
     *
     * @param array|mixed[]|Collection $elements
     *
     * @return void
     */
    public function storeCollectionCache(CollectionCacheKey $key, $elements);
}
