<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Region;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\MultiGetCache;
use Doctrine\ORM\Cache\CacheEntry;
use Doctrine\ORM\Cache\CollectionCacheEntry;

use function assert;
use function count;

/**
 * A cache region that enables the retrieval of multiple elements with one call
 */
class DefaultMultiGetRegion extends DefaultRegion
{
    /**
     * Note that the multiple type is due to doctrine/cache not integrating the MultiGetCache interface
     * in its signature due to BC in 1.x
     *
     * @var MultiGetCache|Cache
     */
    protected $cache;

    /**
     * {@inheritDoc}
     *
     * @param MultiGetCache $cache
     */
    public function __construct($name, MultiGetCache $cache, $lifetime = 0)
    {
        assert($cache instanceof Cache);
        parent::__construct($name, $cache, $lifetime);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(CollectionCacheEntry $collection)
    {
        $keysToRetrieve = [];

        foreach ($collection->identifiers as $index => $key) {
            $keysToRetrieve[$index] = $this->getCacheEntryKey($key);
        }

        $items = $this->cache->fetchMultiple($keysToRetrieve);
        if (count($items) !== count($keysToRetrieve)) {
            return null;
        }

        $returnableItems = [];

        foreach ($keysToRetrieve as $index => $key) {
            if (! $items[$key] instanceof CacheEntry) {
                return null;
            }

            $returnableItems[$index] = $items[$key];
        }

        return $returnableItems;
    }
}
