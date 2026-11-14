<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Logging;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\QueryCacheKey;
use Override;

use function array_sum;

/**
 * Provide basic second level cache statistics.
 */
class StatisticsCacheLogger implements CacheLogger
{
    /** @var array<string, int> */
    private array $cacheMissCountMap = [];

    /** @var array<string, int> */
    private array $cacheHitCountMap = [];

    /** @var array<string, int> */
    private array $cachePutCountMap = [];

    #[Override]
    public function collectionCacheMiss(string $regionName, CollectionCacheKey $key): void
    {
        $this->cacheMissCountMap[$regionName]
            = ($this->cacheMissCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function collectionCacheHit(string $regionName, CollectionCacheKey $key): void
    {
        $this->cacheHitCountMap[$regionName]
            = ($this->cacheHitCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function collectionCachePut(string $regionName, CollectionCacheKey $key): void
    {
        $this->cachePutCountMap[$regionName]
            = ($this->cachePutCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function entityCacheMiss(string $regionName, EntityCacheKey $key): void
    {
        $this->cacheMissCountMap[$regionName]
            = ($this->cacheMissCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function entityCacheHit(string $regionName, EntityCacheKey $key): void
    {
        $this->cacheHitCountMap[$regionName]
            = ($this->cacheHitCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function entityCachePut(string $regionName, EntityCacheKey $key): void
    {
        $this->cachePutCountMap[$regionName]
            = ($this->cachePutCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function queryCacheHit(string $regionName, QueryCacheKey $key): void
    {
        $this->cacheHitCountMap[$regionName]
            = ($this->cacheHitCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function queryCacheMiss(string $regionName, QueryCacheKey $key): void
    {
        $this->cacheMissCountMap[$regionName]
            = ($this->cacheMissCountMap[$regionName] ?? 0) + 1;
    }

    #[Override]
    public function queryCachePut(string $regionName, QueryCacheKey $key): void
    {
        $this->cachePutCountMap[$regionName]
            = ($this->cachePutCountMap[$regionName] ?? 0) + 1;
    }

    /**
     * Get the number of entries successfully retrieved from cache.
     *
     * @param string $regionName The name of the cache region.
     */
    public function getRegionHitCount(string $regionName): int
    {
        return $this->cacheHitCountMap[$regionName] ?? 0;
    }

    /**
     * Get the number of cached entries *not* found in cache.
     *
     * @param string $regionName The name of the cache region.
     */
    public function getRegionMissCount(string $regionName): int
    {
        return $this->cacheMissCountMap[$regionName] ?? 0;
    }

    /**
     * Get the number of cacheable entries put in cache.
     *
     * @param string $regionName The name of the cache region.
     */
    public function getRegionPutCount(string $regionName): int
    {
        return $this->cachePutCountMap[$regionName] ?? 0;
    }

    /** @return array<string, int> */
    public function getRegionsMiss(): array
    {
        return $this->cacheMissCountMap;
    }

    /** @return array<string, int> */
    public function getRegionsHit(): array
    {
        return $this->cacheHitCountMap;
    }

    /** @return array<string, int> */
    public function getRegionsPut(): array
    {
        return $this->cachePutCountMap;
    }

    /**
     * Clear region statistics
     *
     * @param string $regionName The name of the cache region.
     */
    public function clearRegionStats(string $regionName): void
    {
        $this->cachePutCountMap[$regionName]  = 0;
        $this->cacheHitCountMap[$regionName]  = 0;
        $this->cacheMissCountMap[$regionName] = 0;
    }

    /**
     * Clear all statistics
     */
    public function clearStats(): void
    {
        $this->cachePutCountMap  = [];
        $this->cacheHitCountMap  = [];
        $this->cacheMissCountMap = [];
    }

    /**
     * Get the total number of put in cache.
     */
    public function getPutCount(): int
    {
        return array_sum($this->cachePutCountMap);
    }

    /**
     * Get the total number of entries successfully retrieved from cache.
     */
    public function getHitCount(): int
    {
        return array_sum($this->cacheHitCountMap);
    }

    /**
     * Get the total number of cached entries *not* found in cache.
     */
    public function getMissCount(): int
    {
        return array_sum($this->cacheMissCountMap);
    }
}
