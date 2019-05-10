<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\AbstractSqlExecutor;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Utility\HierarchyDiscriminatorResolver;
use function array_keys;
use function array_values;
use function count;
use function in_array;
use function ksort;
use function md5;
use function reset;
use function serialize;
use function sha1;
use function stripos;

/**
 * A Query object represents a DQL query.
 */
final class Query extends AbstractQuery
{
    /**
     * A query object is in CLEAN state when it has NO unparsed/unprocessed DQL parts.
     */
    public const STATE_CLEAN = 1;

    /**
     * A query object is in state DIRTY when it has DQL parts that have not yet been
     * parsed/processed. This is automatically defined as DIRTY when addDqlQueryPart
     * is called.
     */
    public const STATE_DIRTY = 2;

    /* Query HINTS */

    /**
     * The refresh hint turns any query into a refresh query with the result that
     * any local changes in entities are overridden with the fetched values.
     */
    public const HINT_REFRESH = 'doctrine.refresh';

    public const HINT_CACHE_ENABLED = 'doctrine.cache.enabled';

    public const HINT_CACHE_EVICT = 'doctrine.cache.evict';

    /**
     * Internal hint: is set to the proxy entity that is currently triggered for loading
     */
    public const HINT_REFRESH_ENTITY = 'doctrine.refresh.entity';

    /**
     * The forcePartialLoad query hint forces a particular query to return
     * partial objects.
     *
     * @todo Rename: HINT_OPTIMIZE
     */
    public const HINT_FORCE_PARTIAL_LOAD = 'doctrine.forcePartialLoad';

    /**
     * The includeMetaColumns query hint causes meta columns like foreign keys and
     * discriminator columns to be selected and returned as part of the query result.
     *
     * This hint does only apply to non-object queries.
     */
    public const HINT_INCLUDE_META_COLUMNS = 'doctrine.includeMetaColumns';

    /**
     * An array of class names that implement \Doctrine\ORM\Query\TreeWalker and
     * are iterated and executed after the DQL has been parsed into an AST.
     */
    public const HINT_CUSTOM_TREE_WALKERS = 'doctrine.customTreeWalkers';

    /**
     * A string with a class name that implements \Doctrine\ORM\Query\TreeWalker
     * and is used for generating the target SQL from any DQL AST tree.
     */
    public const HINT_CUSTOM_OUTPUT_WALKER = 'doctrine.customOutputWalker';

    //const HINT_READ_ONLY = 'doctrine.readOnly';

    public const HINT_INTERNAL_ITERATION = 'doctrine.internal.iteration';

    public const HINT_LOCK_MODE = 'doctrine.lockMode';

    /**
     * The current state of this query.
     *
     * @var int
     */
    private $state = self::STATE_CLEAN;

    /**
     * A snapshot of the parameter types the query was parsed with.
     *
     * @var mixed[]
     */
    private $parsedTypes = [];

    /**
     * Cached DQL query.
     *
     * @var string
     */
    private $dql;

    /**
     * The parser result that holds DQL => SQL information.
     *
     * @var ParserResult
     */
    private $parserResult;

    /**
     * The first result to return (the "offset").
     *
     * @var int
     */
    private $firstResult;

    /**
     * The maximum number of results to return (the "limit").
     *
     * @var int|null
     */
    private $maxResults;

    /**
     * The cache driver used for caching queries.
     *
     * @var Cache|null
     */
    private $queryCache;

    /**
     * Whether or not expire the query cache.
     *
     * @var bool
     */
    private $expireQueryCache = false;

    /**
     * The query cache lifetime.
     *
     * @var int
     */
    private $queryCacheTTL;

    /**
     * Whether to use a query cache, if available. Defaults to TRUE.
     *
     * @var bool
     */
    private $useQueryCache = true;

    /**
     * Gets the SQL query/queries that correspond to this DQL query.
     *
     * @return mixed The built sql query or an array of all sql queries.
     *
     * @override
     */
    public function getSQL()
    {
        return $this->parse()->getSqlExecutor()->getSqlStatements();
    }

    /**
     * Returns the corresponding AST for this DQL query.
     *
     * @return SelectStatement|UpdateStatement|DeleteStatement
     */
    public function getAST()
    {
        $parser = new Parser($this);

        return $parser->getAST();
    }

