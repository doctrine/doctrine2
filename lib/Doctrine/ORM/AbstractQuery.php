<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\Mapping\MappingException as CommonMappingException;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\ORM\Cache\Exception\InvalidResultCacheDriver;
use Doctrine\ORM\Cache\Logging\CacheLogger;
use Doctrine\ORM\Cache\QueryCacheKey;
use Doctrine\ORM\Cache\TimestampCacheKey;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ResultSetMapping;
use function array_map;
use function array_shift;
use function count;
use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function ksort;
use function reset;
use function serialize;
use function sha1;

/**
 * Base contract for ORM queries. Base class for Query and NativeQuery.
 */
abstract class AbstractQuery
{
    /* Hydration mode constants */

    /**
     * Hydrates an object graph. This is the default behavior.
     */
    public const HYDRATE_OBJECT = 1;

    /**
     * Hydrates an array graph.
     */
    public const HYDRATE_ARRAY = 2;

    /**
     * Hydrates a flat, rectangular result set with scalar values.
     */
    public const HYDRATE_SCALAR = 3;

    /**
     * Hydrates a single scalar value.
     */
    public const HYDRATE_SINGLE_SCALAR = 4;

    /**
     * Very simple object hydrator (optimized for performance).
     */
    public const HYDRATE_SIMPLEOBJECT = 5;

    /**
     * The parameter map of this query.
     *
     * @var ArrayCollection|Parameter[]
     */
    protected $parameters;

    /**
     * The user-specified ResultSetMapping to use.
     *
     * @var ResultSetMapping
     */
    protected $resultSetMapping;

    /**
     * The entity manager used by this query object.
     *
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * The map of query hints.
     *
     * @var mixed[]
     */
    protected $hints = [];

    /**
     * The hydration mode.
     *
     * @var string|int
     */
    protected $hydrationMode = self::HYDRATE_OBJECT;

    /** @var QueryCacheProfile */
    protected $queryCacheProfile;

    /**
     * Whether or not expire the result cache.
     *
     * @var bool
     */
    protected $expireResultCache = false;

    /** @var QueryCacheProfile */
    protected $hydrationCacheProfile;

    /**
     * Whether to use second level cache, if available.
     *
     * @var bool
     */
    protected $cacheable = false;

    /** @var bool */
    protected $hasCache = false;

    /**
     * Second level cache region name.
     *
     * @var string|null
     */
    protected $cacheRegion;

    /**
     * Second level query cache mode.
     *
     * @var int|null
     */
    protected $cacheMode;

    /** @var CacheLogger|null */
    protected $cacheLogger;

    /** @var int */
    protected $lifetime = 0;

    /**
     * Initializes a new instance of a class derived from <tt>AbstractQuery</tt>.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em         = $em;
        $this->parameters = new ArrayCollection();
        $this->hints      = $em->getConfiguration()->getDefaultQueryHints();
        $this->hasCache   = $this->em->getConfiguration()->isSecondLevelCacheEnabled();

        if ($this->hasCache) {
            $this->cacheLogger = $em->getConfiguration()
                ->getSecondLevelCacheConfiguration()
                ->getCacheLogger();
        }
    }

    /**
     * Enable/disable second level query (result) caching for this query.
     *
     * @param bool $cacheable
     *
     * @return static This query instance.
     */
    public function setCacheable($cacheable)
    {
        $this->cacheable = (bool) $cacheable;

        return $this;
    }

    /**
     * @return bool TRUE if the query results are enable for second level cache, FALSE otherwise.
     */
    public function isCacheable()
    {
        return $this->cacheable;
    }

    /**
     * @param string $cacheRegion
     *
     * @return static This query instance.
     */
    public function setCacheRegion($cacheRegion)
    {
        $this->cacheRegion = (string) $cacheRegion;

        return $this;
    }

    /**
     * Obtain the name of the second level query cache region in which query results will be stored
     *
     * @return string|null The cache region name; NULL indicates the default region.
     */
    public function getCacheRegion()
    {
        return $this->cacheRegion;
    }

    /**
     * @return bool TRUE if the query cache and second level cache are enabled, FALSE otherwise.
     */
    protected function isCacheEnabled()
    {
        return $this->cacheable && $this->hasCache;
    }

    /**
     * @return int
     */
    public function getLifetime()
    {
        return $this->lifetime;
    }

