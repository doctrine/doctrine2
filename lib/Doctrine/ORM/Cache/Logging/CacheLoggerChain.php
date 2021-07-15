<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Logging;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\QueryCacheKey;

class CacheLoggerChain implements CacheLogger
{
    /** @var array<CacheLogger> */
    private $loggers = [];

    /**
     * @param string $name
     *
     * @return void
     */
    public function setLogger($name, CacheLogger $logger)
    {
        $this->loggers[$name] = $logger;
    }

    /**
     * @param string $name
     *
     * @return CacheLogger|null
     */
    public function getLogger($name)
    {
        return $this->loggers[$name] ?? null;
    }

    /**
     * @return array<CacheLogger>
     */
    public function getLoggers()
    {
        return $this->loggers;
    }

    /**
     * {@inheritdoc}
     */
    public function collectionCacheHit($regionName, CollectionCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->collectionCacheHit($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function collectionCacheMiss($regionName, CollectionCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->collectionCacheMiss($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function collectionCachePut($regionName, CollectionCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->collectionCachePut($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function entityCacheHit($regionName, EntityCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->entityCacheHit($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function entityCacheMiss($regionName, EntityCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->entityCacheMiss($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function entityCachePut($regionName, EntityCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->entityCachePut($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function queryCacheHit($regionName, QueryCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->queryCacheHit($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function queryCacheMiss($regionName, QueryCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->queryCacheMiss($regionName, $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function queryCachePut($regionName, QueryCacheKey $key)
    {
        foreach ($this->loggers as $logger) {
            $logger->queryCachePut($regionName, $key);
        }
    }
}
