<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
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
use function assert;
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

    /**
     * Marks queries as creating only read only objects.
     *
     * If the object retrieved from the query is already in the identity map
     * then it does not get marked as read only if it wasn't already.
     */
    public const HINT_READ_ONLY = 'doctrine.readOnly';

    public const HINT_INTERNAL_ITERATION = 'doctrine.internal.iteration';

    public const HINT_LOCK_MODE = 'doctrine.lockMode';

    /**
     * The current state of this query.
     *
     * @var int
     */
    private $_state = self::STATE_DIRTY;

    /**
     * A snapshot of the parameter types the query was parsed with.
     *
     * @var array<string,Type>
     */
    private $parsedTypes = [];

    /**
     * Cached DQL query.
     *
     * @var string|null
     */
    private $dql = null;

    /**
     * The parser result that holds DQL => SQL information.
     *
     * @var ParserResult
     */
    private $parserResult;

    /**
     * The first result to return (the "offset").
     *
     * @var int|null
     */
    private $firstResult = null;

    /**
     * The maximum number of results to return (the "limit").
     *
     * @var int|null
     */
    private $maxResults = null;

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
        if ($this->_resultSetMapping === null) {
            $this->_resultSetMapping = $this->parse()->getResultSetMapping();
        }

        return $this->_resultSetMapping;
    }

    /**
     * Parses the DQL query, if necessary, and stores the parser result.
     *
     * Note: Populates $this->_parserResult as a side-effect.
     */
    private function parse(): ParserResult
    {
        $types = [];

        foreach ($this->parameters as $parameter) {
            /** @var Query\Parameter $parameter */
            $types[$parameter->getName()] = $parameter->getType();
        }

        // Return previous parser result if the query and the filter collection are both clean
        if ($this->_state === self::STATE_CLEAN && $this->parsedTypes === $types && $this->_em->isFiltersStateClean()) {
            return $this->parserResult;
        }

        $this->_state      = self::STATE_CLEAN;
        $this->parsedTypes = $types;

        $queryCache = $this->getQueryCacheDriver();
        // Check query cache.
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
    protected function _doExecute()
    {
        $executor = $this->parse()->getSqlExecutor();

        if ($this->_queryCacheProfile) {
            $executor->setQueryCacheProfile($this->_queryCacheProfile);
        } else {
            $executor->removeQueryCacheProfile();
        }

        if ($this->_resultSetMapping === null) {
            $this->_resultSetMapping = $this->parserResult->getResultSetMapping();
        }

        // Prepare parameters
        $paramMappings = $this->parserResult->getParameterMappings();
        $paramCount    = count($this->parameters);
        $mappingCount  = count($paramMappings);

        if ($paramCount > $mappingCount) {
            throw QueryException::tooManyParameters($mappingCount, $paramCount);
        }

        if ($paramCount < $mappingCount) {
            throw QueryException::tooFewParameters($mappingCount, $paramCount);
        }

        // evict all cache for the entity region
        if ($this->hasCache && isset($this->_hints[self::HINT_CACHE_EVICT]) && $this->_hints[self::HINT_CACHE_EVICT]) {
            $this->evictEntityCacheRegion();
        }

        [$sqlParams, $types] = $this->processParameterMappings($paramMappings);

        $this->evictResultSetCache(
            $executor,
            $sqlParams,
            $types,
            $this->_em->getConnection()->getParams()
        );

        return $executor->execute($this->_em->getConnection(), $sqlParams, $types);
    }

    /**
     * @param array<string,mixed> $sqlParams
     * @param array<string,Type>  $types
     * @param array<string,mixed> $connectionParams
     */
    private function evictResultSetCache(
        AbstractSqlExecutor $executor,
        array $sqlParams,
        array $types,
        array $connectionParams
    ): void {
        if ($this->_queryCacheProfile === null || ! $this->getExpireResultCache()) {
            return;
        }

        $cacheDriver = $this->_queryCacheProfile->getResultCacheDriver();
        $statements  = (array) $executor->getSqlStatements(); // Type casted since it can either be a string or an array

        foreach ($statements as $statement) {
            $cacheKeys = $this->_queryCacheProfile->generateCacheKeys($statement, $sqlParams, $types, $connectionParams);

            $cacheDriver->delete(reset($cacheKeys));
        }
    }

    /**
     * Evict entity cache region
     */
    private function evictEntityCacheRegion(): void
    {
        $AST = $this->getAST();

        if ($AST instanceof SelectStatement) {
            throw new QueryException('The hint "HINT_CACHE_EVICT" is not valid for select statements.');
        }

        $className = $AST instanceof DeleteStatement
            ? $AST->deleteClause->abstractSchemaName
            : $AST->updateClause->abstractSchemaName;

        $this->_em->getCache()->evictEntityRegion($className);
    }

    /**
     * Processes query parameter mappings.
     *
     * @param array<list<int>> $paramMappings
     *
     * @return mixed[][]
     * @psalm-return array{0: list<mixed>, 1: array}
     *
     * @throws Query\QueryException
     */
    private function processParameterMappings(array $paramMappings): array
    {
        $sqlParams = [];
        $types     = [];

        foreach ($this->parameters as $parameter) {
            $key = $parameter->getName();

            if (! isset($paramMappings[$key])) {
                throw QueryException::unknownParameter($key);
            }

            [$value, $type] = $this->resolveParameterValue($parameter);

            foreach ($paramMappings[$key] as $position) {
                $types[$position] = $type;
            }

            $sqlPositions = $paramMappings[$key];

            // optimized multi value sql positions away for now,
            // they are not allowed in DQL anyways.
            $value      = [$value];
            $countValue = count($value);

            for ($i = 0, $l = count($sqlPositions); $i < $l; $i++) {
                $sqlParams[$sqlPositions[$i]] = $value[$i % $countValue];
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
     * @return mixed[] tuple of (value, type)
     * @psalm-return array{0: mixed, 1: mixed}
     */
    private function resolveParameterValue(Parameter $parameter): array
    {
        if ($parameter->typeWasSpecified()) {
            return [$parameter->getValue(), $parameter->getType()];
        }

        $key           = $parameter->getName();
        $originalValue = $parameter->getValue();
        $value         = $originalValue;
        $rsm           = $this->getResultSetMapping();

        assert($rsm !== null);

        if ($value instanceof ClassMetadata && isset($rsm->metadataParameterMapping[$key])) {
            $value = $value->getMetadataValue($rsm->metadataParameterMapping[$key]);
        }

        if ($value instanceof ClassMetadata && isset($rsm->discriminatorParameters[$key])) {
            $value = array_keys(HierarchyDiscriminatorResolver::resolveDiscriminatorsForClass($value, $this->_em));
        }

        $processedValue = $this->processParameterValue($value);

        return [
            $processedValue,
            $originalValue === $processedValue
                ? $parameter->getType()
                : ParameterTypeInferer::inferType($processedValue),
        ];
    }

    /**
     * Defines a cache driver to be used for caching queries.
     *
     * @param Cache|null $queryCache Cache driver.
     *
     * @return self This query instance.
     */
    public function setQueryCacheDriver($queryCache): self
    {
        $this->queryCache = $queryCache;

        return $this;
    }

    /**
     * Defines whether the query should make use of a query cache, if available.
     *
     * @param bool $bool
     *
     * @return self This query instance.
     */
    public function useQueryCache($bool): self
    {
        $this->useQueryCache = $bool;

        return $this;
    }

    /**
     * Returns the cache driver used for query caching.
     *
     * @return Cache|null The cache driver used for query caching or NULL, if
     * this Query does not use query caching.
     */
    public function getQueryCacheDriver(): ?Cache
    {
        if ($this->queryCache) {
            return $this->queryCache;
        }

        return $this->_em->getConfiguration()->getQueryCacheImpl();
    }

    /**
     * Defines how long the query cache will be active before expire.
     *
     * @param int $timeToLive How long the cache entry is valid.
     *
     * @return self This query instance.
     */
    public function setQueryCacheLifetime($timeToLive): self
    {
        if ($timeToLive !== null) {
            $timeToLive = (int) $timeToLive;
        }

        $this->queryCacheTTL = $timeToLive;

        return $this;
    }

    /**
     * Retrieves the lifetime of resultset cache.
     */
    public function getQueryCacheLifetime(): ?int
    {
        return $this->queryCacheTTL;
    }

    /**
     * Defines if the query cache is active or not.
     *
     * @param bool $expire Whether or not to force query cache expiration.
     *
     * @return self This query instance.
     */
    public function expireQueryCache($expire = true): self
    {
        $this->expireQueryCache = $expire;

        return $this;
    }

    /**
     * Retrieves if the query cache is active or not.
     */
    public function getExpireQueryCache(): bool
    {
        return $this->expireQueryCache;
    }

    /**
     * @override
     */
    public function free(): void
    {
        parent::free();

        $this->dql    = null;
        $this->_state = self::STATE_CLEAN;
    }

    /**
     * Sets a DQL query string.
     *
     * @param string $dqlQuery DQL Query.
     */
    public function setDQL($dqlQuery): self
    {
        if ($dqlQuery !== null) {
            $this->dql    = $dqlQuery;
            $this->_state = self::STATE_DIRTY;
        }

        return $this;
    }

    /**
     * Returns the DQL query that is represented by this query object.
     */
    public function getDQL(): ?string
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
    public function getState(): int
    {
        return $this->_state;
    }

    /**
     * Method to check if an arbitrary piece of DQL exists
     *
     * @param string $dql Arbitrary piece of DQL to check for.
     */
    public function contains($dql): bool
    {
        return stripos($this->getDQL(), $dql) !== false;
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param int|null $firstResult The first result to return.
     *
     * @return self This query object.
     */
    public function setFirstResult($firstResult): self
    {
        $this->firstResult = $firstResult;
        $this->_state      = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns NULL if {@link setFirstResult} was not applied to this query.
     *
     * @return int|null The position of the first result.
     */
    public function getFirstResult(): ?int
    {
        return $this->firstResult;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @param int|null $maxResults
     *
     * @return self This query object.
     */
    public function setMaxResults($maxResults): self
    {
        $this->maxResults = $maxResults;
        $this->_state     = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query.
     *
     * @return int|null Maximum number of results.
     */
    public function getMaxResults(): ?int
    {
        return $this->maxResults;
    }

    /**
     * Executes the query and returns an IterableResult that can be used to incrementally
     * iterated over the result.
     *
     * @deprecated
     *
     * @param ArrayCollection|mixed[]|null $parameters    The query parameters.
     * @param string|int                   $hydrationMode The hydration mode to use.
     * @psalm-param ArrayCollection<int, Parameter>|array<string, mixed>|null $parameters
     */
    public function iterate($parameters = null, $hydrationMode = self::HYDRATE_OBJECT): IterableResult
    {
        $this->setHint(self::HINT_INTERNAL_ITERATION, true);

        return parent::iterate($parameters, $hydrationMode);
    }

    /** {@inheritDoc} */
    public function toIterable(iterable $parameters = [], $hydrationMode = self::HYDRATE_OBJECT): iterable
    {
        $this->setHint(self::HINT_INTERNAL_ITERATION, true);

        return parent::toIterable($parameters, $hydrationMode);
    }

    /**
     * {@inheritdoc}
     */
    public function setHint($name, $value): self
    {
        $this->_state = self::STATE_DIRTY;

        return parent::setHint($name, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function setHydrationMode($hydrationMode): self
    {
        $this->_state = self::STATE_DIRTY;

        return parent::setHydrationMode($hydrationMode);
    }

    /**
     * Set the lock mode for this Query.
     *
     * @see \Doctrine\DBAL\LockMode
     *
     * @param int $lockMode
     *
     * @throws TransactionRequiredException
     */
    public function setLockMode($lockMode): self
    {
        if (in_array($lockMode, [LockMode::NONE, LockMode::PESSIMISTIC_READ, LockMode::PESSIMISTIC_WRITE], true)) {
            if (! $this->_em->getConnection()->isTransactionActive()) {
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
    public function getLockMode(): ?int
    {
        $lockMode = $this->getHint(self::HINT_LOCK_MODE);

        if ($lockMode === false) {
            return null;
        }

        return $lockMode;
    }

    /**
     * Generate a cache id for the query cache - reusing the Result-Cache-Id generator.
     */
    protected function getQueryCacheId(): string
    {
        ksort($this->_hints);

        $platform = $this->getEntityManager()
            ->getConnection()
            ->getDatabasePlatform()
            ->getName();

        return md5(
            $this->getDQL() . serialize($this->_hints) .
            '&platform=' . $platform .
            ($this->_em->hasFilters() ? $this->_em->getFilters()->getHash() : '') .
            '&firstResult=' . $this->firstResult . '&maxResult=' . $this->maxResults .
            '&hydrationMode=' . $this->_hydrationMode . '&types=' . serialize($this->parsedTypes) . 'DOCTRINE_QUERY_CACHE_SALT'
        );
    }

    protected function getHash(): string
    {
        return sha1(parent::getHash() . '-' . $this->firstResult . '-' . $this->maxResults);
    }

    /**
     * Cleanup Query resource when clone is called.
     */
    public function __clone()
    {
        parent::__clone();

        $this->_state = self::STATE_DIRTY;
    }
}