    /**
     * Sets the life-time for this query into second level cache.
     *
     * @param int $lifetime
     *
     * @return \Doctrine\ORM\AbstractQuery This query instance.
     */
    public function setLifetime($lifetime)
    {
        $this->lifetime = (int) $lifetime;

        return $this;
    }

    /**
     * @return int
     */
    public function getCacheMode()
    {
        return $this->cacheMode;
    }

    /**
     * @param int $cacheMode
     *
     * @return \Doctrine\ORM\AbstractQuery This query instance.
     */
    public function setCacheMode($cacheMode)
    {
        $this->cacheMode = (int) $cacheMode;

        return $this;
    }

    /**
     * Gets the SQL query that corresponds to this query object.
     * The returned SQL syntax depends on the connection driver that is used
     * by this query object at the time of this method call.
     *
     * @return string SQL query
     */
    abstract public function getSQL();

    /**
     * Retrieves the associated EntityManager of this Query instance.
     *
     * @return EntityManagerInterface
     */
    public function getEntityManager()
    {
        return $this->em;
    }

    /**
     * Frees the resources used by the query object.
     *
     * Resets Parameters, Parameter Types and Query Hints.
     */
    public function free()
    {
        $this->parameters = new ArrayCollection();

        $this->hints = $this->em->getConfiguration()->getDefaultQueryHints();
    }

    /**
     * Get all defined parameters.
     *
     * @return ArrayCollection The defined query parameters.
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Gets a query parameter.
     *
     * @param mixed $key The key (index or name) of the bound parameter.
     *
     * @return Query\Parameter|null The value of the bound parameter, or NULL if not available.
     */
    public function getParameter($key)
    {
        $filteredParameters = $this->parameters->filter(
            static function (Query\Parameter $parameter) use ($key) : bool {
                $parameterName = $parameter->getName();

                return $key === $parameterName || (string) $key === (string) $parameterName;
            }
        );

        return $filteredParameters->isEmpty() ? null : $filteredParameters->first();
    }

    /**
     * Sets a collection of query parameters.
     *
     * @param ArrayCollection|mixed[] $parameters
     *
     * @return static This query instance.
     */
    public function setParameters($parameters)
    {
        // BC compatibility with 2.3-
        if (is_array($parameters)) {
            $parameterCollection = new ArrayCollection();

            foreach ($parameters as $key => $value) {
                $parameterCollection->add(new Parameter($key, $value));
            }

            $parameters = $parameterCollection;
        }

        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Sets a query parameter.
     *
     * @param string|int      $key   The parameter position or name.
     * @param mixed           $value The parameter value.
     * @param int|string|null $type  The parameter type. If specified, the given value will be run through
     *                               the type conversion of this type. This is usually not needed for
     *                               strings and numeric types.
     *
     * @return static This query instance.
     */
    public function setParameter($key, $value, $type = null)
    {
        $existingParameter = $this->getParameter($key);

        if ($existingParameter !== null) {
            $existingParameter->setValue($value, $type);

            return $this;
        }

        $this->parameters->add(new Parameter($key, $value, $type));

        return $this;
    }

    /**
     * Processes an individual parameter value.
     *
     * @param mixed $value
     *
     * @return string|mixed[]
     *
     * @throws ORMInvalidArgumentException
     */
    public function processParameterValue($value)
    {
        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof Mapping\ClassMetadata) {
            return $value->discriminatorValue ?: $value->getClassName();
        }

        if ($value instanceof Collection) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            foreach ($value as $key => $paramValue) {
                $paramValue  = $this->processParameterValue($paramValue);
                $value[$key] = is_array($paramValue) ? reset($paramValue) : $paramValue;
            }

            return $value;
        }

        if (! is_object($value)) {
            return $value;
        }

        try {
            $value = $this->em->getUnitOfWork()->getSingleIdentifierValue($value);

            if ($value === null) {
                throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
            }
        } catch (MappingException | CommonMappingException $e) {
            // Silence any mapping exceptions. These can occur if the object in
            // question is not a mapped entity, in which case we just don't do
            // any preparation on the value.
        }

