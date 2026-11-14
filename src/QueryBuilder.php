<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Internal\NoUnknownNamedArguments;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryExpressionVisitor;
use InvalidArgumentException;
use Override;
use RuntimeException;
use SortDirection;
use Stringable;

use function array_keys;
use function array_unshift;
use function assert;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;
use function key;
use function reset;
use function sprintf;
use function str_starts_with;
use function strpos;
use function strrpos;
use function substr;

/**
 * This class is responsible for building DQL query strings via an object oriented
 * PHP interface.
 */
class QueryBuilder implements Stringable
{
    use NoUnknownNamedArguments;

    /**
     * The array of DQL parts collected.
     *
     * @phpstan-var array<string, mixed>
     */
    private array $dqlParts = [
        'distinct' => false,
        'select'  => [],
        'from'    => [],
        'join'    => [],
        'set'     => [],
        'where'   => null,
        'groupBy' => [],
        'having'  => null,
        'orderBy' => [],
    ];

    private QueryType $type = QueryType::Select;

    /**
     * The complete DQL string for this query.
     */
    private string|null $dql = null;

    /**
     * The query parameters.
     *
     * @phpstan-var ArrayCollection<int, Parameter>
     */
    private ArrayCollection $parameters;

    /**
     * The index of the first result to retrieve.
     */
    private int $firstResult = 0;

    /**
     * The maximum number of results to retrieve.
     */
    private int|null $maxResults = null;

    /**
     * Keeps root entity alias names for join entities.
     *
     * @phpstan-var array<string, string>
     */
    private array $joinRootAliases = [];

    /**
     * Whether to use second level cache, if available.
     */
    protected bool $cacheable = false;

    /**
     * Second level cache region name.
     */
    protected string|null $cacheRegion = null;

    /**
     * Second level query cache mode.
     *
     * @phpstan-var Cache::MODE_*|null
     */
    protected int|null $cacheMode = null;

    protected int $lifetime = 0;

    /**
     * The counter of bound parameters.
     *
     * @var int<0, max>
     */
    private int $boundCounter = 0;

    /**
     * The hints to set on the query.
     *
     * @var array<string, string|int|bool|iterable<mixed>|object>
     */
    private array $hints = [];

    /**
     * Initializes a new <tt>QueryBuilder</tt> that uses the given <tt>EntityManager</tt>.
     *
     * @param EntityManagerInterface $em The EntityManager to use.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->parameters = new ArrayCollection();
    }

    final protected function getType(): QueryType
    {
        return $this->type;
    }

    /**
     * Gets an ExpressionBuilder used for object-oriented construction of query expressions.
     * This producer method is intended for convenient inline usage. Example:
     *
     * <code>
     *     $qb = $em->createQueryBuilder();
     *     $qb
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where($qb->expr()->eq('u.id', 1));
     * </code>
     *
     * For more complex expression construction, consider storing the expression
     * builder object in a local variable.
     */
    public function expr(): Expr
    {
        return $this->em->getExpressionBuilder();
    }

    /**
     * Enable/disable second level query (result) caching for this query.
     *
     * @return $this
     */
    public function setCacheable(bool $cacheable): static
    {
        $this->cacheable = $cacheable;

        return $this;
    }

    /**
     * Are the query results enabled for second level cache?
     */
    public function isCacheable(): bool
    {
        return $this->cacheable;
    }

    /** @return $this */
    public function setCacheRegion(string $cacheRegion): static
    {
        $this->cacheRegion = $cacheRegion;

        return $this;
    }

    /**
     * Obtain the name of the second level query cache region in which query results will be stored
     *
     * @return string|null The cache region name; NULL indicates the default region.
     */
    public function getCacheRegion(): string|null
    {
        return $this->cacheRegion;
    }

    public function getLifetime(): int
    {
        return $this->lifetime;
    }

    /**
     * Sets the life-time for this query into second level cache.
     *
     * @return $this
     */
    public function setLifetime(int $lifetime): static
    {
        $this->lifetime = $lifetime;

        return $this;
    }

    /** @return array<string, string|int|bool|iterable<mixed>|object> */
    public function getHints(): array
    {
        return $this->hints;
    }