    /**
     * {@inheritdoc}
     */
    protected function getResultSetMapping()
    {
        // parse query or load from cache
        if ($this->resultSetMapping === null) {
            $this->resultSetMapping = $this->parse()->getResultSetMapping();
        }

        return $this->resultSetMapping;
    }

    /**
     * Parses the DQL query, if necessary, and stores the parser result.
     *
     * Note: Populates $this->parserResult as a side-effect.
     *
     * @return ParserResult
     */
    private function parse()
    {
        $types = [];

        foreach ($this->parameters as $parameter) {
            /** @var Query\Parameter $parameter */
            $types[$parameter->getName()] = $parameter->getType();
        }

        // Return previous parser result if the query and the filter collection are both clean
        if ($this->state === self::STATE_CLEAN && $this->parsedTypes === $types && $this->em->isFiltersStateClean()) {
            return $this->parserResult;
        }

        $this->state       = self::STATE_CLEAN;
        $this->parsedTypes = $types;

        // Check query cache.
        $queryCache = $this->getQueryCacheDriver();
        if (! ($this->useQueryCache && $queryCache)) {
            $parser = new Parser($this);

            $this->parserResult = $parser->parse();

            return $this->parserResult;
        }

        $hash   = $this->getQueryCacheId();
        $cached = $this->expireQueryCache ? false : $queryCache->fetch($hash);

        if ($cached instanceof ParserResult) {
            // Cache hit.
            $this->parserResult = $cached;

            return $this->parserResult;
        }

        // Cache miss.
        $parser = new Parser($this);

        $this->parserResult = $parser->parse();

        $queryCache->save($hash, $this->parserResult, $this->queryCacheTTL);

        return $this->parserResult;
    }

    /**
     * {@inheritdoc}
     */
    protected function doExecute()
    {
        $executor = $this->parse()->getSqlExecutor();

        if ($this->queryCacheProfile) {
            $executor->setQueryCacheProfile($this->queryCacheProfile);
        } else {
            $executor->removeQueryCacheProfile();
        }

        if ($this->resultSetMapping === null) {
            $this->resultSetMapping = $this->parserResult->getResultSetMapping();
        }

        // Prepare parameters
        $paramMappings = $this->parserResult->getParameterMappings();
        $paramCount    = count($this->parameters);
        $mappingCount  = count($paramMappings);

        if ($paramCount < $mappingCount) {
            $paramNames = array_map(function($parameter) {
                return $parameter->getName();
            }, $this->parameters->toArray());
            throw QueryException::missingParameter($paramMappings, $paramNames);
        }

        // evict all cache for the entity region
        if ($this->hasCache && isset($this->hints[self::HINT_CACHE_EVICT]) && $this->hints[self::HINT_CACHE_EVICT]) {
            $this->evictEntityCacheRegion();
        }

        [$sqlParams, $types] = $this->processParameterMappings($paramMappings);

        $this->evictResultSetCache(
            $executor,
            $sqlParams,
            $types,
            $this->em->getConnection()->getParams()
        );

        return $executor->execute($this->em->getConnection(), $sqlParams, $types);
    }

    /**
     * @param mixed[] $sqlParams
     * @param mixed[] $types
     * @param mixed[] $connectionParams
     */
    private function evictResultSetCache(
        AbstractSqlExecutor $executor,
        array $sqlParams,
        array $types,
        array $connectionParams
    ) {
        if ($this->queryCacheProfile === null || ! $this->getExpireResultCache()) {
            return;
        }

        $cacheDriver = $this->queryCacheProfile->getResultCacheDriver();
        $statements  = (array) $executor->getSqlStatements(); // Type casted since it can either be a string or an array

        foreach ($statements as $statement) {
            $cacheKeys = $this->queryCacheProfile->generateCacheKeys($statement, $sqlParams, $types, $connectionParams);

            $cacheDriver->delete(reset($cacheKeys));
        }
    }

    /**
     * Evict entity cache region
     */
    private function evictEntityCacheRegion()
    {
        $AST = $this->getAST();

        if ($AST instanceof SelectStatement) {
            throw new QueryException('The hint "HINT_CACHE_EVICT" is not valid for select statements.');
        }

        $className = $AST instanceof DeleteStatement
            ? $AST->deleteClause->abstractSchemaName
            : $AST->updateClause->abstractSchemaName;

        $this->em->getCache()->evictEntityRegion($className);
    }