        return $value;
    }

    /**
     * Sets the ResultSetMapping that should be used for hydration.
     *
     * @return static This query instance.
     */
    public function setResultSetMapping(Query\ResultSetMapping $rsm)
    {
        $this->resultSetMapping = $rsm;

        return $this;
    }

    /**
     * Gets the ResultSetMapping used for hydration.
     *
     * @return ResultSetMapping
     */
    protected function getResultSetMapping()
    {
        return $this->resultSetMapping;
    }

    /**
     * Set a cache profile for hydration caching.
     *
     * If no result cache driver is set in the QueryCacheProfile, the default
     * result cache driver is used from the configuration.
     *
     * Important: Hydration caching does NOT register entities in the
     * UnitOfWork when retrieved from the cache. Never use result cached
     * entities for requests that also flush the EntityManager. If you want
     * some form of caching with UnitOfWork registration you should use
     * {@see AbstractQuery::setResultCacheProfile()}.
     *
     * @return static This query instance.
     *
     * @example
     * $lifetime = 100;
     * $resultKey = "abc";
     * $query->setHydrationCacheProfile(new QueryCacheProfile());
     * $query->setHydrationCacheProfile(new QueryCacheProfile($lifetime, $resultKey));
     */
    public function setHydrationCacheProfile(?QueryCacheProfile $profile = null)
    {
        if ($profile !== null && ! $profile->getResultCacheDriver()) {
            $resultCacheDriver = $this->em->getConfiguration()->getHydrationCacheImpl();
            $profile           = $profile->setResultCacheDriver($resultCacheDriver);
        }

        $this->hydrationCacheProfile = $profile;

        return $this;
    }

    /**
     * @return QueryCacheProfile
     */
    public function getHydrationCacheProfile()
    {
        return $this->hydrationCacheProfile;
    }

    /**
     * Set a cache profile for the result cache.
     *
     * If no result cache driver is set in the QueryCacheProfile, the default
     * result cache driver is used from the configuration.
     *
     * @return static This query instance.
     */
    public function setResultCacheProfile(?QueryCacheProfile $profile = null)
    {
        if ($profile !== null && ! $profile->getResultCacheDriver()) {
            $resultCacheDriver = $this->em->getConfiguration()->getResultCacheImpl();
            $profile           = $profile->setResultCacheDriver($resultCacheDriver);
        }

        $this->queryCacheProfile = $profile;

        return $this;
    }

    /**
     * Defines a cache driver to be used for caching result sets and implicitly enables caching.
     *
     * @param \Doctrine\Common\Cache\Cache|null $resultCacheDriver Cache driver
     *
     * @return static This query instance.
     *
     * @throws ORMException
     */
    public function setResultCacheDriver($resultCacheDriver = null)
    {
        if ($resultCacheDriver !== null && ! ($resultCacheDriver instanceof \Doctrine\Common\Cache\Cache)) {
            throw InvalidResultCacheDriver::create();
        }

        $this->queryCacheProfile = $this->queryCacheProfile
            ? $this->queryCacheProfile->setResultCacheDriver($resultCacheDriver)
            : new QueryCacheProfile(0, null, $resultCacheDriver);

        return $this;
    }

    /**
     * Returns the cache driver used for caching result sets.
     *
     * @deprecated
     *
     * @return \Doctrine\Common\Cache\Cache Cache driver
     */
    public function getResultCacheDriver()
    {
        if ($this->queryCacheProfile && $this->queryCacheProfile->getResultCacheDriver()) {
            return $this->queryCacheProfile->getResultCacheDriver();
        }

        return $this->em->getConfiguration()->getResultCacheImpl();
    }

    /**
     * Enables caching of the results of this query, for given or default amount of seconds
     * and optionally specifies which ID to use for the cache entry.
     *
     * @param int|null    $lifetime      How long the cache entry is valid, in seconds.
     * @param string|null $resultCacheId ID to use for the cache entry.
     *
     * @return static This query instance.
     */
    public function enableResultCache($lifetime = null, $resultCacheId = null)
    {
        $this->setResultCacheLifetime($lifetime);
        $this->setResultCacheId($resultCacheId);

        return $this;
    }

    /**
     * Disables caching of the results of this query.
     *
     * @return static This query instance.
     */
    public function disableResultCache()
    {
        $this->queryCacheProfile = null;

        return $this;
    }

    /**
     * Defines how long the result cache will be active before expire.
     *
     * @param int $lifetime How long the cache entry is valid, in seconds.
     *
     * @return static This query instance.
     */
    public function setResultCacheLifetime($lifetime)
    {
        $lifetime = $lifetime !== null ? (int) $lifetime : 0;

        $this->queryCacheProfile = $this->queryCacheProfile
            ? $this->queryCacheProfile->setLifetime($lifetime)
            : new QueryCacheProfile($lifetime, null, $this->em->getConfiguration()->getResultCacheImpl());

        return $this;
    }

    /**
     * Retrieves the lifetime of resultset cache.
     *
     * @deprecated
     *
     * @return int
     */
    public function getResultCacheLifetime()
    {
        return $this->queryCacheProfile ? $this->queryCacheProfile->getLifetime() : 0;
    }

    /**
     * Defines if the result cache is active or not.
     *
     * @param bool $expire Whether or not to force resultset cache expiration.
     *
     * @return static This query instance.
     */
    public function expireResultCache($expire = true)
    {
        $this->expireResultCache = $expire;

        return $this;
    }

    /**
     * Retrieves if the resultset cache is active or not.
     *
     * @return bool
     */
    public function getExpireResultCache()
    {
        return $this->expireResultCache;
    }

    /**
     * @return QueryCacheProfile
     */
    public function getQueryCacheProfile()
    {
        return $this->queryCacheProfile;
    }

    /**
     * Change the default fetch mode of an association for this query.
     *
     * $fetchMode can be one of FetchMode::EAGER, FetchMode::LAZY or FetchMode::EXTRA_LAZY
     *
     * @param string $class
     * @param string $assocName
     * @param int    $fetchMode
     *
     * @return static This query instance.
     */
    public function setFetchMode($class, $assocName, $fetchMode)
    {
        if ($fetchMode !== Mapping\FetchMode::EAGER) {
            $fetchMode = Mapping\FetchMode::LAZY;
        }

        $this->hints['fetchMode'][$class][$assocName] = $fetchMode;

        return $this;
    }

    /**
     * Defines the processing mode to be used during hydration / result set transformation.
     *
     * @param string|int $hydrationMode Doctrine processing mode to be used during hydration process.
     *                                  One of the Query::HYDRATE_* constants.
     *
     * @return static This query instance.
     */
    public function setHydrationMode($hydrationMode)
    {
        $this->hydrationMode = $hydrationMode;

        return $this;
    }

    /**
     * Gets the hydration mode currently used by the query.
     *
     * @return string|int
     */
    public function getHydrationMode()
    {
        return $this->hydrationMode;
    }

    /**
     * Gets the list of results for the query.
     *
     * Alias for execute(null, $hydrationMode = HYDRATE_OBJECT).
     *
     * @param string|int $hydrationMode
     *
     * @return mixed
     */
    public function getResult($hydrationMode = self::HYDRATE_OBJECT)
    {
        return $this->execute(null, $hydrationMode);
    }

    /**
     * Gets the array of results for the query.
     *
     * Alias for execute(null, HYDRATE_ARRAY).
     *
     * @return mixed[]
     */
    public function getArrayResult()
    {
        return $this->execute(null, self::HYDRATE_ARRAY);
    }

    /**
     * Gets the scalar results for the query.
     *
     * Alias for execute(null, HYDRATE_SCALAR).
     *
     * @return mixed[]
     */
    public function getScalarResult()
    {
        return $this->execute(null, self::HYDRATE_SCALAR);
    }

    /**
     * Get exactly one result or null.
     *
     * @param string|int $hydrationMode
     *
     * @return mixed
     *
     * @throws NonUniqueResultException
     */
    public function getOneOrNullResult($hydrationMode = null)
    {
        try {
            $result = $this->execute(null, $hydrationMode);
        } catch (NoResultException $e) {
            return null;
        }

        if ($this->hydrationMode !== self::HYDRATE_SINGLE_SCALAR && ! $result) {
            return null;
        }

        if (! is_array($result)) {
            return $result;
        }

        if (count($result) > 1) {
            throw new NonUniqueResultException();
        }

        return array_shift($result);
    }

    /**
     * Gets the single result of the query.
     *
     * Enforces the presence as well as the uniqueness of the result.
     *
     * If the result is not unique, a NonUniqueResultException is thrown.
     * If there is no result, a NoResultException is thrown.
     *
     * @param string|int $hydrationMode
     *
     * @return mixed
     *
     * @throws NonUniqueResultException If the query result is not unique.
     * @throws NoResultException        If the query returned no result.
     */
    public function getSingleResult($hydrationMode = null)
    {
        $result = $this->execute(null, $hydrationMode);

        if ($this->hydrationMode !== self::HYDRATE_SINGLE_SCALAR && ! $result) {
            throw new NoResultException();
        }

        if (! is_array($result)) {
            return $result;
        }

        if (count($result) > 1) {
            throw new NonUniqueResultException();
        }

        return array_shift($result);
    }

    /**
     * Gets the single scalar result of the query.
     *
     * Alias for getSingleResult(HYDRATE_SINGLE_SCALAR).
     *
     * @return mixed
     *
     * @throws NonUniqueResultException If the query result is not unique.
     * @throws NoResultException        If the query returned no result.
     */
    public function getSingleScalarResult()
    {
        return $this->getSingleResult(self::HYDRATE_SINGLE_SCALAR);
    }

    /**
     * Sets a query hint. If the hint name is not recognized, it is silently ignored.
     *
     * @param string $name  The name of the hint.
     * @param mixed  $value The value of the hint.
     *
     * @return static This query instance.
     */
    public function setHint($name, $value)
    {
        $this->hints[$name] = $value;

        return $this;
    }

    /**
     * Gets the value of a query hint. If the hint name is not recognized, FALSE is returned.
     *
     * @param string $name The name of the hint.
     *
     * @return mixed The value of the hint or FALSE, if the hint name is not recognized.
     */
    public function getHint($name)
    {
        return $this->hints[$name] ?? false;
    }

    /**
     * Check if the query has a hint
     *
     * @param string $name The name of the hint
     *
     * @return bool False if the query does not have any hint
     */
    public function hasHint($name)
    {
        return isset($this->hints[$name]);
    }

    /**
     * Return the key value map of query hints that are currently set.
     *
     * @return mixed[]
     */
    public function getHints()
    {
        return $this->hints;
    }

    /**
     * Executes the query and returns an IterableResult that can be used to incrementally
     * iterate over the result.
     *
     * @param ArrayCollection|mixed[]|null $parameters    The query parameters.
     * @param string|int|null              $hydrationMode The hydration mode to use.
     *
     * @return IterableResult
     */
    public function iterate($parameters = null, $hydrationMode = null)
    {
        if ($hydrationMode !== null) {
            $this->setHydrationMode($hydrationMode);
        }

        if (! empty($parameters)) {
            $this->setParameters($parameters);
        }

        $rsm  = $this->getResultSetMapping();
        $stmt = $this->doExecute();

        return $this->em->newHydrator($this->hydrationMode)->iterate($stmt, $rsm, $this->hints);
    }

    /**
     * Executes the query.
     *
     * @param ArrayCollection|mixed[]|null $parameters    Query parameters.
     * @param string|int|null              $hydrationMode Processing mode to be used during the hydration process.
     *
     * @return mixed
     */
    public function execute($parameters = null, $hydrationMode = null)
    {
        return $this->isCacheEnabled()
            ? $this->executeUsingQueryCache($parameters, $hydrationMode)
            : $this->executeIgnoreQueryCache($parameters, $hydrationMode);
    }

    /**
     * Execute query ignoring second level cache.
     *
     * @param ArrayCollection|mixed[]|null $parameters
     * @param string|int|null              $hydrationMode
     *
     * @return mixed
     */
    private function executeIgnoreQueryCache($parameters = null, $hydrationMode = null)
    {
        if ($hydrationMode !== null) {
            $this->setHydrationMode($hydrationMode);
        }

        if (! empty($parameters)) {
            $this->setParameters($parameters);
        }

        $setCacheEntry = static function () {
        };

        if ($this->hydrationCacheProfile !== null) {
            [$cacheKey, $realCacheKey] = $this->getHydrationCacheId();

            $queryCacheProfile = $this->getHydrationCacheProfile();
            $cache             = $queryCacheProfile->getResultCacheDriver();
            $result            = $cache->fetch($cacheKey);

            if (isset($result[$realCacheKey])) {
                return $result[$realCacheKey];
            }

            if (! $result) {
                $result = [];
            }

            $setCacheEntry = static function ($data) use ($cache, $result, $cacheKey, $realCacheKey, $queryCacheProfile) {
                $result[$realCacheKey] = $data;

                $cache->save($cacheKey, $result, $queryCacheProfile->getLifetime());
            };
        }

        $stmt = $this->doExecute();

        if (is_numeric($stmt)) {
            $setCacheEntry($stmt);

            return $stmt;
        }

        $rsm  = $this->getResultSetMapping();
        $data = $this->em->newHydrator($this->hydrationMode)->hydrateAll($stmt, $rsm, $this->hints);

        $setCacheEntry($data);

        return $data;
    }

    /**
     * Load from second level cache or executes the query and put into cache.
     *
     * @param ArrayCollection|mixed[]|null $parameters
     * @param string|int|null              $hydrationMode
     *
     * @return mixed
     */
    private function executeUsingQueryCache($parameters = null, $hydrationMode = null)
    {
        $rsm        = $this->getResultSetMapping();
        $queryCache = $this->em->getCache()->getQueryCache($this->cacheRegion);
        $queryKey   = new QueryCacheKey(
            $this->getHash(),
            $this->lifetime,
            $this->cacheMode ?: Cache::MODE_NORMAL,
            $this->getTimestampKey()
        );

        $result = $queryCache->get($queryKey, $rsm, $this->hints);

        if ($result !== null) {
            if ($this->cacheLogger) {
                $this->cacheLogger->queryCacheHit($queryCache->getRegion()->getName(), $queryKey);
            }

            return $result;
        }

        $result = $this->executeIgnoreQueryCache($parameters, $hydrationMode);
        $cached = $queryCache->put($queryKey, $rsm, $result, $this->hints);

        if ($this->cacheLogger) {
            $this->cacheLogger->queryCacheMiss($queryCache->getRegion()->getName(), $queryKey);

            if ($cached) {
                $this->cacheLogger->queryCachePut($queryCache->getRegion()->getName(), $queryKey);
            }
        }

        return $result;
    }

    /**
     * @return TimestampCacheKey|null
     */
    private function getTimestampKey()
    {
        $entityName = reset($this->resultSetMapping->aliasMap);

        if (empty($entityName)) {
            return null;
        }

        $metadata = $this->em->getClassMetadata($entityName);

        return new Cache\TimestampCacheKey($metadata->getRootClassName());
    }

    /**
     * Get the result cache id to use to store the result set cache entry.
     * Will return the configured id if it exists otherwise a hash will be
     * automatically generated for you.
     *
     * @return string[] ($key, $hash)
     */
    protected function getHydrationCacheId()
    {
        $parameters = [];

        foreach ($this->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $this->processParameterValue($parameter->getValue());
        }

        $sql                    = $this->getSQL();
        $queryCacheProfile      = $this->getHydrationCacheProfile();
        $hints                  = $this->getHints();
        $hints['hydrationMode'] = $this->getHydrationMode();

        ksort($hints);

        return $queryCacheProfile->generateCacheKeys($sql, $parameters, $hints);
    }

    /**
     * Set the result cache id to use to store the result set cache entry.
     * If this is not explicitly set by the developer then a hash is automatically
     * generated for you.
     *
     * @param string $id
     *
     * @return static This query instance.
     */
    public function setResultCacheId($id)
    {
        $this->queryCacheProfile = $this->queryCacheProfile
            ? $this->queryCacheProfile->setCacheKey($id)
            : new QueryCacheProfile(0, $id, $this->em->getConfiguration()->getResultCacheImpl());

        return $this;
    }

    /**
     * Get the result cache id to use to store the result set cache entry if set.
     *
     * @deprecated
     *
     * @return string
     */
    public function getResultCacheId()
    {
        return $this->queryCacheProfile ? $this->queryCacheProfile->getCacheKey() : null;
    }

    /**
     * Executes the query and returns a the resulting Statement object.
     *
     * @return Statement The executed database statement that holds the results.
     */
    abstract protected function doExecute();

    /**
     * Cleanup Query resource when clone is called.
     */
    public function __clone()
    {
        $this->parameters = new ArrayCollection();
        $this->hints      = $this->em->getConfiguration()->getDefaultQueryHints();
    }

    /**
     * Generates a string of currently query to use for the cache second level cache.
     *
     * @return string
     */
    protected function getHash()
    {
        $query  = $this->getSQL();
        $hints  = $this->getHints();
        $params = array_map(function (Parameter $parameter) {
            $value = $parameter->getValue();

            // Small optimization
            // Does not invoke processParameterValue for scalar values
            if (is_scalar($value)) {
                return $value;
            }

            return $this->processParameterValue($value);
        }, $this->parameters->getValues());

        ksort($hints);

        return sha1($query . '-' . serialize($params) . '-' . serialize($hints));
    }
}
