<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Logging;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\QueryCacheKey;

use function array_sum;

/**
 * Provide basic second level cache statistics.
 */
class StatisticsCacheLogger implements CacheLogger
{
    /** @var int[] */
    private $cacheMissCountMap = [];

    /** @var int[] */
    private $cacheHitCountMap = [];

    /** @var int[] */
    private $cachePutCountMap = [];

    /**
     * {@inheritdoc}
     */
    public function collectionCacheMiss($regionName, CollectionCacheKey $key)
    {
        $this->cacheMissCountMap[$regionName] = isset($this->cacheMissCountMap[$regionName])
            ? $this->cacheMissCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function collectionCacheHit($regionName, CollectionCacheKey $key)
    {
        $this->cacheHitCountMap[$regionName] = isset($this->cacheHitCountMap[$regionName])
            ? $this->cacheHitCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function collectionCachePut($regionName, CollectionCacheKey $key)
    {
        $this->cachePutCountMap[$regionName] = isset($this->cachePutCountMap[$regionName])
            ? $this->cachePutCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function entityCacheMiss($regionName, EntityCacheKey $key)
    {
        $this->cacheMissCountMap[$regionName] = isset($this->cacheMissCountMap[$regionName])
            ? $this->cacheMissCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function entityCacheHit($regionName, EntityCacheKey $key)
    {
        $this->cacheHitCountMap[$regionName] = isset($this->cacheHitCountMap[$regionName])
            ? $this->cacheHitCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function entityCachePut($regionName, EntityCacheKey $key)
    {
        $this->cachePutCountMap[$regionName] = isset($this->cachePutCountMap[$regionName])
            ? $this->cachePutCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function queryCacheHit($regionName, QueryCacheKey $key)
    {
        $this->cacheHitCountMap[$regionName] = isset($this->cacheHitCountMap[$regionName])
            ? $this->cacheHitCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function queryCacheMiss($regionName, QueryCacheKey $key)
    {
        $this->cacheMissCountMap[$regionName] = isset($this->cacheMissCountMap[$regionName])
            ? $this->cacheMissCountMap[$regionName] + 1
            : 1;
    }

    /**
     * {@inheritdoc}
     */
    public function queryCachePut($regionName, QueryCacheKey $key)
    {
        $this->cachePutCountMap[$regionName] = isset($this->cachePutCountMap[$regionName])
            ? $this->cachePutCountMap[$regionName] + 1
            : 1;
    }

    /**
     * Get the number of entries successfully retrieved from cache.
     *
     * @param string $regionName The name of the cache region.
     *
     * @return int
     */
    public function getRegionHitCount($regionName)
    {
        return $this->cacheHitCountMap[$regionName] ?? 0;
    }

    /**
     * Get the number of cached entries *not* found in cache.
     *
     * @param string $regionName The name of the cache region.
     *
     * @return int
     */
    public function getRegionMissCount($regionName)
    {
        return $this->cacheMissCountMap[$regionName] ?? 0;
    }

    /**
     * Get the number of cacheable entries put in cache.
     *
     * @param string $regionName The name of the cache region.
     *
     * @return int
     */
    public function getRegionPutCount($regionName)
    {
        return $this->cachePutCountMap[$regionName] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function getRegionsMiss()
    {
        return $this->cacheMissCountMap;
    }

    /**
     * @return array<string, int>
     */
    public function getRegionsHit()
    {
        return $this->cacheHitCountMap;
    }

    /**
     * @return array<string, int>
     */
    public function getRegionsPut()
    {
        return $this->cachePutCountMap;
    }

    /**
     * Clear region statistics
     *
     * @param string $regionName The name of the cache region.
     *
     * @return void
     */
    public function clearRegionStats($regionName)
    {
        $this->cachePutCountMap[$regionName]  = 0;
        $this->cacheHitCountMap[$regionName]  = 0;
        $this->cacheMissCountMap[$regionName] = 0;
    }

    /**
     * Clear all statistics
     *
     * @return void
     */
    public function clearStats()
    {
        $this->cachePutCountMap  = [];
        $this->cacheHitCountMap  = [];
        $this->cacheMissCountMap = [];
    }

    /**
     * Get the total number of put in cache.
     *
     * @return int
     */
    public function getPutCount()
    {
        return array_sum($this->cachePutCountMap);
    }

    /**
     * Get the total number of entries successfully retrieved from cache.
     *
     * @return int
     */
    public function getHitCount()
    {
        return array_sum($this->cacheHitCountMap);
    }

    /**
     * Get the total number of cached entries *not* found in cache.
     *
     * @return int
     */
    public function getMissCount()
    {
        return array_sum($this->cacheMissCountMap);
    }
}
