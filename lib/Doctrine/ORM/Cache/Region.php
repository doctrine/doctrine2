<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Cache\Exception\CacheException;

/**
 * Defines a contract for accessing a particular named region.
 */
interface Region extends MultiGetRegion
{
    /**
     * Retrieve the name of this region.
     *
     * @return string The region name
     */
    public function getName();

    /**
     * Determine whether this region contains data for the given key.
     *
     * @param CacheKey $key The cache key
     *
     * @return bool TRUE if the underlying cache contains corresponding data; FALSE otherwise.
     */
    public function contains(CacheKey $key);

    /**
     * Get an item from the cache.
     *
     * @param CacheKey $key The key of the item to be retrieved.
     *
     * @return CacheEntry|null The cached entry or NULL
     *
     * @throws CacheException Indicates a problem accessing the item or region.
     */
    public function get(CacheKey $key);

    /**
     * Put an item into the cache.
     *
     * @param CacheKey   $key   The key under which to cache the item.
     * @param CacheEntry $entry The entry to cache.
     * @param Lock|null  $lock  The lock previously obtained.
     *
     * @throws CacheException Indicates a problem accessing the region.
     */
    public function put(CacheKey $key, CacheEntry $entry, ?Lock $lock = null);

    /**
     * Remove an item from the cache.
     *
     * @param CacheKey $key The key under which to cache the item.
     *
     * @throws CacheException Indicates a problem accessing the region.
     */
    public function evict(CacheKey $key);

    /**
     * Remove all contents of this particular cache region.
     *
     * @throws CacheException Indicates problem accessing the region.
     */
    public function evictAll();
}