    /**
     * Gets the value of a query hint. If the hint name is not recognized, FALSE is returned.
     *
     * @return mixed The value of the hint or FALSE, if the hint name is not recognized.
     */
    public function getHint(string $name): mixed
    {
        return $this->hints[$name] ?? false;
    }

    public function hasHint(string $name): bool
    {
        return isset($this->hints[$name]);
    }

    /**
     * Adds hints for the query.
     *
     * @return $this
     */
    public function setHint(string $name, mixed $value): static
    {
        $this->hints[$name] = $value;

        return $this;
    }

    /** @phpstan-return Cache::MODE_*|null */
    public function getCacheMode(): int|null
    {
        return $this->cacheMode;
    }

    /**
     * @phpstan-param Cache::MODE_* $cacheMode
     *
     * @return $this
     */
    public function setCacheMode(int $cacheMode): static
    {
        $this->cacheMode = $cacheMode;

        return $this;
    }

    /**
     * Gets the associated EntityManager for this query builder.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /**
     * Gets the complete DQL string formed by the current specifications of this QueryBuilder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *     echo $qb->getDql(); // SELECT u FROM User u
     * </code>
     */
    public function getDQL(): string
    {
        return $this->dql ??= match ($this->type) {
            QueryType::Select => $this->getDQLForSelect(),
            QueryType::Delete => $this->getDQLForDelete(),
            QueryType::Update => $this->getDQLForUpdate(),
        };
    }

    /**
     * Constructs a Query instance from the current specifications of the builder.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *     $q = $qb->getQuery();
     *     $results = $q->execute();
     * </code>
     */
    public function getQuery(): Query
    {
        $parameters = clone $this->parameters;
        $query      = $this->em->createQuery($this->getDQL())
            ->setParameters($parameters)
            ->setFirstResult($this->firstResult)
            ->setMaxResults($this->maxResults);

        if ($this->lifetime) {
            $query->setLifetime($this->lifetime);
        }

        if ($this->cacheMode) {
            $query->setCacheMode($this->cacheMode);
        }

        if ($this->cacheable) {
            $query->setCacheable($this->cacheable);
        }

        if ($this->cacheRegion) {
            $query->setCacheRegion($this->cacheRegion);
        }

        foreach ($this->hints as $name => $value) {
            $query->setHint($name, $value);
        }

        return $query;
    }

    /**
     * Finds the root entity alias of the joined entity.
     *
     * @param string $alias       The alias of the new join entity
     * @param string $parentAlias The parent entity alias of the join relationship
     */
    private function findRootAlias(string $alias, string $parentAlias): string
    {
        if (in_array($parentAlias, $this->getRootAliases(), true)) {
            $rootAlias = $parentAlias;
        } elseif (isset($this->joinRootAliases[$parentAlias])) {
            $rootAlias = $this->joinRootAliases[$parentAlias];
        } else {
            // Should never happen with correct joining order. Might be
            // thoughtful to throw exception instead.
            $aliases = $this->getRootAliases();

            if (! isset($aliases[0])) {
                throw new RuntimeException('No alias was set before invoking getRootAlias().');
            }

            $rootAlias = $aliases[0];
        }

        $this->joinRootAliases[$alias] = $rootAlias;

        return $rootAlias;
    }

    /**
     * Gets the FIRST root alias of the query. This is the first entity alias involved
     * in the construction of the query.
     *
     * <code>
     * $qb = $em->createQueryBuilder()
     *     ->select('u')
     *     ->from('User', 'u');
     *
     * echo $qb->getRootAlias(); // u
     * </code>
     *
     * @deprecated Please use $qb->getRootAliases() instead.
     *
     * @throws RuntimeException
     */
    public function getRootAlias(): string
    {
        $aliases = $this->getRootAliases();

        if (! isset($aliases[0])) {
            throw new RuntimeException('No alias was set before invoking getRootAlias().');
        }

        return $aliases[0];
    }