    /**
     * Processes query parameter mappings.
     *
     * @param mixed[] $paramMappings
     *
     * @return mixed[][]
     *
     * @throws Query\QueryException
     */
    private function processParameterMappings($paramMappings)
    {
        $sqlParams = [];
        $types     = [];

        foreach ($this->parameters as $parameter) {
            $key   = $parameter->getName();
            $value = $parameter->getValue();
            $rsm   = $this->getResultSetMapping();
            
            if (! isset($paramMappings[$key])) {
                throw QueryException::unknownParameter($key);
            }

            if (isset($rsm->metadataParameterMapping[$key]) && $value instanceof ClassMetadata) {
                $value = $value->getMetadataValue($rsm->metadataParameterMapping[$key]);
            }

            if (isset($rsm->discriminatorParameters[$key]) && $value instanceof ClassMetadata) {
                $value = array_keys(HierarchyDiscriminatorResolver::resolveDiscriminatorsForClass($value, $this->em));
            }

            $value = $this->processParameterValue($value);
            $type  = $parameter->getValue() === $value
                ? $parameter->getType()
                : ParameterTypeInferer::inferType($value);

            foreach ($paramMappings[$key] as $position) {
                $types[$position] = $type;
            }

            $sqlPositions      = $paramMappings[$key];
            $sqlPositionsCount = count($sqlPositions);

            // optimized multi value sql positions away for now,
            // they are not allowed in DQL anyways.
            $value      = [$value];
            $countValue = count($value);

            for ($i = 0, $l = $sqlPositionsCount; $i < $l; $i++) {
                $sqlParams[$sqlPositions[$i]] = $value[($i % $countValue)];
            }
        }

        if (count($sqlParams) !== count($types)) {
            throw QueryException::parameterTypeMismatch();
        }

        if ($sqlParams) {
            ksort($sqlParams);
            $sqlParams = array_values($sqlParams);

            ksort($types);
            $types = array_values($types);
        }

        return [$sqlParams, $types];
    }

    /**
     * Defines a cache driver to be used for caching queries.
     *
     * @param Cache|null $queryCache Cache driver.
     *
     * @return Query This query instance.
     */
    public function setQueryCacheDriver($queryCache)
    {
        $this->queryCache = $queryCache;

        return $this;
    }

    /**
     * Defines whether the query should make use of a query cache, if available.
     *
     * @param bool $bool
     *
     * @return Query This query instance.
     */
    public function useQueryCache($bool)
    {
        $this->useQueryCache = $bool;

        return $this;
    }

    /**
     * Returns the cache driver used for query caching.
     *
     * @return Cache|null The cache driver used for query caching or NULL, if
     *                                           this Query does not use query caching.
     */
    public function getQueryCacheDriver()
    {
        if ($this->queryCache) {
            return $this->queryCache;
        }

        return $this->em->getConfiguration()->getQueryCacheImpl();
    }

    /**
     * Defines how long the query cache will be active before expire.
     *
     * @param int $timeToLive How long the cache entry is valid.
     *
     * @return Query This query instance.
     */
    public function setQueryCacheLifetime($timeToLive)
    {
        if ($timeToLive !== null) {
            $timeToLive = (int) $timeToLive;
        }

        $this->queryCacheTTL = $timeToLive;

        return $this;
    }

    /**
     * Retrieves the lifetime of resultset cache.
     *
     * @return int
     */
    public function getQueryCacheLifetime()
    {
        return $this->queryCacheTTL;
    }

    /**
     * Defines if the query cache is active or not.
     *
     * @param bool $expire Whether or not to force query cache expiration.
     *
     * @return Query This query instance.
     */
    public function expireQueryCache($expire = true)
    {
        $this->expireQueryCache = $expire;

        return $this;
    }

    /**
     * Retrieves if the query cache is active or not.
     *
     * @return bool
     */
    public function getExpireQueryCache()
    {
        return $this->expireQueryCache;
    }

    public function free()
    {
        parent::free();

        $this->dql   = null;
        $this->state = self::STATE_CLEAN;
    }

    /**
     * Sets a DQL query string.
     *
     * @param string $dqlQuery DQL Query.
     *
     * @return AbstractQuery
     */
    public function setDQL($dqlQuery)
    {
        if ($dqlQuery !== null) {
            $this->dql   = $dqlQuery;
            $this->state = self::STATE_DIRTY;
        }

        return $this;
    }

