<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Region;

use BadMethodCallException;
use Doctrine\Common\Cache\Cache as CacheAdapter;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\ClearableCache;
use Doctrine\ORM\Cache\CacheEntry;
use Doctrine\ORM\Cache\CacheKey;
use Doctrine\ORM\Cache\CollectionCacheEntry;
use Doctrine\ORM\Cache\Lock;
use Doctrine\ORM\Cache\Region;

use function get_class;
use function sprintf;

/**
 * The simplest cache region compatible with all doctrine-cache drivers.
 */
class DefaultRegion implements Region
{
    public const REGION_KEY_SEPARATOR = '_';

    /** @var CacheAdapter */
    protected $cache;

    /** @var string */
    protected $name;

    /** @var int */
    protected $lifetime = 0;

    /**
     * @param string $name
     * @param int    $lifetime
     */
    public function __construct($name, CacheAdapter $cache, $lifetime = 0)
    {
        $this->cache    = $cache;
        $this->name     = (string) $name;
        $this->lifetime = (int) $lifetime;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return CacheProvider
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * {@inheritdoc}
     */
    public function contains(CacheKey $key)
    {
        return $this->cache->contains($this->getCacheEntryKey($key));
    }

    /**
     * {@inheritdoc}
     */
    public function get(CacheKey $key)
    {
        $entry = $this->cache->fetch($this->getCacheEntryKey($key));

        if (! $entry instanceof CacheEntry) {
            return null;
        }

        return $entry;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(CollectionCacheEntry $collection)
    {
        $result = [];

        foreach ($collection->identifiers as $key) {
            $entryKey   = $this->getCacheEntryKey($key);
            $entryValue = $this->cache->fetch($entryKey);

            if (! $entryValue instanceof CacheEntry) {
                return null;
            }

            $result[] = $entryValue;
        }

        return $result;
    }

    /**
     * @return string
     */
    protected function getCacheEntryKey(CacheKey $key)
    {
        return $this->name . self::REGION_KEY_SEPARATOR . $key->hash;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function put(CacheKey $key, CacheEntry $entry, ?Lock $lock = null)
    {
        return $this->cache->save($this->getCacheEntryKey($key), $entry, $this->lifetime);
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function evict(CacheKey $key)
    {
        return $this->cache->delete($this->getCacheEntryKey($key));
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function evictAll()
    {
        if (! $this->cache instanceof ClearableCache) {
            throw new BadMethodCallException(sprintf(
                'Clearing all cache entries is not supported by the supplied cache adapter of type %s',
                get_class($this->cache)
            ));
        }

        return $this->cache->deleteAll();
    }
}