    /**
     * Gets the root aliases of the query. This is the entity aliases involved
     * in the construction of the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *
     *     $qb->getRootAliases(); // array('u')
     * </code>
     *
     * @return string[]
     * @phpstan-return list<string>
     */
    public function getRootAliases(): array
    {
        $aliases = [];

        foreach ($this->dqlParts['from'] as &$fromClause) {
            if (is_string($fromClause)) {
                $spacePos = strrpos($fromClause, ' ');

                /** @phpstan-var class-string $from */
                $from  = substr($fromClause, 0, $spacePos);
                $alias = substr($fromClause, $spacePos + 1);

                $fromClause = new Query\Expr\From($from, $alias);
            }

            $aliases[] = $fromClause->getAlias();
        }

        return $aliases;
    }

    /**
     * Gets all the aliases that have been used in the query.
     * Including all select root aliases and join aliases
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->join('u.articles','a');
     *
     *     $qb->getAllAliases(); // array('u','a')
     * </code>
     *
     * @return string[]
     * @phpstan-return list<string>
     */
    public function getAllAliases(): array
    {
        return [...$this->getRootAliases(), ...array_keys($this->joinRootAliases)];
    }

    /**
     * Gets the root entities of the query. This is the entity classes involved
     * in the construction of the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     *
     *     $qb->getRootEntities(); // array('User')
     * </code>
     *
     * @return string[]
     * @phpstan-return list<class-string>
     */
    public function getRootEntities(): array
    {
        $entities = [];

        foreach ($this->dqlParts['from'] as &$fromClause) {
            if (is_string($fromClause)) {
                $spacePos = strrpos($fromClause, ' ');

                /** @phpstan-var class-string $from */
                $from  = substr($fromClause, 0, $spacePos);
                $alias = substr($fromClause, $spacePos + 1);

                $fromClause = new Query\Expr\From($from, $alias);
            }

            $entities[] = $fromClause->getFrom();
        }

        return $entities;
    }

    /**
     * Sets a query parameter for the query being constructed.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = :user_id')
     *         ->setParameter('user_id', 1);
     * </code>
     *
     * @param string|int                                   $key  The parameter position or name.
     * @param ParameterType|ArrayParameterType|string|null $type ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     *
     * @return $this
     */
    public function setParameter(string|int $key, mixed $value, ParameterType|ArrayParameterType|string|null $type = null): static
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
     * Sets a collection of query parameters for the query being constructed.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = :user_id1 OR u.id = :user_id2')
     *         ->setParameters(new ArrayCollection(array(
     *             new Parameter('user_id1', 1),
     *             new Parameter('user_id2', 2)
     *        )));
     * </code>
     *
     * @phpstan-param ArrayCollection<int, Parameter> $parameters
     *
     * @return $this
     */
    public function setParameters(ArrayCollection $parameters): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Gets all defined query parameters for the query being constructed.
     *
     * @phpstan-return ArrayCollection<int, Parameter>
     */
    public function getParameters(): ArrayCollection
    {
        return $this->parameters;
    }

    /**
     * Gets a (previously set) query parameter of the query being constructed.
     */
    public function getParameter(string|int $key): Parameter|null
    {
        $key = Parameter::normalizeName($key);

        $filteredParameters = $this->parameters->filter(
            static fn (Parameter $parameter): bool => $key === $parameter->getName(),
        );

        return ! $filteredParameters->isEmpty() ? $filteredParameters->first() : null;
    }

    /**
     * Sets the position of the first result to retrieve (the "offset").
     *
     * @return $this
     */
    public function setFirstResult(int|null $firstResult): static
    {
        $this->firstResult = (int) $firstResult;

        return $this;
    }

    /**
     * Gets the position of the first result the query object was set to retrieve (the "offset").
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
    public function setMaxResults(int|null $maxResults): static
    {
        if ($this->type === QueryType::Delete || $this->type === QueryType::Update) {
            throw new RuntimeException('Setting a limit is not supported for delete or update queries.');
        }

        $this->maxResults = $maxResults;

        return $this;
    }

    /**
     * Gets the maximum number of results the query object was set to retrieve (the "limit").
     * Returns NULL if {@link setMaxResults} was not applied to this query builder.
     */
    public function getMaxResults(): int|null
    {
        return $this->maxResults;
    }

