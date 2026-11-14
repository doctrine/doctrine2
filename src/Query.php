<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\AbstractSqlExecutor;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Utility\HierarchyDiscriminatorResolver;
use Psr\Cache\CacheItemPoolInterface;

use function array_keys;
use function array_values;
use function assert;
use function count;
use function get_debug_type;
use function in_array;
use function is_a;
use function ksort;
use function md5;
use function reset;
use function serialize;
use function sha1;
use function stripos;

/**
 * A Query object represents a DQL query.
 *
 * @final
 */
class Query extends AbstractQuery
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
     * A list of comments to prepend, as SQL comments, to every SQL statement the query executes.
     *
     * Comments are part of the query cache key, so avoid putting values that change on every
     * request (like request IDs) in them when using a query cache.
     */
    public const HINT_SQL_COMMENTS = 'doctrine.sqlComments';

    /**
     * The current state of this query.
     *
     * @phpstan-var self::STATE_*
     */
    private int $state = self::STATE_DIRTY;

    /**
     * A snapshot of the parameter types the query was parsed with.
     *
     * @var array<string,Type>
     */
    private array $parsedTypes = [];

    /**
     * Cached DQL query.
     */
    private string|null $dql = null;

    /**
     * The parser result that holds DQL => SQL information.
     */
    private ParserResult $parserResult;

    /**
     * The first result to return (the "offset").
     */
    private int $firstResult = 0;

    /**
     * The maximum number of results to return (the "limit").
     */
    private int|null $maxResults = null;

    /**
     * The cache driver used for caching queries.
     */
    private CacheItemPoolInterface|null $queryCache = null;

    /**
     * Whether or not expire the query cache.
     */
    private bool $expireQueryCache = false;

    /**
     * The query cache lifetime.
     */
    private int|null $queryCacheTTL = null;

    /**
     * Whether to use a query cache, if available. Defaults to TRUE.
     */
    private bool $useQueryCache = true;

    /**
     * Gets the SQL query/queries that correspond to this DQL query.
     *
     * @return list<string>|string The built sql query or an array of all sql queries.
     */
    public function getSQL(): string|array
    {
        return $this->getSqlExecutor()->getSqlStatements();
    }

    /**
     * Returns the corresponding AST for this DQL query.
     */
    public function getAST(): SelectStatement|UpdateStatement|DeleteStatement
    {
        $parser = new Parser($this);

        return $parser->getAST();
    }

    protected function getResultSetMapping(): ResultSetMapping
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
        if ($this->state === self::STATE_CLEAN && $this->parsedTypes === $types && $this->em->isFiltersStateClean()) {
            return $this->parserResult;
        }

        $this->state       = self::STATE_CLEAN;
        $this->parsedTypes = $types;

        $queryCache = $this->queryCache ?? $this->em->getConfiguration()->getQueryCache();
        // Check query cache.
        if (! ($this->useQueryCache && $queryCache)) {
            $parser = new Parser($this);

            $this->parserResult = $parser->parse();

            return $this->parserResult;
        }

        $cacheItem = $queryCache->getItem($this->getQueryCacheId());

        if (! $this->expireQueryCache && $cacheItem->isHit()) {
            $cached = $cacheItem->get();
            if ($cached instanceof ParserResult) {
                // Cache hit.
                $this->parserResult = $cached;

                return $this->parserResult;
            }
        }

        // Cache miss.
        $parser = new Parser($this);

        $this->parserResult = $parser->parse();

        $queryCache->save($cacheItem->set($this->parserResult)->expiresAfter($this->queryCacheTTL));

        return $this->parserResult;
    }

    protected function _doExecute(): Result|int
    {
        $executor = $this->getSqlExecutor();

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

        if ($paramCount > $mappingCount) {
            throw QueryException::tooManyParameters($mappingCount, $paramCount);
        }

        if ($paramCount < $mappingCount) {
            throw QueryException::tooFewParameters($mappingCount, $paramCount);
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
            $this->em->getConnection()->getParams(),
        );

        return $executor->execute($this->em->getConnection(), $sqlParams, $types);
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
        array $connectionParams,
    ): void {
        if ($this->queryCacheProfile === null || ! $this->getExpireResultCache()) {
            return;
        }

        $cache = $this->queryCacheProfile->getResultCache();

        assert($cache !== null);

        $statements = (array) $executor->getSqlStatements(); // Type casted since it can either be a string or an array

        foreach ($statements as $statement) {
            $cacheKeys = $this->queryCacheProfile->generateCacheKeys($statement, $sqlParams, $types, $connectionParams);
            $cache->deleteItem(reset($cacheKeys));
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

        $this->em->getCache()->evictEntityRegion($className);
    }

    /**
     * Processes query parameter mappings.
     *
     * @param array<list<int>> $paramMappings
     *
     * @return mixed[][]
     * @phpstan-return array{0: list<mixed>, 1: array}
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
     * @phpstan-return array{0: mixed, 1: mixed}
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
        $parsedRsm     = $this->parserResult->getResultSetMapping();

        if ($value instanceof ClassMetadata && isset($rsm->metadataParameterMapping[$key])) {
            $value = $value->getMetadataValue($rsm->metadataParameterMapping[$key]);
        }

        if (
            $value instanceof ClassMetadata
            && (isset($parsedRsm->discriminatorParameters[$key]) || isset($rsm->discriminatorParameters[$key]))
        ) {
            $value = array_keys(HierarchyDiscriminatorResolver::resolveDiscriminatorsForClass($value, $this->em));
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
     * @return $this
     */
    public function setQueryCache(CacheItemPoolInterface|null $queryCache): self
    {
        $this->queryCache = $queryCache;

        return $this;
    }

    /**
     * Defines whether the query should make use of a query cache, if available.
     *
     * @return $this
     */
    public function useQueryCache(bool $bool): self
    {
        $this->useQueryCache = $bool;

        return $this;
    }

    /**
     * Defines how long the query cache will be active before expire.
     *
     * @param int|null $timeToLive How long the cache entry is valid.
     *
     * @return $this
     */
    public function setQueryCacheLifetime(int|null $timeToLive): self
    {
        $this->queryCacheTTL = $timeToLive;

        return $this;
    }

    /**
     * Retrieves the lifetime of resultset cache.
     */
    public function getQueryCacheLifetime(): int|null
    {
        return $this->queryCacheTTL;
    }

    /**
     * Defines if the query cache is active or not.
     *
     * @return $this
     */
    public function expireQueryCache(bool $expire = true): self
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

    public function free(): void
    {
        parent::free();

        $this->dql   = null;
        $this->state = self::STATE_CLEAN;
    }

    /**
     * Sets a DQL query string.
     */
    public function setDQL(string $dqlQuery): self
    {
        $this->dql   = $dqlQuery;
        $this->state = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Returns the DQL query that is represented by this query object.
     */
    public function getDQL(): string|null
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
     * @phpstan-return self::STATE_* The query state.
     */
    public function getState(): int
    {
        return $this->state;
    }

    /**
     * Method to check if an arbitrary piece of DQL exists
     *
     * @param string $dql Arbitrary piece of DQL to check for.
     */
    public function contains(string $dql): bool
    {
        return stripos($this->getDQL(), $dql) !== false;
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @param int $firstResult The first result to return.
     *
     * @return $this
     */
    public function setFirstResult(int $firstResult): self
    {
        $this->firstResult = $firstResult;
        $this->state       = self::STATE_DIRTY;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
     * Returns 0 if {@link setFirstResult} was not applied to this query.
     *
     * @return int The position of the first result.
     */
    public function getFirstResult(): int
    {
        return $this->firstResult;
    }

    /**
     * Sets the maximum number of results to retrieve (the "limit").
     *
     * @return $this
     */
    public function setMaxResults(int|null $maxResults): self
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
    public function getMaxResults(): int|null
    {
        return $this->maxResults;
    }

    /** {@inheritDoc} */
    public function toIterable(iterable $parameters = [], $hydrationMode = self::HYDRATE_OBJECT): iterable
    {
        $this->setHint(self::HINT_INTERNAL_ITERATION, true);

        return parent::toIterable($parameters, $hydrationMode);
    }

    public function setHint(string $name, mixed $value): static
    {
        $this->state = self::STATE_DIRTY;

        return parent::setHint($name, $value);
    }

    public function setHydrationMode(string|int $hydrationMode): static
    {
        $this->state = self::STATE_DIRTY;

        return parent::setHydrationMode($hydrationMode);
    }

    /**
     * Set the lock mode for this Query.
     *
     * @see \Doctrine\DBAL\LockMode
     *
     * @phpstan-param LockMode::* $lockMode
     *
     * @return $this
     *
     * @throws TransactionRequiredException
     */
    public function setLockMode(LockMode|int $lockMode): self
    {
        if (in_array($lockMode, [LockMode::PESSIMISTIC_READ, LockMode::PESSIMISTIC_WRITE], true)) {
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
     * @return LockMode|int|null The current lock mode of this query or NULL if no specific lock mode is set.
     * @phpstan-return LockMode::*|null
     */
    public function getLockMode(): LockMode|int|null
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
        ksort($this->hints);

        if (! $this->hasHint(self::HINT_CUSTOM_OUTPUT_WALKER)) {
            // Assume Parser will create the SqlOutputWalker; save is_a call, which might trigger a class load
            $firstAndMaxResult = '';
        } else {
            $outputWalkerClass = $this->getHint(self::HINT_CUSTOM_OUTPUT_WALKER);
            if (is_a($outputWalkerClass, OutputWalker::class, true)) {
                $firstAndMaxResult = '';
            } else {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/pull/11188/',
                    'Your output walker class %s should implement %s in order to provide a %s. This also means the output walker should not use the query firstResult/maxResult values, which should be read from the query by the SqlFinalizer only.',
                    $outputWalkerClass,
                    OutputWalker::class,
                    SqlFinalizer::class,
                );
                $firstAndMaxResult = '&firstResult=' . $this->firstResult . '&maxResult=' . $this->maxResults;
            }
        }

        return md5(
            $this->getDQL() . serialize($this->hints) .
            '&platform=' . get_debug_type($this->getEntityManager()->getConnection()->getDatabasePlatform()) .
            ($this->em->hasFilters() ? $this->em->getFilters()->getHash() : '') .
            $firstAndMaxResult .
            '&hydrationMode=' . $this->hydrationMode . '&types=' . serialize($this->parsedTypes) . 'DOCTRINE_QUERY_CACHE_SALT',
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

        $this->state = self::STATE_DIRTY;
    }

    private function getSqlExecutor(): AbstractSqlExecutor
    {
        return $this->parse()->prepareSqlExecutor($this);
    }
}
