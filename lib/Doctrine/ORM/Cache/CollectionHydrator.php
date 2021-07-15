<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;

/**
 * Hydrator cache entry for collections
 */
interface CollectionHydrator
{
    /**
     * @param ClassMetadata            $metadata   The entity metadata.
     * @param CollectionCacheKey       $key        The cached collection key.
     * @param array|mixed[]|Collection $collection The collection.
     *
     * @return CollectionCacheEntry
     */
    public function buildCacheEntry(ClassMetadata $metadata, CollectionCacheKey $key, $collection);

    /**
     * @param ClassMetadata        $metadata   The owning entity metadata.
     * @param CollectionCacheKey   $key        The cached collection key.
     * @param CollectionCacheEntry $entry      The cached collection entry.
     * @param PersistentCollection $collection The collection to load the cache into.
     *
     * @return mixed[]
     */
    public function loadCacheEntry(ClassMetadata $metadata, CollectionCacheKey $key, CollectionCacheEntry $entry, PersistentCollection $collection);
}