    /**
     * Either appends to or replaces a single, generic query part.
     *
     * The available parts are: 'select', 'from', 'join', 'set', 'where',
     * 'groupBy', 'having' and 'orderBy'.
     *
     * @phpstan-param string|object|list<string>|array{join: array<int|string, object>} $dqlPart
     *
     * @return $this
     */
    public function add(string $dqlPartName, string|object|array $dqlPart, bool $append = false): static
    {
        if ($append && ($dqlPartName === 'where' || $dqlPartName === 'having')) {
            throw new InvalidArgumentException(
                "Using \$append = true does not have an effect with 'where' or 'having' " .
                'parts. See QueryBuilder#andWhere() for an example for correct usage.',
            );
        }

        $isMultiple = is_array($this->dqlParts[$dqlPartName])
            && ! ($dqlPartName === 'join' && ! $append);

        // Allow adding any part retrieved from self::getDQLParts().
        if (is_array($dqlPart) && $dqlPartName !== 'join') {
            $dqlPart = reset($dqlPart);
        }

        if ($dqlPartName === 'join') {
            $newDqlPart = [];

            foreach ($dqlPart as $k => $v) {
                if (is_numeric($k)) {
                    Deprecation::trigger(
                        'doctrine/orm',
                        'https://github.com/doctrine/orm/pull/12051',
                        'Using numeric keys in %s for join parts is deprecated and will not be supported in 4.0. Use an associative array with the root alias as key instead.',
                        __METHOD__,
                    );
                    $aliases = $this->getRootAliases();

                    if (! isset($aliases[0])) {
                        throw new RuntimeException('No alias was set before invoking add().');
                    }

                    $k = $aliases[0];
                }

                $newDqlPart[$k] = $v;
            }

            $dqlPart = $newDqlPart;
        }

        if ($append && $isMultiple) {
            if (is_array($dqlPart)) {
                $key = key($dqlPart);

                $this->dqlParts[$dqlPartName][$key][] = $dqlPart[$key];
            } else {
                $this->dqlParts[$dqlPartName][] = $dqlPart;
            }
        } else {
            $this->dqlParts[$dqlPartName] = $isMultiple ? [$dqlPart] : $dqlPart;
        }

        $this->dql = null;

        return $this;
    }

    /**
     * Specifies an item that is to be returned in the query result.
     * Replaces any previously specified selections, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u', 'p')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p');
     * </code>
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function select(mixed ...$select): static
    {
        self::validateVariadicParameter($select);

        $this->type = QueryType::Select;

        if ($select === []) {
            return $this;
        }

        return $this->add('select', new Expr\Select($select), false);
    }

    /**
     * Adds a DISTINCT flag to this query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->distinct()
     *         ->from('User', 'u');
     * </code>
     *
     * @return $this
     */
    public function distinct(bool $flag = true): static
    {
        if ($this->dqlParts['distinct'] !== $flag) {
            $this->dqlParts['distinct'] = $flag;
            $this->dql                  = null;
        }

        return $this;
    }

    /**
     * Adds an item that is to be returned in the query result.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->addSelect('p')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p');
     * </code>
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function addSelect(mixed ...$select): static
    {
        self::validateVariadicParameter($select);

        $this->type = QueryType::Select;

        if ($select === []) {
            return $this;
        }

        return $this->add('select', new Expr\Select($select), true);
    }

    /**
     * Turns the query being built into a bulk delete query that ranges over
     * a certain entity type.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->delete('User', 'u')
     *         ->where('u.id = :user_id')
     *         ->setParameter('user_id', 1);
     * </code>
     *
     * @param class-string|null $delete The class/type whose instances are subject to the deletion.
     * @param string|null       $alias  The class/type alias used in the constructed query.
     *
     * @return $this
     */
    public function delete(string|null $delete = null, string|null $alias = null): static
    {
        $this->type = QueryType::Delete;

        if (! $delete) {
            return $this;
        }

        if (! $alias) {
            throw new InvalidArgumentException(sprintf(
                '%s(): The alias for entity %s must not be omitted.',
                __METHOD__,
                $delete,
            ));
        }

        return $this->add('from', new Expr\From($delete, $alias));
    }