    /**
     * Returns the DQL query that is represented by this query object.
     *
     * @return string DQL query.
     */
    public function getDQL()
    {
        return $this->dql;
    }

    /**
     * Returns the state of this query object
     * By default the type is Doctrine_ORM_Query_Abstract::STATE_CLEAN but if it appears any unprocessed DQL
     * part, it is switched to Doctrine_ORM_Query_Abstract::STATE_DIRTY.
     *
     * @see AbstractQuery::STATE_CLEAN
     * @see AbstractQuery::STATE_DIRTY
     *
     * @return int The query state.
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Method to check if an arbitrary piece of DQL exists
     *
     * @param string $dql Arbitrary piece of DQL to check for.
     *
     * @return bool
     */
    public function contains($dql)
    {
        return stripos($this->getDQL(), $dql) !== false;
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param int $firstResult The first result to return.
     *
     * @return Query This query object.
     */
    public function setFirstResult($firstResult)
    {
        $this->firstResult = $firstResult;
        $this->state       = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns NULL if {@link setFirstResult} was not applied to this query.
     *
     * @return int The position of the first result.
     */
    public function getFirstResult()
    {
        return $this->firstResult;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param int|null $maxResults
     *
     * @return Query This query object.
     */
    public function setMaxResults($maxResults)
    {
        $this->maxResults = $maxResults;
        $this->state      = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query.
     *
     * @return int|null Maximum number of results.
     */
    public function getMaxResults()
    {
        return $this->maxResults;
    }

    /**
     * Executes the query and returns an IterableResult that can be used to incrementally
     * iterated over the result.
     *
     * @param ArrayCollection|array|Parameter[]|mixed[]|null $parameters    The query parameters.
     * @param int                                            $hydrationMode The hydration mode to use.
     *
     * @return IterableResult
     */
    public function iterate($parameters = null, $hydrationMode = self::HYDRATE_OBJECT)
    {
        $this->setHint(self::HINT_INTERNAL_ITERATION, true);

        return parent::iterate($parameters, $hydrationMode);
    }

    /**
     * {@inheritdoc}
     */
    public function setHint($name, $value)
    {
        $this->state = self::STATE_DIRTY;

        return parent::setHint($name, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function setHydrationMode($hydrationMode)
    {
        $this->state = self::STATE_DIRTY;

        return parent::setHydrationMode($hydrationMode);
    }

    /**
     * Set the lock mode for this Query.
     *
     * @see \Doctrine\DBAL\LockMode
     *
     * @param int $lockMode
     *
     * @return Query
     *
     * @throws TransactionRequiredException
     */
    public function setLockMode($lockMode)
    {
        if (in_array($lockMode, [LockMode::NONE, LockMode::PESSIMISTIC_READ, LockMode::PESSIMISTIC_WRITE], true)) {
            if (! $this->em->getConnection()->isTransactionActive()) {
                throw TransactionRequiredException::transactionRequired();
            }
        }

        $this->setHint(self::HINT_LOCK_MODE, $lockMode);

        return $this;
    }

    /**
     * Get the current lock mode for this query.
     *
     * @return int|null The current lock mode of this query or NULL if no specific lock mode is set.
     */
    public function getLockMode()
    {
        $lockMode = $this->getHint(self::HINT_LOCK_MODE);

        if ($lockMode === false) {
            return null;
        }

        return $lockMode;
    }

    /**
     * Generate a cache id for the query cache - reusing the Result-Cache-Id generator.
     *
     * @return string
     */
    protected function getQueryCacheId()
    {
        ksort($this->hints);

        $platform = $this->getEntityManager()
            ->getConnection()
            ->getDatabasePlatform()
            ->getName();

        return md5(
            $this->getDQL() . serialize($this->hints) .
            '&platform=' . $platform .
            ($this->em->hasFilters() ? $this->em->getFilters()->getHash() : '') .
            '&firstResult=' . $this->firstResult . '&maxResult=' . $this->maxResults .
            '&hydrationMode=' . $this->hydrationMode . '&types=' . serialize($this->parsedTypes) . 'DOCTRINE_QUERY_CACHE_SALT'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getHash()
    {
        return sha1(parent::getHash() . '-' . $this->firstResult . '-' . $this->maxResults);
    }

    /**
     * Cleanup Query resource when clone is called.
     */
    public function __clone()
    {
        parent::__clone();

        $this->state = self::STATE_DIRTY;
    }
}
