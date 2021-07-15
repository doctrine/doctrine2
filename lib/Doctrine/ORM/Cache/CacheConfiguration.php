<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Cache\Logging\CacheLogger;

/**
 * Configuration container for second-level cache.
 */
class CacheConfiguration
{
    /** @var CacheFactory|null */
    private $cacheFactory;

    /** @var RegionsConfiguration|null */
    private $regionsConfig;

    /** @var CacheLogger|null */
    private $cacheLogger;

    /** @var QueryCacheValidator|null */
    private $queryValidator;

    /**
     * @return CacheFactory|null
     */
    public function getCacheFactory()
    {
        return $this->cacheFactory;
    }

    /**
     * @return void
     */
    public function setCacheFactory(CacheFactory $factory)
    {
        $this->cacheFactory = $factory;
    }

    /**
     * @return CacheLogger|null
     */
    public function getCacheLogger()
    {
         return $this->cacheLogger;
    }

    /**
     * @return void
     */
    public function setCacheLogger(CacheLogger $logger)
    {
        $this->cacheLogger = $logger;
    }

    /**
     * @return RegionsConfiguration
     */
    public function getRegionsConfiguration()
    {
        if ($this->regionsConfig === null) {
            $this->regionsConfig = new RegionsConfiguration();
        }

        return $this->regionsConfig;
    }

    /**
     * @return void
     */
    public function setRegionsConfiguration(RegionsConfiguration $regionsConfig)
    {
        $this->regionsConfig = $regionsConfig;
    }

    /**
     * @return QueryCacheValidator
     */
    public function getQueryValidator()
    {
        if ($this->queryValidator === null) {
            $this->queryValidator = new TimestampQueryCacheValidator(
                $this->cacheFactory->getTimestampRegion()
            );
        }

         return $this->queryValidator;
    }

    /**
     * @return void
     */
    public function setQueryValidator(QueryCacheValidator $validator)
    {
        $this->queryValidator = $validator;
    }
}