    /**
     * Turns the query being built into a bulk update query that ranges over
     * a certain entity type.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->update('User', 'u')
     *         ->set('u.password', '?1')
     *         ->where('u.id = ?2');
     * </code>
     *
     * @param class-string|null $update The class/type whose instances are subject to the update.
     * @param string|null       $alias  The class/type alias used in the constructed query.
     *
     * @return $this
     */
    public function update(string|null $update = null, string|null $alias = null): static
    {
        $this->type = QueryType::Update;

        if (! $update) {
            return $this;
        }

        if (! $alias) {
            throw new InvalidArgumentException(sprintf(
                '%s(): The alias for entity %s must not be omitted.',
                __METHOD__,
                $update,
            ));
        }

        return $this->add('from', new Expr\From($update, $alias));
    }

    /**
     * Creates and adds a query root corresponding to the entity identified by the given alias,
     * forming a cartesian product with any existing query roots.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u');
     * </code>
     *
     * @param class-string $from    The class name.
     * @param string       $alias   The alias of the class.
     * @param string|null  $indexBy The index for the from.
     *
     * @return $this
     */
    public function from(string $from, string $alias, string|null $indexBy = null): static
    {
        return $this->add('from', new Expr\From($from, $alias, $indexBy), true);
    }

    /**
     * Updates a query root corresponding to an entity setting its index by. This method is intended to be used with
     * EntityRepository->createQueryBuilder(), which creates the initial FROM clause and do not allow you to update it
     * setting an index by.
     *
     * <code>
     *     $qb = $userRepository->createQueryBuilder('u')
     *         ->indexBy('u', 'u.id');
     *
     *     // Is equivalent to...
     *
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u', 'u.id');
     * </code>
     *
     * @return $this
     *
     * @throws Query\QueryException
     */
    public function indexBy(string $alias, string $indexBy): static
    {
        $rootAliases = $this->getRootAliases();

        if (! in_array($alias, $rootAliases, true)) {
            throw new Query\QueryException(
                sprintf('Specified root alias %s must be set before invoking indexBy().', $alias),
            );
        }

        foreach ($this->dqlParts['from'] as &$fromClause) {
            assert($fromClause instanceof Expr\From);
            if ($fromClause->getAlias() !== $alias) {
                continue;
            }

            $fromClause = new Expr\From($fromClause->getFrom(), $fromClause->getAlias(), $indexBy);
        }

        return $this;
    }

    /**
     * Creates and adds a join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->join('u.Phonenumbers', 'p', Expr\Join::WITH, 'p.is_primary = 1');
     * </code>
     *
     * @phpstan-param Expr\Join::ON|Expr\Join::WITH|null $conditionType
     *
     * @return $this
     */
    public function join(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null,
    ): static {
        return $this->innerJoin($join, $alias, $conditionType, $condition, $indexBy);
    }

