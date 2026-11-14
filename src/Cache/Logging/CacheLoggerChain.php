<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Logging;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\QueryCacheKey;
use Override;

class CacheLoggerChain implements CacheLogger
{
    /** @var array<string, CacheLogger> */
    private array $loggers = [];

    public function setLogger(string $name, CacheLogger $logger): void
    {
        $this->loggers[$name] = $logger;
    }

    public function getLogger(string $name): CacheLogger|null
    {
        return $this->loggers[$name] ?? null;
    }

    /** @return array<string, CacheLogger> */
    public function getLoggers(): array
    {
        return $this->loggers;
    }

    #[Override]
    public function collectionCacheHit(string $regionName, CollectionCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->collectionCacheHit($regionName, $key);
        }
    }

    #[Override]
    public function collectionCacheMiss(string $regionName, CollectionCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->collectionCacheMiss($regionName, $key);
        }
    }

    #[Override]
    public function collectionCachePut(string $regionName, CollectionCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->collectionCachePut($regionName, $key);
        }
    }

    #[Override]
    public function entityCacheHit(string $regionName, EntityCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->entityCacheHit($regionName, $key);
        }
    }

    #[Override]
    public function entityCacheMiss(string $regionName, EntityCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->entityCacheMiss($regionName, $key);
        }
    }

    #[Override]
    public function entityCachePut(string $regionName, EntityCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->entityCachePut($regionName, $key);
        }
    }

    #[Override]
    public function queryCacheHit(string $regionName, QueryCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->queryCacheHit($regionName, $key);
        }
    }

    #[Override]
    public function queryCacheMiss(string $regionName, QueryCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->queryCacheMiss($regionName, $key);
        }
    }

    #[Override]
    public function queryCachePut(string $regionName, QueryCacheKey $key): void
    {
        foreach ($this->loggers as $logger) {
            $logger->queryCachePut($regionName, $key);
        }
    }
}