    /**
     * Creates and adds a join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     *     [php]
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->innerJoin('u.Phonenumbers', 'p', Expr\Join::WITH, 'p.is_primary = 1');
     *
     * @phpstan-param Expr\Join::ON|Expr\Join::WITH|null $conditionType
     *
     * @return $this
     */
    public function innerJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null,
    ): static {
        $parentAlias = substr($join, 0, (int) strpos($join, '.'));

        $rootAlias = $this->findRootAlias($alias, $parentAlias);

        $join = new Expr\Join(
            Expr\Join::INNER_JOIN,
            $join,
            $alias,
            $conditionType,
            $condition,
            $indexBy,
        );

        return $this->add('join', [$rootAlias => $join], true);
    }

    /**
     * Creates and adds a left join over an entity association to the query.
     *
     * The entities in the joined association will be fetched as part of the query
     * result if the alias used for the joined association is placed in the select
     * expressions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->leftJoin('u.Phonenumbers', 'p', Expr\Join::WITH, 'p.is_primary = 1');
     * </code>
     *
     * @phpstan-param Expr\Join::ON|Expr\Join::WITH|null $conditionType
     *
     * @return $this
     */
    public function leftJoin(
        string $join,
        string $alias,
        string|null $conditionType = null,
        string|Expr\Composite|Expr\Comparison|Expr\Func|null $condition = null,
        string|null $indexBy = null,
    ): static {
        $parentAlias = substr($join, 0, (int) strpos($join, '.'));

        $rootAlias = $this->findRootAlias($alias, $parentAlias);

        $join = new Expr\Join(
            Expr\Join::LEFT_JOIN,
            $join,
            $alias,
            $conditionType,
            $condition,
            $indexBy,
        );

        return $this->add('join', [$rootAlias => $join], true);
    }

    /**
     * Sets a new value for a field in a bulk update query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->update('User', 'u')
     *         ->set('u.password', '?1')
     *         ->where('u.id = ?2');
     * </code>
     *
     * @return $this
     */
    public function set(string $key, mixed $value): static
    {
        return $this->add('set', new Expr\Comparison($key, Expr\Comparison::EQ, $value), true);
    }

    /**
     * Specifies one or more restrictions to the query result.
     * Replaces any previously specified restrictions, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = ?');
     *
     *     // You can optionally programmatically build and/or expressions
     *     $qb = $em->createQueryBuilder();
     *
     *     $or = $qb->expr()->orX();
     *     $or->add($qb->expr()->eq('u.id', 1));
     *     $or->add($qb->expr()->eq('u.id', 2));
     *
     *     $qb->update('User', 'u')
     *         ->set('u.password', '?')
     *         ->where($or);
     * </code>
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function where(mixed ...$predicates): static
    {
        self::validateVariadicParameter($predicates);

        if (! (count($predicates) === 1 && $predicates[0] instanceof Expr\Composite)) {
            $predicates = new Expr\Andx($predicates);
        }

        return $this->add('where', $predicates);
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * conjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.username LIKE ?')
     *         ->andWhere('u.is_active = 1');
     * </code>
     *
     * @see where()
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function andWhere(mixed ...$where): static
    {
        self::validateVariadicParameter($where);

        $dql = $this->getDQLPart('where');

        if ($dql instanceof Expr\Andx) {
            $dql->addMultiple($where);
        } else {
            array_unshift($where, $dql);
            $dql = new Expr\Andx($where);
        }

        return $this->add('where', $dql);
    }

    /**
     * Adds one or more restrictions to the query results, forming a logical
     * disjunction with any previously specified restrictions.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->where('u.id = 1')
     *         ->orWhere('u.id = 2');
     * </code>
     *
     * @see where()
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function orWhere(mixed ...$where): static
    {
        self::validateVariadicParameter($where);

        $dql = $this->getDQLPart('where');

        if ($dql instanceof Expr\Orx) {
            $dql->addMultiple($where);
        } else {
            array_unshift($where, $dql);
            $dql = new Expr\Orx($where);
        }

        return $this->add('where', $dql);
    }

    /**
     * Specifies a grouping over the results of the query.
     * Replaces any previously specified groupings, if any.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->groupBy('u.id');
     * </code>
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function groupBy(string ...$groupBy): static
    {
        self::validateVariadicParameter($groupBy);

        return $this->add('groupBy', new Expr\GroupBy($groupBy));
    }

    /**
     * Adds a grouping expression to the query.
     *
     * <code>
     *     $qb = $em->createQueryBuilder()
     *         ->select('u')
     *         ->from('User', 'u')
     *         ->groupBy('u.lastLogin')
     *         ->addGroupBy('u.createdAt');
     * </code>
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function addGroupBy(string ...$groupBy): static
    {
        self::validateVariadicParameter($groupBy);

        return $this->add('groupBy', new Expr\GroupBy($groupBy), true);
    }

    /**
     * Specifies a restriction over the groups of the query.
     * Replaces any previous having restrictions, if any.
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function having(mixed ...$having): static
    {
        self::validateVariadicParameter($having);

        if (! (count($having) === 1 && ($having[0] instanceof Expr\Andx || $having[0] instanceof Expr\Orx))) {
            $having = new Expr\Andx($having);
        }

        return $this->add('having', $having);
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * conjunction with any existing having restrictions.
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function andHaving(mixed ...$having): static
    {
        self::validateVariadicParameter($having);

        $dql = $this->getDQLPart('having');

        if ($dql instanceof Expr\Andx) {
            $dql->addMultiple($having);
        } else {
            array_unshift($having, $dql);
            $dql = new Expr\Andx($having);
        }

        return $this->add('having', $dql);
    }

    /**
     * Adds a restriction over the groups of the query, forming a logical
     * disjunction with any existing having restrictions.
     *
     * @return $this
     *
     * @no-named-arguments
     */
    public function orHaving(mixed ...$having): static
    {
        self::validateVariadicParameter($having);

        $dql = $this->getDQLPart('having');

        if ($dql instanceof Expr\Orx) {
            $dql->addMultiple($having);
        } else {
            array_unshift($having, $dql);
            $dql = new Expr\Orx($having);
        }

        return $this->add('having', $dql);
    }

    /**
     * Specifies an ordering for the query results.
     * Replaces any previously specified orderings, if any.
     *
     * @return $this
     */
    public function orderBy(string|Expr\OrderBy $sort, SortDirection $order = SortDirection::Ascending): static
    {
        $orderBy = $sort instanceof Expr\OrderBy ? $sort : new Expr\OrderBy($sort, $order);

        return $this->add('orderBy', $orderBy);
    }

    /**
     * Adds an ordering to the query results.
     *
     * @return $this
     */
    public function addOrderBy(string|Expr\OrderBy $sort, SortDirection $order = SortDirection::Ascending): static
    {
        $orderBy = $sort instanceof Expr\OrderBy ? $sort : new Expr\OrderBy(
            $sort,
            $order,
        );

        return $this->add('orderBy', $orderBy, true);
    }

    /**
     * Adds criteria to the query.
     *
     * Adds where expressions with AND operator.
     * Adds orderings.
     * Overrides firstResult and maxResults if they're set.
     *
     * @return $this
     *
     * @throws Query\QueryException
     */
    public function addCriteria(Criteria $criteria): static
    {
        $allAliases = $this->getAllAliases();
        if (! isset($allAliases[0])) {
            throw new Query\QueryException('No aliases are set before invoking addCriteria().');
        }

        $visitor = new QueryExpressionVisitor($this->getAllAliases());

        $whereExpression = $criteria->getWhereExpression();
        if ($whereExpression) {
            $this->andWhere($whereExpression->visit($visitor));
            foreach ($visitor->getParameters() as $parameter) {
                $this->parameters->add($parameter);
            }
        }

        $orderings = $criteria->getOrderings();

        foreach ($orderings as $sort => $order) {
            $hasValidAlias = false;
            foreach ($allAliases as $alias) {
                if (str_starts_with($sort . '.', $alias . '.')) {
                    $hasValidAlias = true;
                    break;
                }
            }

            if (! $hasValidAlias) {
                $sort = $allAliases[0] . '.' . $sort;
            }

            $this->addOrderBy($sort, $order);
        }

        // Overwrite limits only if they was set in criteria
        $firstResult = $criteria->getFirstResult();
        if ($firstResult > 0) {
            $this->setFirstResult($firstResult);
        }

        $maxResults = $criteria->getMaxResults();
        if ($maxResults !== null) {
            $this->setMaxResults($maxResults);
        }

        return $this;
    }

    /**
     * Gets a query part by its name.
     */
    public function getDQLPart(string $queryPartName): mixed
    {
        return $this->dqlParts[$queryPartName];
    }

    /**
     * Gets all query parts.
     *
     * @phpstan-return array<string, mixed> $dqlParts
     */
    public function getDQLParts(): array
    {
        return $this->dqlParts;
    }

    private function getDQLForDelete(): string
    {
         return 'DELETE'
              . $this->getReducedDQLQueryPart('from', ['pre' => ' ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('where', ['pre' => ' WHERE '])
              . $this->getReducedDQLQueryPart('orderBy', ['pre' => ' ORDER BY ', 'separator' => ', ']);
    }

    private function getDQLForUpdate(): string
    {
         return 'UPDATE'
              . $this->getReducedDQLQueryPart('from', ['pre' => ' ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('set', ['pre' => ' SET ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('where', ['pre' => ' WHERE '])
              . $this->getReducedDQLQueryPart('orderBy', ['pre' => ' ORDER BY ', 'separator' => ', ']);
    }

    private function getDQLForSelect(): string
    {
        $dql = 'SELECT'
             . ($this->dqlParts['distinct'] === true ? ' DISTINCT' : '')
             . $this->getReducedDQLQueryPart('select', ['pre' => ' ', 'separator' => ', ']);

        $fromParts   = $this->getDQLPart('from');
        $joinParts   = $this->getDQLPart('join');
        $fromClauses = [];

        // Loop through all FROM clauses
        if (! empty($fromParts)) {
            $dql .= ' FROM ';

            foreach ($fromParts as $from) {
                $fromClause = (string) $from;

                if ($from instanceof Expr\From && isset($joinParts[$from->getAlias()])) {
                    foreach ($joinParts[$from->getAlias()] as $join) {
                        $fromClause .= ' ' . ((string) $join);
                    }
                }

                $fromClauses[] = $fromClause;
            }
        }

        $dql .= implode(', ', $fromClauses)
              . $this->getReducedDQLQueryPart('where', ['pre' => ' WHERE '])
              . $this->getReducedDQLQueryPart('groupBy', ['pre' => ' GROUP BY ', 'separator' => ', '])
              . $this->getReducedDQLQueryPart('having', ['pre' => ' HAVING '])
              . $this->getReducedDQLQueryPart('orderBy', ['pre' => ' ORDER BY ', 'separator' => ', ']);

        return $dql;
    }

    /** @phpstan-param array<string, mixed> $options */
    private function getReducedDQLQueryPart(string $queryPartName, array $options = []): string
    {
        $queryPart = $this->getDQLPart($queryPartName);

        if (empty($queryPart)) {
            return $options['empty'] ?? '';
        }

        return ($options['pre'] ?? '')
             . (is_array($queryPart) ? implode($options['separator'], $queryPart) : $queryPart)
             . ($options['post'] ?? '');
    }

    /**
     * Resets DQL parts.
     *
     * @param string[]|null $parts
     * @phpstan-param list<string>|null $parts
     *
     * @return $this
     */
    public function resetDQLParts(array|null $parts = null): static
    {
        if ($parts === null) {
            $parts = array_keys($this->dqlParts);
        }

        foreach ($parts as $part) {
            $this->resetDQLPart($part);
        }

        return $this;
    }

    /**
     * Resets single DQL part.
     *
     * @return $this
     */
    public function resetDQLPart(string $part): static
    {
        $this->dqlParts[$part] = is_array($this->dqlParts[$part]) ? [] : null;
        $this->dql             = null;

        return $this;
    }

    /**
     * Creates a new named parameter and bind the value $value to it.
     *
     * The parameter $value specifies the value that you want to bind. If
     * $placeholder is not provided createNamedParameter() will automatically
     * create a placeholder for you. An automatic placeholder will be of the
     * name ':dcValue1', ':dcValue2' etc.
     *
     * Example:
     *  <code>
     *   $qb = $em->createQueryBuilder();
     *   $qb
     *      ->select('u')
     *      ->from('User', 'u')
     *      ->where('u.username = ' . $qb->createNamedParameter('Foo', Types::STRING))
     *      ->orWhere('u.username = ' . $qb->createNamedParameter('Bar', Types::STRING))
     *  </code>
     *
     * @param ParameterType|ArrayParameterType|string|null $type        ParameterType::*, ArrayParameterType::* or \Doctrine\DBAL\Types\Type::* constant
     * @param non-empty-string|null                        $placeholder The name to bind with. The string must start with a colon ':'.
     *
     * @return non-empty-string the placeholder name used.
     */
    public function createNamedParameter(mixed $value, ParameterType|ArrayParameterType|string|int|null $type = null, string|null $placeholder = null): string
    {
        if ($placeholder === null) {
            $this->boundCounter++;
            $placeholder = ':dcValue' . $this->boundCounter;
        }

        $this->setParameter(substr($placeholder, 1), $value, $type);

        return $placeholder;
    }

    /**
     * Gets a string representation of this QueryBuilder which corresponds to
     * the final DQL query being constructed.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->getDQL();
    }

    /**
     * Deep clones all expression objects in the DQL parts.
     *
     * @return void
     */
    public function __clone()
    {
        foreach ($this->dqlParts as $part => $elements) {
            if (is_array($this->dqlParts[$part])) {
                foreach ($this->dqlParts[$part] as $idx => $element) {
                    if (is_object($element)) {
                        $this->dqlParts[$part][$idx] = clone $element;
                    }
                }
            } elseif (is_object($elements)) {
                $this->dqlParts[$part] = clone $elements;
            }
        }

        $parameters = [];

        foreach ($this->parameters as $parameter) {
            $parameters[] = clone $parameter;
        }

        $this->parameters = new ArrayCollection($parameters);
    }
}
