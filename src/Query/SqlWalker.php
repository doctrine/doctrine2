<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use BadMethodCallException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\TypeProvider;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\TypeProviderLocator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Utility\HierarchyDiscriminatorResolver;
use Doctrine\ORM\Utility\PersisterHelper;
use InvalidArgumentException;
use LogicException;
use SortDirection;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function assert;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function reset;
use function sprintf;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * The SqlWalker walks over a DQL AST and constructs the corresponding SQL.
 *
 * @phpstan-import-type QueryComponent from Parser
 * @phpstan-consistent-constructor
 */
class SqlWalker
{
    public const HINT_DISTINCT = 'doctrine.distinct';

    /**
     * Used to mark a query as containing a PARTIAL expression, which needs to be known by SLC.
     */
    public const HINT_PARTIAL = 'doctrine.partial';

    private readonly ResultSetMapping $rsm;

    /** Whether the selected entity fields use their raw columns, without PHP value conversion. */
    private bool $useRawColumns = false;

    /**
     * Counter for generating unique column aliases.
     */
    private int $aliasCounter = 0;

    /**
     * Counter for generating unique table aliases.
     */
    private int $tableAliasCounter = 0;

    /**
     * Counter for generating unique scalar result.
     */
    private int $scalarResultCounter = 1;

    /**
     * Counter for generating unique parameter indexes.
     */
    private int $sqlParamIndex = 0;

    /**
     * Counter for generating indexes.
     */
    private int $newObjectCounter = 0;

    private readonly EntityManagerInterface $em;
    private readonly Connection $conn;

    /** @var mixed[] */
    private array $tableAliasMap = [];

    /**
     * Map from result variable names to their SQL column alias names.
     *
     * @phpstan-var array<string|int, string|list<string>>
     */
    private array $scalarResultAliasMap = [];

    /**
     * Map from Table-Alias + Column-Name to OrderBy-Direction.
     *
     * @var array<string, SortDirection|string>
     */
    private array $orderedColumnsMap = [];

    /**
     * Map from DQL-Alias + Field-Name to SQL Column Alias.
     *
     * @var array<string, array<string, string>>
     */
    private array $scalarFields = [];

    /**
     * A list of classes that appear in non-scalar SelectExpressions.
     *
     * @phpstan-var array<string, array{class: ClassMetadata, dqlAlias: string, resultAlias: string|null, partial: bool}>
     */
    private array $selectedClasses = [];

    /**
     * The DQL alias of the root class of the currently traversed query.
     *
     * @phpstan-var list<string>
     */
    private array $rootAliases = [];

    /**
     * Flag that indicates whether to generate SQL table aliases in the SQL.
     * These should only be generated for SELECT queries, not for UPDATE/DELETE.
     */
    private bool $useSqlTableAliases = true;

    /**
     * The database platform abstraction.
     */
    private readonly AbstractPlatform $platform;

    /**
     * The quote strategy.
     */
    private readonly QuoteStrategy $quoteStrategy;

    private readonly TypeProvider|TypeRegistry $typeProvider;

    /** @phpstan-param array<string, QueryComponent> $queryComponents The query components (symbol table). */
    public function __construct(
        private readonly Query $query,
        private readonly ParserResult $parserResult,
        private array $queryComponents,
    ) {
        $this->rsm           = $parserResult->getResultSetMapping();
        $this->em            = $query->getEntityManager();
        $this->conn          = $this->em->getConnection();
        $this->platform      = $this->conn->getDatabasePlatform();
        $this->quoteStrategy = $this->em->getConfiguration()->getQuoteStrategy();
        $this->typeProvider  = TypeProviderLocator::fromConnection($this->conn);
    }

    /**
     * Gets the Query instance used by the walker.
     */
    public function getQuery(): Query
    {
        return $this->query;
    }

    /**
     * Gets the Connection used by the walker.
     */
    public function getConnection(): Connection
    {
        return $this->conn;
    }

    /**
     * Gets the EntityManager used by the walker.
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }

    /**
     * Gets the information about a single query component.
     *
     * @param string $dqlAlias The DQL alias.
     *
     * @return mixed[]
     * @phpstan-return QueryComponent
     */
    public function getQueryComponent(string $dqlAlias): array
    {
        return $this->queryComponents[$dqlAlias];
    }

    public function getMetadataForDqlAlias(string $dqlAlias): ClassMetadata
    {
        return $this->queryComponents[$dqlAlias]['metadata']
            ?? throw new LogicException(sprintf('No metadata for DQL alias: %s', $dqlAlias));
    }

    /**
     * Returns internal queryComponents array.
     *
     * @return array<string, QueryComponent>
     */
    public function getQueryComponents(): array
    {
        return $this->queryComponents;
    }

    /**
     * Sets or overrides a query component for a given dql alias.
     *
     * @phpstan-param QueryComponent $queryComponent
     */
    public function setQueryComponent(string $dqlAlias, array $queryComponent): void
    {
        $requiredKeys = ['metadata', 'parent', 'relation', 'map', 'nestingLevel', 'token'];

        if (array_diff($requiredKeys, array_keys($queryComponent))) {
            throw QueryException::invalidQueryComponent($dqlAlias);
        }

        $this->queryComponents[$dqlAlias] = $queryComponent;
    }

    /**
     * Gets an executor that can be used to execute the result of this walker.
     *
     * @deprecated Output walkers should no longer create the executor directly, but instead provide
     *             a SqlFinalizer by implementing the `OutputWalker` interface. Thus, this method is
     *             no longer needed and will be removed in 4.0.
     */
    public function getExecutor(AST\SelectStatement|AST\UpdateStatement|AST\DeleteStatement $statement): Exec\AbstractSqlExecutor
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/pull/11188/',
            'Output walkers should implement %s. That way, the %s method is no longer needed and will be removed in 4.0',
            OutputWalker::class,
            __METHOD__,
        );

        return match (true) {
            $statement instanceof AST\UpdateStatement => $this->createUpdateStatementExecutor($statement),
            $statement instanceof AST\DeleteStatement => $this->createDeleteStatementExecutor($statement),
            default => new Exec\SingleSelectExecutor($statement, $this),
        };
    }

    protected function createUpdateStatementExecutor(AST\UpdateStatement $AST): Exec\AbstractSqlExecutor
    {
        $primaryClass = $this->em->getClassMetadata($AST->updateClause->abstractSchemaName);

        return $primaryClass->isInheritanceTypeJoined()
            ? new Exec\MultiTableUpdateExecutor($AST, $this)
            : new Exec\SingleTableDeleteUpdateExecutor($AST, $this);
    }

    protected function createDeleteStatementExecutor(AST\DeleteStatement $AST): Exec\AbstractSqlExecutor
    {
        $primaryClass = $this->em->getClassMetadata($AST->deleteClause->abstractSchemaName);

        return $primaryClass->isInheritanceTypeJoined()
            ? new Exec\MultiTableDeleteExecutor($AST, $this)
            : new Exec\SingleTableDeleteUpdateExecutor($AST, $this);
    }

    /**
     * Generates a unique, short SQL table alias.
     */
    public function getSQLTableAlias(string $tableName, string $dqlAlias = ''): string
    {
        $tableName .= $dqlAlias ? '@[' . $dqlAlias . ']' : '';

        if (! isset($this->tableAliasMap[$tableName])) {
            $this->tableAliasMap[$tableName] = (preg_match('/[a-z]/i', $tableName[0]) ? strtolower($tableName[0]) : 't')
                . $this->tableAliasCounter++ . '_';
        }

        return $this->tableAliasMap[$tableName];
    }

    /**
     * Forces the SqlWalker to use a specific alias for a table name, rather than
     * generating an alias on its own.
     */
    public function setSQLTableAlias(string $tableName, string $alias, string $dqlAlias = ''): string
    {
        $tableName .= $dqlAlias ? '@[' . $dqlAlias . ']' : '';

        $this->tableAliasMap[$tableName] = $alias;

        return $alias;
    }

    /**
     * Gets an SQL column alias for a column name.
     */
    public function getSQLColumnAlias(string $columnName): string
    {
        return $this->quoteStrategy->getColumnAlias($columnName, $this->aliasCounter++, $this->platform);
    }

    /**
     * Generates the SQL JOINs that are necessary for Class Table Inheritance
     * for the given class.
     */
    private function generateClassTableInheritanceJoins(
        ClassMetadata $class,
        string $dqlAlias,
    ): string {
        $sql = '';

        $baseTableAlias = $this->getSQLTableAlias($class->getTableName(), $dqlAlias);

        // INNER JOIN parent class tables
        foreach ($class->parentClasses as $parentClassName) {
            $parentClass = $this->em->getClassMetadata($parentClassName);
            $tableAlias  = $this->getSQLTableAlias($parentClass->getTableName(), $dqlAlias);

            // If this is a joined association we must use left joins to preserve the correct result.
            $sql .= isset($this->queryComponents[$dqlAlias]['relation']) ? ' LEFT ' : ' INNER ';
            $sql .= 'JOIN ' . $this->quoteStrategy->getTableName($parentClass, $this->platform) . ' ' . $tableAlias . ' ON ';

            $sqlParts = [];

            foreach ($this->quoteStrategy->getIdentifierColumnNames($class, $this->platform) as $columnName) {
                $sqlParts[] = $baseTableAlias . '.' . $columnName . ' = ' . $tableAlias . '.' . $columnName;
            }

            // Add filters on the root class
            $sqlParts[] = $this->generateFilterConditionSQL($parentClass, $tableAlias);

            $sql .= implode(' AND ', array_filter($sqlParts));
        }

        // Ignore subclassing inclusion if partial objects is disallowed
        if ($this->query->getHint(Query::HINT_FORCE_PARTIAL_LOAD)) {
            return $sql;
        }

        // LEFT JOIN child class tables
        foreach ($class->subClasses as $subClassName) {
            $subClass   = $this->em->getClassMetadata($subClassName);
            $tableAlias = $this->getSQLTableAlias($subClass->getTableName(), $dqlAlias);

            $sql .= ' LEFT JOIN ' . $this->quoteStrategy->getTableName($subClass, $this->platform) . ' ' . $tableAlias . ' ON ';

            $sqlParts = [];

            foreach ($this->quoteStrategy->getIdentifierColumnNames($subClass, $this->platform) as $columnName) {
                $sqlParts[] = $baseTableAlias . '.' . $columnName . ' = ' . $tableAlias . '.' . $columnName;
            }

            $sql .= implode(' AND ', $sqlParts);
        }

        return $sql;
    }

    private function generateOrderedCollectionOrderByItems(): string
    {
        $orderedColumns = [];

        foreach ($this->selectedClasses as $selectedClass) {
            $dqlAlias = $selectedClass['dqlAlias'];
            $qComp    = $this->queryComponents[$dqlAlias];

            if (! isset($qComp['relation']->orderBy)) {
                continue;
            }

            assert(isset($qComp['metadata']));
            $persister = $this->em->getUnitOfWork()->getEntityPersister($qComp['metadata']->name);

            foreach ($qComp['relation']->orderBy as $fieldName => $orientation) {
                $columnName = $this->quoteStrategy->getColumnName($fieldName, $qComp['metadata'], $this->platform);
                $tableName  = $qComp['metadata']->isInheritanceTypeJoined()
                    ? $persister->getOwningTable($fieldName)
                    : $qComp['metadata']->getTableName();

                $orderedColumn = $this->getSQLTableAlias($tableName, $dqlAlias) . '.' . $columnName;

                // OrderByClause should replace an ordered relation. see - DDC-2475
                if (isset($this->orderedColumnsMap[$orderedColumn])) {
                    continue;
                }

                $this->orderedColumnsMap[$orderedColumn] = $orientation;
                if ($orientation instanceof SortDirection) {
                    $orientation = ($orientation === SortDirection::Ascending ? 'ASC' : 'DESC');
                }

                $orderedColumns[] = $orderedColumn . ' ' . $orientation;
            }
        }

        return implode(', ', $orderedColumns);
    }

    /**
     * Generates a discriminator column SQL condition for the class with the given DQL alias.
     *
     * @phpstan-param list<string> $dqlAliases List of root DQL aliases to inspect for discriminator restrictions.
     */
    private function generateDiscriminatorColumnConditionSQL(array $dqlAliases): string
    {
        $sqlParts = [];

        foreach ($dqlAliases as $dqlAlias) {
            $class = $this->getMetadataForDqlAlias($dqlAlias);

            if (! $class->isInheritanceTypeSingleTable()) {
                continue;
            }

            $sqlTableAlias = $this->useSqlTableAliases
                ? $this->getSQLTableAlias($class->getTableName(), $dqlAlias) . '.'
                : '';

            $conn   = $this->em->getConnection();
            $values = [];

            if ($class->discriminatorValue !== null) { // discriminators can be 0
                $values[] = $class->getDiscriminatorColumn()->type === 'integer' && is_int($class->discriminatorValue)
                    ? $class->discriminatorValue
                    : $conn->quote((string) $class->discriminatorValue);
            }

            foreach ($class->subClasses as $subclassName) {
                $subclassMetadata = $this->em->getClassMetadata($subclassName);

                // Abstract entity classes show up in the list of subClasses, but may be omitted
                // from the discriminator map. In that case, they have a null discriminator value.
                if ($subclassMetadata->discriminatorValue === null) {
                    continue;
                }

                $values[] = $subclassMetadata->getDiscriminatorColumn()->type === 'integer' && is_int($subclassMetadata->discriminatorValue)
                    ? $subclassMetadata->discriminatorValue
                    : $conn->quote((string) $subclassMetadata->discriminatorValue);
            }

            if ($values !== []) {
                $sqlParts[] = $sqlTableAlias . $class->getDiscriminatorColumn()->name . ' IN (' . implode(', ', $values) . ')';
            } else {
                $sqlParts[] = '1=0'; // impossible condition
            }
        }

        $sql = implode(' AND ', $sqlParts);

        return count($sqlParts) > 1 ? '(' . $sql . ')' : $sql;
    }

    /**
     * Generates the filter SQL for a given entity and table alias.
     */
    private function generateFilterConditionSQL(
        ClassMetadata $targetEntity,
        string $targetTableAlias,
    ): string {
        if (! $this->em->hasFilters()) {
            return '';
        }

        switch ($targetEntity->inheritanceType) {
            case ClassMetadata::INHERITANCE_TYPE_NONE:
                break;
            case ClassMetadata::INHERITANCE_TYPE_JOINED:
                // The classes in the inheritance will be added to the query one by one,
                // but only the root node is getting filtered
                if ($targetEntity->name !== $targetEntity->rootEntityName) {
                    return '';
                }

                break;
            case ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE:
                // With STI the table will only be queried once, make sure that the filters
                // are added to the root entity
                $targetEntity = $this->em->getClassMetadata($targetEntity->rootEntityName);
                break;
            default:
                //@todo: throw exception?
                return '';
        }

        $filterClauses = [];
        foreach ($this->em->getFilters()->getEnabledFilters() as $filter) {
            $filterExpr = $filter->addFilterConstraint($targetEntity, $targetTableAlias);
            if ($filterExpr !== '') {
                $filterClauses[] = '(' . $filterExpr . ')';
            }
        }

        return implode(' AND ', $filterClauses);
    }

    /**
     * Walks down a SelectStatement AST node, thereby generating the appropriate SQL.
     */
    public function walkSelectStatement(AST\SelectStatement $selectStatement): string
    {
        $sql       = $this->createSqlForFinalizer($selectStatement);
        $finalizer = new Exec\SingleSelectSqlFinalizer($sql);

        return $finalizer->finalizeSql($this->query);
    }

    protected function createSqlForFinalizer(AST\SelectStatement $selectStatement): string
    {
        $sql = $this->walkSelectClause($selectStatement->selectClause)
            . $this->walkFromClause($selectStatement->fromClause)
            . $this->walkWhereClause($selectStatement->whereClause);

        if ($selectStatement->groupByClause) {
            $sql .= $this->walkGroupByClause($selectStatement->groupByClause);
        }

        if ($selectStatement->havingClause) {
            $sql .= $this->walkHavingClause($selectStatement->havingClause);
        }

        if ($selectStatement->orderByClause) {
            $sql .= $this->walkOrderByClause($selectStatement->orderByClause);
        }

        $orderBySql = $this->generateOrderedCollectionOrderByItems();
        if (! $selectStatement->orderByClause && $orderBySql) {
            $sql .= ' ORDER BY ' . $orderBySql;
        }

        $this->assertOptimisticLockingHasAllClassesVersioned();

        return $sql;
    }

    private function assertOptimisticLockingHasAllClassesVersioned(): void
    {
        $lockMode = $this->query->getHint(Query::HINT_LOCK_MODE) ?: LockMode::NONE;

        if ($lockMode === LockMode::OPTIMISTIC) {
            foreach ($this->selectedClasses as $selectedClass) {
                if (! $selectedClass['class']->isVersioned) {
                    throw OptimisticLockException::lockFailed($selectedClass['class']->name);
                }
            }
        }
    }

    /**
     * Walks down a UpdateStatement AST node, thereby generating the appropriate SQL.
     */
    public function walkUpdateStatement(AST\UpdateStatement $updateStatement): string
    {
        $this->useSqlTableAliases = false;
        $this->rsm->isSelect      = false;

        return $this->walkUpdateClause($updateStatement->updateClause)
            . $this->walkWhereClause($updateStatement->whereClause);
    }

    /**
     * Walks down a DeleteStatement AST node, thereby generating the appropriate SQL.
     */
    public function walkDeleteStatement(AST\DeleteStatement $deleteStatement): string
    {
        $this->useSqlTableAliases = false;
        $this->rsm->isSelect      = false;

        return $this->walkDeleteClause($deleteStatement->deleteClause)
            . $this->walkWhereClause($deleteStatement->whereClause);
    }

    /**
     * Walks down an IdentificationVariable AST node, thereby generating the appropriate SQL.
     * This one differs of ->walkIdentificationVariable() because it generates the entity identifiers.
     */
    public function walkEntityIdentificationVariable(string $identVariable): string
    {
        $class      = $this->getMetadataForDqlAlias($identVariable);
        $tableAlias = $this->getSQLTableAlias($class->getTableName(), $identVariable);
        $sqlParts   = [];

        foreach ($this->quoteStrategy->getIdentifierColumnNames($class, $this->platform) as $columnName) {
            $sqlParts[] = $tableAlias . '.' . $columnName;
        }

        return implode(', ', $sqlParts);
    }

    /**
     * Walks down an EntityAsDtoArgumentExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkEntityAsDtoArgumentExpression(AST\EntityAsDtoArgumentExpression $expr): string
    {
        return implode(', ', $this->walkObjectExpression($expr->expression, [], $expr->identificationVariable ?: null));
    }

    /**
     * Walks down an IdentificationVariable (no AST node associated), thereby generating the SQL.
     */
    public function walkIdentificationVariable(string $identificationVariable, string|null $fieldName = null): string
    {
        $class = $this->getMetadataForDqlAlias($identificationVariable);

        if (
            $fieldName !== null && $class->isInheritanceTypeJoined() &&
            isset($class->fieldMappings[$fieldName]->inherited)
        ) {
            $class = $this->em->getClassMetadata($class->fieldMappings[$fieldName]->inherited);
        }

        return $this->getSQLTableAlias($class->getTableName(), $identificationVariable);
    }

    /**
     * Walks down a PathExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkPathExpression(AST\PathExpression $pathExpr): string
    {
        $sql = '';
        assert($pathExpr->field !== null);

        switch ($pathExpr->type) {
            case AST\PathExpression::TYPE_STATE_FIELD:
                $fieldName = $pathExpr->field;
                $dqlAlias  = $pathExpr->identificationVariable;
                $class     = $this->getMetadataForDqlAlias($dqlAlias);

                if ($this->useSqlTableAliases) {
                    $sql .= $this->walkIdentificationVariable($dqlAlias, $fieldName) . '.';
                }

                $sql .= $this->quoteStrategy->getColumnName($fieldName, $class, $this->platform);
                break;

            case AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION:
                // 1- the owning side:
                //    Just use the foreign key, i.e. u.group_id
                $fieldName = $pathExpr->field;
                $dqlAlias  = $pathExpr->identificationVariable;
                $class     = $this->getMetadataForDqlAlias($dqlAlias);

                if (isset($class->associationMappings[$fieldName]->inherited)) {
                    $class = $this->em->getClassMetadata($class->associationMappings[$fieldName]->inherited);
                }

                $assoc = $class->associationMappings[$fieldName];

                if (! $assoc->isOwningSide()) {
                    throw QueryException::associationPathInverseSideNotSupported($pathExpr);
                }

                assert($assoc->isToOneOwningSide());

                // COMPOSITE KEYS NOT (YET?) SUPPORTED
                if (count($assoc->sourceToTargetKeyColumns) > 1) {
                    throw QueryException::associationPathCompositeKeyNotSupported();
                }

                if ($this->useSqlTableAliases) {
                    $sql .= $this->getSQLTableAlias($class->getTableName(), $dqlAlias) . '.';
                }

                $sql .= reset($assoc->targetToSourceKeyColumns);
                break;

            default:
                throw QueryException::invalidPathExpression($pathExpr);
        }

        return $sql;
    }

    /**
     * Walks down a SelectClause AST node, thereby generating the appropriate SQL.
     */
    public function walkSelectClause(AST\SelectClause $selectClause): string
    {
        $sql                  = 'SELECT ' . ($selectClause->isDistinct ? 'DISTINCT ' : '');
        $sqlSelectExpressions = array_filter(array_map($this->walkSelectExpression(...), $selectClause->selectExpressions));

        if ($this->query->getHint(Query::HINT_INTERNAL_ITERATION) === true && $selectClause->isDistinct) {
            $this->query->setHint(self::HINT_DISTINCT, true);
        }

        $addMetaColumns = ! $this->query->getHint(Query::HINT_FORCE_PARTIAL_LOAD) &&
            $this->query->getHydrationMode() === Query::HYDRATE_OBJECT
            || $this->query->getHint(Query::HINT_INCLUDE_META_COLUMNS);

        foreach ($this->selectedClasses as $selectedClass) {
            $class       = $selectedClass['class'];
            $dqlAlias    = $selectedClass['dqlAlias'];
            $resultAlias = $selectedClass['resultAlias'];
            $isPartial   = $selectedClass['partial'];

            // Register as entity or joined entity result
            if (! isset($this->queryComponents[$dqlAlias]['relation'])) {
                $this->rsm->addEntityResult($class->name, $dqlAlias, $resultAlias);

                if ($isPartial) {
                    $this->rsm->markPartialEntityResult($dqlAlias);
                }
            } else {
                assert(isset($this->queryComponents[$dqlAlias]['parent']));

                $this->rsm->addJoinedEntityResult(
                    $class->name,
                    $dqlAlias,
                    $this->queryComponents[$dqlAlias]['parent'],
                    $this->queryComponents[$dqlAlias]['relation']->fieldName,
                    $isPartial,
                );
            }

            if ($class->isInheritanceTypeSingleTable() || $class->isInheritanceTypeJoined()) {
                // Add discriminator columns to SQL
                $rootClass   = $this->em->getClassMetadata($class->rootEntityName);
                $tblAlias    = $this->getSQLTableAlias($rootClass->getTableName(), $dqlAlias);
                $discrColumn = $rootClass->getDiscriminatorColumn();
                $columnAlias = $this->getSQLColumnAlias($discrColumn->name);

                $sqlSelectExpressions[] = $tblAlias . '.' . $discrColumn->name . ' AS ' . $columnAlias;

                $this->rsm->setDiscriminatorColumn($dqlAlias, $columnAlias);
                $this->rsm->addMetaResult($dqlAlias, $columnAlias, $discrColumn->fieldName, false, $discrColumn->type);
                if (! empty($discrColumn->enumType)) {
                    $this->rsm->addEnumResult($columnAlias, $discrColumn->enumType);
                }
            }

            // Add foreign key columns to SQL, if necessary
            if (! $addMetaColumns && ! $class->containsForeignIdentifier) {
                continue;
            }

            // Add foreign key columns of class and also parent classes
            foreach ($class->associationMappings as $assoc) {
                if (
                    ! $assoc->isToOneOwningSide()
                    || ( ! $addMetaColumns && ! isset($assoc->id))
                ) {
                    continue;
                }

                $targetClass   = $this->em->getClassMetadata($assoc->targetEntity);
                $isIdentifier  = (isset($assoc->id) && $assoc->id === true);
                $owningClass   = isset($assoc->inherited) ? $this->em->getClassMetadata($assoc->inherited) : $class;
                $sqlTableAlias = $this->getSQLTableAlias($owningClass->getTableName(), $dqlAlias);

                foreach ($assoc->joinColumns as $joinColumn) {
                    $columnName  = $joinColumn->name;
                    $columnAlias = $this->getSQLColumnAlias($columnName);
                    $columnType  = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);

                    $quotedColumnName       = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                    $sqlSelectExpressions[] = $sqlTableAlias . '.' . $quotedColumnName . ' AS ' . $columnAlias;

                    $this->rsm->addMetaResult($dqlAlias, $columnAlias, $columnName, $isIdentifier, $columnType);
                }
            }

            // Add foreign key columns to SQL, if necessary
            if (! $addMetaColumns) {
                continue;
            }

            // Add foreign key columns of subclasses
            foreach ($class->subClasses as $subClassName) {
                $subClass      = $this->em->getClassMetadata($subClassName);
                $sqlTableAlias = $this->getSQLTableAlias($subClass->getTableName(), $dqlAlias);

                foreach ($subClass->associationMappings as $assoc) {
                    // Skip if association is inherited
                    if (isset($assoc->inherited)) {
                        continue;
                    }

                    if ($assoc->isToOneOwningSide()) {
                        $targetClass = $this->em->getClassMetadata($assoc->targetEntity);

                        foreach ($assoc->joinColumns as $joinColumn) {
                            $columnName  = $joinColumn->name;
                            $columnAlias = $this->getSQLColumnAlias($columnName);
                            $columnType  = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);

                            $quotedColumnName       = $this->quoteStrategy->getJoinColumnName($joinColumn, $subClass, $this->platform);
                            $sqlSelectExpressions[] = $sqlTableAlias . '.' . $quotedColumnName . ' AS ' . $columnAlias;

                            $this->rsm->addMetaResult($dqlAlias, $columnAlias, $columnName, $subClass->isIdentifier($columnName), $columnType);
                        }
                    }
                }
            }
        }

        return $sql . implode(', ', $sqlSelectExpressions);
    }

    /**
     * Walks down a FromClause AST node, thereby generating the appropriate SQL.
     */
    public function walkFromClause(AST\FromClause $fromClause): string
    {
        $identificationVarDecls = $fromClause->identificationVariableDeclarations;
        $sqlParts               = [];

        foreach ($identificationVarDecls as $identificationVariableDecl) {
            $sqlParts[] = $this->walkIdentificationVariableDeclaration($identificationVariableDecl);
        }

        return ' FROM ' . implode(', ', $sqlParts);
    }

    /**
     * Walks down a IdentificationVariableDeclaration AST node, thereby generating the appropriate SQL.
     */
    public function walkIdentificationVariableDeclaration(AST\IdentificationVariableDeclaration $identificationVariableDecl): string
    {
        $sql = $this->walkRangeVariableDeclaration($identificationVariableDecl->rangeVariableDeclaration);

        if ($identificationVariableDecl->indexBy) {
            $this->walkIndexBy($identificationVariableDecl->indexBy);
        }

        foreach ($identificationVariableDecl->joins as $join) {
            $sql .= $this->walkJoin($join);
        }

        return $sql;
    }

    /**
     * Walks down a IndexBy AST node.
     */
    public function walkIndexBy(AST\IndexBy $indexBy): void
    {
        $pathExpression = $indexBy->singleValuedPathExpression;
        $alias          = $pathExpression->identificationVariable;
        assert($pathExpression->field !== null);

        switch ($pathExpression->type) {
            case AST\PathExpression::TYPE_STATE_FIELD:
                $field = $pathExpression->field;
                break;

            case AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION:
                // Just use the foreign key, i.e. u.group_id
                $fieldName = $pathExpression->field;
                $class     = $this->getMetadataForDqlAlias($alias);

                if (isset($class->associationMappings[$fieldName]->inherited)) {
                    $class = $this->em->getClassMetadata($class->associationMappings[$fieldName]->inherited);
                }

                $association = $class->associationMappings[$fieldName];

                if (! $association->isOwningSide()) {
                    throw QueryException::associationPathInverseSideNotSupported($pathExpression);
                }

                assert($association->isToOneOwningSide());

                if (count($association->sourceToTargetKeyColumns) > 1) {
                    throw QueryException::associationPathCompositeKeyNotSupported();
                }

                $field = reset($association->targetToSourceKeyColumns);
                break;

            default:
                throw QueryException::invalidPathExpression($pathExpression);
        }

        if (isset($this->scalarFields[$alias][$field])) {
            $this->rsm->addIndexByScalar($this->scalarFields[$alias][$field]);

            return;
        }

        $this->rsm->addIndexBy($alias, $field);
    }

    /**
     * Walks down a RangeVariableDeclaration AST node, thereby generating the appropriate SQL.
     */
    public function walkRangeVariableDeclaration(AST\RangeVariableDeclaration $rangeVariableDeclaration): string
    {
        return $this->generateRangeVariableDeclarationSQL($rangeVariableDeclaration, false);
    }

    /**
     * Generate appropriate SQL for RangeVariableDeclaration AST node
     */
    private function generateRangeVariableDeclarationSQL(
        AST\RangeVariableDeclaration $rangeVariableDeclaration,
        bool $buildNestedJoins,
    ): string {
        $class    = $this->em->getClassMetadata($rangeVariableDeclaration->abstractSchemaName);
        $dqlAlias = $rangeVariableDeclaration->aliasIdentificationVariable;

        if ($rangeVariableDeclaration->isRoot) {
            $this->rootAliases[] = $dqlAlias;
        }

        $sql = $this->platform->appendLockHint(
            $this->quoteStrategy->getTableName($class, $this->platform) . ' ' .
            $this->getSQLTableAlias($class->getTableName(), $dqlAlias),
            $this->query->getHint(Query::HINT_LOCK_MODE) ?: LockMode::NONE,
        );

        if (! $class->isInheritanceTypeJoined()) {
            return $sql;
        }

        $classTableInheritanceJoins = $this->generateClassTableInheritanceJoins($class, $dqlAlias);

        if (! $buildNestedJoins) {
            return $sql . $classTableInheritanceJoins;
        }

        return $classTableInheritanceJoins === '' ? $sql : '(' . $sql . $classTableInheritanceJoins . ')';
    }

    /**
     * Walks down a JoinAssociationDeclaration AST node, thereby generating the appropriate SQL.
     *
     * @phpstan-param AST\Join::JOIN_TYPE_* $joinType
     *
     * @throws QueryException
     */
    public function walkJoinAssociationDeclaration(
        AST\JoinAssociationDeclaration $joinAssociationDeclaration,
        int $joinType = AST\Join::JOIN_TYPE_INNER,
        AST\ConditionalExpression|AST\Phase2OptimizableConditional|null $condExpr = null,
    ): string {
        $sql = '';

        $associationPathExpression = $joinAssociationDeclaration->joinAssociationPathExpression;
        $joinedDqlAlias            = $joinAssociationDeclaration->aliasIdentificationVariable;
        $indexBy                   = $joinAssociationDeclaration->indexBy;

        $relation = $this->queryComponents[$joinedDqlAlias]['relation'] ?? null;
        assert($relation !== null);
        $targetClass     = $this->em->getClassMetadata($relation->targetEntity);
        $sourceClass     = $this->em->getClassMetadata($relation->sourceEntity);
        $targetTableName = $this->quoteStrategy->getTableName($targetClass, $this->platform);

        $targetTableAlias = $this->getSQLTableAlias($targetClass->getTableName(), $joinedDqlAlias);
        $sourceTableAlias = $this->getSQLTableAlias($sourceClass->getTableName(), $associationPathExpression->identificationVariable);

        // Ensure we got the owning side, since it has all mapping info
        $assoc = $this->em->getMetadataFactory()->getOwningSide($relation);

        if ($this->query->getHint(Query::HINT_INTERNAL_ITERATION) === true && (! $this->query->getHint(self::HINT_DISTINCT) || isset($this->selectedClasses[$joinedDqlAlias]))) {
            if ($relation->isToMany()) {
                throw QueryException::iterateWithFetchJoinNotAllowed($assoc);
            }
        }

        $fetchMode = $this->query->getHint('fetchMode')[$assoc->sourceEntity][$assoc->fieldName] ?? $relation->fetch;

        if ($fetchMode === ClassMetadata::FETCH_EAGER && $condExpr !== null) {
            throw QueryException::eagerFetchJoinWithNotAllowed($assoc->sourceEntity, $assoc->fieldName);
        }

        // This condition is not checking ClassMetadata::MANY_TO_ONE, because by definition it cannot
        // be the owning side and previously we ensured that $assoc is always the owning side of the associations.
        // The owning side is necessary at this point because only it contains the JoinColumn information.
        switch (true) {
            case $assoc->isToOne():
                assert($assoc->isToOneOwningSide());
                $conditions = [];

                foreach ($assoc->joinColumns as $joinColumn) {
                    $quotedSourceColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
                    $quotedTargetColumn = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $targetClass, $this->platform);

                    if ($relation->isOwningSide()) {
                        $conditions[] = $sourceTableAlias . '.' . $quotedSourceColumn . ' = ' . $targetTableAlias . '.' . $quotedTargetColumn;

                        continue;
                    }

                    $conditions[] = $sourceTableAlias . '.' . $quotedTargetColumn . ' = ' . $targetTableAlias . '.' . $quotedSourceColumn;
                }

                // Apply remaining inheritance restrictions
                $discrSql = $this->generateDiscriminatorColumnConditionSQL([$joinedDqlAlias]);

                if ($discrSql) {
                    $conditions[] = $discrSql;
                }

                // Apply the filters
                $filterExpr = $this->generateFilterConditionSQL($targetClass, $targetTableAlias);

                if ($filterExpr) {
                    $conditions[] = $filterExpr;
                }

                $targetTableJoin = [
                    'table' => $targetTableName . ' ' . $targetTableAlias,
                    'condition' => implode(' AND ', $conditions),
                ];
                break;

            case $assoc->isManyToMany():
                // Join relation table
                $joinTable      = $assoc->joinTable;
                $joinTableAlias = $this->getSQLTableAlias($joinTable->name, $joinedDqlAlias);
                $joinTableName  = $this->quoteStrategy->getJoinTableName($assoc, $sourceClass, $this->platform);

                $conditions      = [];
                $relationColumns = $relation->isOwningSide()
                    ? $assoc->joinTable->joinColumns
                    : $assoc->joinTable->inverseJoinColumns;

                foreach ($relationColumns as $joinColumn) {
                    $quotedSourceColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
                    $quotedTargetColumn = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $targetClass, $this->platform);

                    $conditions[] = $sourceTableAlias . '.' . $quotedTargetColumn . ' = ' . $joinTableAlias . '.' . $quotedSourceColumn;
                }

                $sql .= $joinTableName . ' ' . $joinTableAlias . ' ON ' . implode(' AND ', $conditions);

                // Join target table
                $sql .= $joinType === AST\Join::JOIN_TYPE_LEFT || $joinType === AST\Join::JOIN_TYPE_LEFTOUTER ? ' LEFT JOIN ' : ' INNER JOIN ';

                $conditions      = [];
                $relationColumns = $relation->isOwningSide()
                    ? $assoc->joinTable->inverseJoinColumns
                    : $assoc->joinTable->joinColumns;

                foreach ($relationColumns as $joinColumn) {
                    $quotedSourceColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
                    $quotedTargetColumn = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $targetClass, $this->platform);

                    $conditions[] = $targetTableAlias . '.' . $quotedTargetColumn . ' = ' . $joinTableAlias . '.' . $quotedSourceColumn;
                }

                // Apply remaining inheritance restrictions
                $discrSql = $this->generateDiscriminatorColumnConditionSQL([$joinedDqlAlias]);

                if ($discrSql) {
                    $conditions[] = $discrSql;
                }

                // Apply the filters
                $filterExpr = $this->generateFilterConditionSQL($targetClass, $targetTableAlias);

                if ($filterExpr) {
                    $conditions[] = $filterExpr;
                }

                $targetTableJoin = [
                    'table' => $targetTableName . ' ' . $targetTableAlias,
                    'condition' => implode(' AND ', $conditions),
                ];
                break;

            default:
                throw new BadMethodCallException('Type of association must be one of *_TO_ONE or MANY_TO_MANY');
        }

        // Handle WITH clause
        $withCondition = $condExpr === null ? '' : ('(' . $this->walkConditionalExpression($condExpr) . ')');

        if ($targetClass->isInheritanceTypeJoined()) {
            $ctiJoins = $this->generateClassTableInheritanceJoins($targetClass, $joinedDqlAlias);
            // If we have WITH condition, we need to build nested joins for target class table and cti joins
            if ($withCondition && $ctiJoins) {
                $sql .= '(' . $targetTableJoin['table'] . $ctiJoins . ') ON ' . $targetTableJoin['condition'];
            } else {
                $sql .= $targetTableJoin['table'] . ' ON ' . $targetTableJoin['condition'] . $ctiJoins;
            }
        } else {
            $sql .= $targetTableJoin['table'] . ' ON ' . $targetTableJoin['condition'];
        }

        if ($withCondition) {
            $sql .= ' AND ' . $withCondition;
        }

        // Apply the indexes
        if ($indexBy) {
            // For Many-To-One or One-To-One associations this obviously makes no sense, but is ignored silently.
            $this->walkIndexBy($indexBy);
        } elseif ($relation->isIndexed()) {
            $this->rsm->addIndexBy($joinedDqlAlias, $relation->indexBy());
        }

        return $sql;
    }

    /**
     * Walks down a FunctionNode AST node, thereby generating the appropriate SQL.
     */
    public function walkFunction(AST\Functions\FunctionNode $function): string
    {
        return $function->getSql($this);
    }

    /**
     * Walks down an OrderByClause AST node, thereby generating the appropriate SQL.
     */
    public function walkOrderByClause(AST\OrderByClause $orderByClause): string
    {
        $orderByItems = array_map($this->walkOrderByItem(...), $orderByClause->orderByItems);

        if ($orderByClause->includeCollectionOrderByItems) {
            $collectionOrderByItems = $this->generateOrderedCollectionOrderByItems();
            if ($collectionOrderByItems !== '') {
                $orderByItems = array_merge($orderByItems, (array) $collectionOrderByItems);
            }
        }

        return ' ORDER BY ' . implode(', ', $orderByItems);
    }

    /**
     * Walks down an OrderByItem AST node, thereby generating the appropriate SQL.
     */
    public function walkOrderByItem(AST\OrderByItem $orderByItem): string
    {
        $type = strtoupper($orderByItem->type);
        $expr = $orderByItem->expression;
        $sql  = $expr instanceof AST\Node
            ? $expr->dispatch($this)
            : $this->walkResultVariable($this->queryComponents[$expr]['token']->value);

        $this->orderedColumnsMap[$sql] = $type;

        if ($expr instanceof AST\Subselect) {
            return '(' . $sql . ') ' . $type;
        }

        return $sql . ' ' . $type;
    }

    /**
     * Walks down a HavingClause AST node, thereby generating the appropriate SQL.
     */
    public function walkHavingClause(AST\HavingClause $havingClause): string
    {
        return ' HAVING ' . $this->walkConditionalExpression($havingClause->conditionalExpression);
    }

    /**
     * Walks down a Join AST node and creates the corresponding SQL.
     */
    public function walkJoin(AST\Join $join): string
    {
        $joinType        = $join->joinType;
        $joinDeclaration = $join->joinAssociationDeclaration;

        $sql = $joinType === AST\Join::JOIN_TYPE_LEFT || $joinType === AST\Join::JOIN_TYPE_LEFTOUTER
            ? ' LEFT JOIN '
            : ' INNER JOIN ';

        switch (true) {
            case $joinDeclaration instanceof AST\RangeVariableDeclaration:
                $class      = $this->em->getClassMetadata($joinDeclaration->abstractSchemaName);
                $dqlAlias   = $joinDeclaration->aliasIdentificationVariable;
                $tableAlias = $this->getSQLTableAlias($class->table['name'], $dqlAlias);
                $conditions = [];

                if ($join->conditionalExpression) {
                    $conditions[] = '(' . $this->walkConditionalExpression($join->conditionalExpression) . ')';
                }

                $isUnconditionalJoin = $conditions === [];
                $condExprConjunction = $class->isInheritanceTypeJoined() && $joinType !== AST\Join::JOIN_TYPE_LEFT && $joinType !== AST\Join::JOIN_TYPE_LEFTOUTER && $isUnconditionalJoin
                    ? ' AND '
                    : ' ON ';

                $sql .= $this->generateRangeVariableDeclarationSQL($joinDeclaration, ! $isUnconditionalJoin);

                // Apply remaining inheritance restrictions
                $discrSql = $this->generateDiscriminatorColumnConditionSQL([$dqlAlias]);

                if ($discrSql) {
                    $conditions[] = $discrSql;
                }

                // Apply the filters
                $filterExpr = $this->generateFilterConditionSQL($class, $tableAlias);

                if ($filterExpr) {
                    $conditions[] = $filterExpr;
                }

                if ($conditions) {
                    $sql .= $condExprConjunction . implode(' AND ', $conditions);
                }

                break;

            case $joinDeclaration instanceof AST\JoinAssociationDeclaration:
                $sql .= $this->walkJoinAssociationDeclaration($joinDeclaration, $joinType, $join->conditionalExpression);
                break;
        }

        return $sql;
    }

    /**
     * Walks down a CoalesceExpression AST node and generates the corresponding SQL.
     */
    public function walkCoalesceExpression(AST\CoalesceExpression $coalesceExpression): string
    {
        $sql = 'COALESCE(';

        $scalarExpressions = [];

        foreach ($coalesceExpression->scalarExpressions as $scalarExpression) {
            $scalarExpressions[] = $this->walkSimpleArithmeticExpression($scalarExpression);
        }

        return $sql . implode(', ', $scalarExpressions) . ')';
    }

    /**
     * Walks down a NullIfExpression AST node and generates the corresponding SQL.
     */
    public function walkNullIfExpression(AST\NullIfExpression $nullIfExpression): string
    {
        $firstExpression = is_string($nullIfExpression->firstExpression)
            ? $this->conn->quote($nullIfExpression->firstExpression)
            : $this->walkSimpleArithmeticExpression($nullIfExpression->firstExpression);

        $secondExpression = is_string($nullIfExpression->secondExpression)
            ? $this->conn->quote($nullIfExpression->secondExpression)
            : $this->walkSimpleArithmeticExpression($nullIfExpression->secondExpression);

        return 'NULLIF(' . $firstExpression . ', ' . $secondExpression . ')';
    }

    /**
     * Walks down a GeneralCaseExpression AST node and generates the corresponding SQL.
     */
    public function walkGeneralCaseExpression(AST\GeneralCaseExpression $generalCaseExpression): string
    {
        $sql = 'CASE';

        foreach ($generalCaseExpression->whenClauses as $whenClause) {
            $sql .= ' WHEN ' . $this->walkConditionalExpression($whenClause->caseConditionExpression);
            $sql .= ' THEN ' . $this->walkSimpleArithmeticExpression($whenClause->thenScalarExpression);
        }

        $sql .= ' ELSE ' . $this->walkSimpleArithmeticExpression($generalCaseExpression->elseScalarExpression) . ' END';

        return $sql;
    }

    /**
     * Walks down a SimpleCaseExpression AST node and generates the corresponding SQL.
     */
    public function walkSimpleCaseExpression(AST\SimpleCaseExpression $simpleCaseExpression): string
    {
        $sql = 'CASE ' . $this->walkStateFieldPathExpression($simpleCaseExpression->caseOperand);

        foreach ($simpleCaseExpression->simpleWhenClauses as $simpleWhenClause) {
            $sql .= ' WHEN ' . $this->walkSimpleArithmeticExpression($simpleWhenClause->caseScalarExpression);
            $sql .= ' THEN ' . $this->walkSimpleArithmeticExpression($simpleWhenClause->thenScalarExpression);
        }

        $sql .= ' ELSE ' . $this->walkSimpleArithmeticExpression($simpleCaseExpression->elseScalarExpression) . ' END';

        return $sql;
    }

    /**
     * Walks down a SelectExpression AST node and generates the corresponding SQL.
     */
    public function walkSelectExpression(AST\SelectExpression $selectExpression): string
    {
        $sql    = '';
        $expr   = $selectExpression->expression;
        $hidden = $selectExpression->hiddenAliasResultVariable;

        switch (true) {
            case $expr instanceof AST\PathExpression:
                if ($expr->type !== AST\PathExpression::TYPE_STATE_FIELD) {
                    throw QueryException::invalidPathExpression($expr);
                }

                assert($expr->field !== null);
                $fieldName = $expr->field;
                $dqlAlias  = $expr->identificationVariable;
                $class     = $this->getMetadataForDqlAlias($dqlAlias);

                $resultAlias = $selectExpression->fieldIdentificationVariable ?: $fieldName;
                $tableName   = $class->isInheritanceTypeJoined()
                    ? $this->em->getUnitOfWork()->getEntityPersister($class->name)->getOwningTable($fieldName)
                    : $class->getTableName();

                $sqlTableAlias = $this->getSQLTableAlias($tableName, $dqlAlias);
                $fieldMapping  = $class->fieldMappings[$fieldName];
                $columnName    = $this->quoteStrategy->getColumnName($fieldName, $class, $this->platform);
                $columnAlias   = $this->getSQLColumnAlias($fieldMapping->columnName);
                $col           = $sqlTableAlias . '.' . $columnName;

                $type = $this->typeProvider->get($fieldMapping->type);
                $col  = $type->convertToPHPValueSQL($col, $this->conn->getDatabasePlatform());

                $sql .= $col . ' AS ' . $columnAlias;

                $this->scalarResultAliasMap[$resultAlias] = $columnAlias;

                if (! $hidden) {
                    $this->rsm->addScalarResult($columnAlias, $resultAlias, $fieldMapping->type);
                    $this->scalarFields[$dqlAlias][$fieldName] = $columnAlias;

                    if (! empty($fieldMapping->enumType)) {
                        $this->rsm->addEnumResult($columnAlias, $fieldMapping->enumType);
                    }
                }

                break;

            case $expr instanceof AST\AggregateExpression:
            case $expr instanceof AST\Functions\FunctionNode:
            case $expr instanceof AST\SimpleArithmeticExpression:
            case $expr instanceof AST\ArithmeticTerm:
            case $expr instanceof AST\ArithmeticFactor:
            case $expr instanceof AST\ParenthesisExpression:
            case $expr instanceof AST\Literal:
            case $expr instanceof AST\NullIfExpression:
            case $expr instanceof AST\CoalesceExpression:
            case $expr instanceof AST\GeneralCaseExpression:
            case $expr instanceof AST\SimpleCaseExpression:
                $columnAlias = $this->getSQLColumnAlias('sclr');
                $resultAlias = $selectExpression->fieldIdentificationVariable ?: $this->scalarResultCounter++;

                $sql .= $expr->dispatch($this) . ' AS ' . $columnAlias;

                $this->scalarResultAliasMap[$resultAlias] = $columnAlias;

                if ($hidden) {
                    break;
                }

                if ($expr instanceof Query\AST\ExpressionWithReturnType) {
                    $this->rsm->addScalarResult($columnAlias, $resultAlias, $expr->getReturnTypeName());

                    break;
                }

                if ($expr instanceof Query\AST\TypedExpression) {
                    Deprecation::trigger(
                        'doctrine/orm',
                        'https://github.com/doctrine/orm/pull/12543',
                        'Implementing %s is deprecated, implement %s instead.',
                        Query\AST\TypedExpression::class, // @phpstan-ignore classConstant.deprecatedInterface
                        Query\AST\ExpressionWithReturnType::class,
                    );

                    // TypedExpression only exposes a Type instance, so the name has to be looked up.
                    // This branch and Type::lookupName() are both deprecated and go away together.
                    // @phpstan-ignore method.deprecatedInterface, staticMethod.deprecated
                    $this->rsm->addScalarResult($columnAlias, $resultAlias, Type::lookupName($expr->getReturnType()));

                    break;
                }

                // Conceptually we could resolve field type here by traverse through AST to retrieve field type,
                // but this is not a feasible solution; assume 'string'.
                $this->rsm->addScalarResult($columnAlias, $resultAlias, Types::STRING);

                break;

            case $expr instanceof AST\Subselect:
                $columnAlias = $this->getSQLColumnAlias('sclr');
                $resultAlias = $selectExpression->fieldIdentificationVariable ?: $this->scalarResultCounter++;

                $sql .= '(' . $this->walkSubselect($expr) . ') AS ' . $columnAlias;

                $this->scalarResultAliasMap[$resultAlias] = $columnAlias;

                if (! $hidden) {
                    // We cannot resolve field type here; assume 'string'.
                    $this->rsm->addScalarResult($columnAlias, $resultAlias, Types::STRING);
                }

                break;

            case $expr instanceof AST\NewObjectExpression:
                $sql .= $this->walkNewObject($expr, $selectExpression->fieldIdentificationVariable);
                break;

            default:
                // IdentificationVariable or PartialObjectExpression
                if ($expr instanceof AST\PartialObjectExpression) {
                    $this->query->setHint(self::HINT_PARTIAL, true);

                    $dqlAlias        = $expr->identificationVariable;
                    $partialFieldSet = $expr->partialFieldSet;
                } else {
                    $dqlAlias        = $expr;
                    $partialFieldSet = [];
                }

                $sql .= implode(', ', $this->walkObjectExpression($dqlAlias, $partialFieldSet, $selectExpression->fieldIdentificationVariable ?: null));
        }

        return $sql;
    }

    /**
     * Runs the given callback with the raw columns of the selected entity fields,
     * without applying the PHP value conversion of their mapping types.
     *
     * The paginator uses it to generate its identifier subquery, which is never
     * hydrated, so its ORDER BY must use the stored column.
     *
     * @internal
     *
     * @param callable(): T $callback
     *
     * @return T
     *
     * @template T
     */
    protected function withRawColumns(callable $callback): mixed
    {
        if ($this->useRawColumns) {
            throw new LogicException('Raw columns are already used.');
        }

        $this->useRawColumns = true;

        try {
            return $callback();
        } finally {
            $this->useRawColumns = false;
        }
    }

    /**
     * Walks down an Object Expression AST node and return Sql Parts
     *
     * @param mixed[] $partialFieldSet
     *
     * @return string[]
     */
    public function walkObjectExpression(string $dqlAlias, array $partialFieldSet, string|null $resultAlias): array
    {
        $class = $this->getMetadataForDqlAlias($dqlAlias);

        if (! isset($this->selectedClasses[$dqlAlias])) {
            $this->selectedClasses[$dqlAlias] = [
                'class'       => $class,
                'dqlAlias'    => $dqlAlias,
                'resultAlias' => $resultAlias,
                'partial'     => $partialFieldSet !== [],
            ];
        }

        $sqlParts = [];

        // Select all fields from the queried class
        foreach ($class->fieldMappings as $fieldName => $mapping) {
            if ($partialFieldSet && ! in_array($fieldName, $partialFieldSet, true)) {
                continue;
            }

            $tableName = isset($mapping->inherited)
                ? $this->em->getClassMetadata($mapping->inherited)->getTableName()
                : $class->getTableName();

            $sqlTableAlias    = $this->getSQLTableAlias($tableName, $dqlAlias);
            $columnAlias      = $this->getSQLColumnAlias($mapping->columnName);
            $quotedColumnName = $this->quoteStrategy->getColumnName($fieldName, $class, $this->platform);

            $col = $sqlTableAlias . '.' . $quotedColumnName;

            if (! $this->useRawColumns) {
                $type = $this->typeProvider->get($mapping->type);
                $col  = $type->convertToPHPValueSQL($col, $this->platform);
            }

            $sqlParts[] = $col . ' AS ' . $columnAlias;

            if ($resultAlias !== null) {
                $this->scalarResultAliasMap[$resultAlias][] = $columnAlias;
            }

            $this->rsm->addFieldResult($dqlAlias, $columnAlias, $fieldName, $class->name);

            if (! empty($mapping->enumType)) {
                $this->rsm->addEnumResult($columnAlias, $mapping->enumType);
            }
        }

        // Add any additional fields of subclasses (excluding inherited fields)
        // 1) on Single Table Inheritance: always, since its marginal overhead
        // 2) on Class Table Inheritance only if partial objects are disallowed,
        //    since it requires outer joining subtables.
        if ($class->isInheritanceTypeSingleTable() || ! $this->query->getHint(Query::HINT_FORCE_PARTIAL_LOAD)) {
            foreach ($class->subClasses as $subClassName) {
                $subClass      = $this->em->getClassMetadata($subClassName);
                $sqlTableAlias = $this->getSQLTableAlias($subClass->getTableName(), $dqlAlias);

                foreach ($subClass->fieldMappings as $fieldName => $mapping) {
                    if (isset($mapping->inherited) || ($partialFieldSet && ! in_array($fieldName, $partialFieldSet, true))) {
                        continue;
                    }

                    $columnAlias      = $this->getSQLColumnAlias($mapping->columnName);
                    $quotedColumnName = $this->quoteStrategy->getColumnName($fieldName, $subClass, $this->platform);

                    $col = $sqlTableAlias . '.' . $quotedColumnName;

                    if (! $this->useRawColumns) {
                        $type = $this->typeProvider->get($mapping->type);
                        $col  = $type->convertToPHPValueSQL($col, $this->platform);
                    }

                    $sqlParts[] = $col . ' AS ' . $columnAlias;

                    if ($resultAlias !== null) {
                        $this->scalarResultAliasMap[$resultAlias][] = $columnAlias;
                    }

                    $this->rsm->addFieldResult($dqlAlias, $columnAlias, $fieldName, $subClassName);
                }
            }
        }

        return $sqlParts;
    }

    public function walkQuantifiedExpression(AST\QuantifiedExpression $qExpr): string
    {
        return ' ' . strtoupper($qExpr->type) . '(' . $this->walkSubselect($qExpr->subselect) . ')';
    }

    /**
     * Walks down a Subselect AST node, thereby generating the appropriate SQL.
     */
    public function walkSubselect(AST\Subselect $subselect): string
    {
        $useAliasesBefore  = $this->useSqlTableAliases;
        $rootAliasesBefore = $this->rootAliases;

        $this->rootAliases        = []; // reset the rootAliases for the subselect
        $this->useSqlTableAliases = true;

        $sql  = $this->walkSimpleSelectClause($subselect->simpleSelectClause);
        $sql .= $this->walkSubselectFromClause($subselect->subselectFromClause);
        $sql .= $this->walkWhereClause($subselect->whereClause);

        $sql .= $subselect->groupByClause ? $this->walkGroupByClause($subselect->groupByClause) : '';
        $sql .= $subselect->havingClause ? $this->walkHavingClause($subselect->havingClause) : '';
        $sql .= $subselect->orderByClause ? $this->walkOrderByClause($subselect->orderByClause) : '';

        $this->rootAliases        = $rootAliasesBefore; // put the main aliases back
        $this->useSqlTableAliases = $useAliasesBefore;

        return $sql;
    }

    /**
     * Walks down a SubselectFromClause AST node, thereby generating the appropriate SQL.
     */
    public function walkSubselectFromClause(AST\SubselectFromClause $subselectFromClause): string
    {
        $identificationVarDecls = $subselectFromClause->identificationVariableDeclarations;
        $sqlParts               = [];

        foreach ($identificationVarDecls as $subselectIdVarDecl) {
            $sqlParts[] = $this->walkIdentificationVariableDeclaration($subselectIdVarDecl);
        }

        return ' FROM ' . implode(', ', $sqlParts);
    }

    /**
     * Walks down a SimpleSelectClause AST node, thereby generating the appropriate SQL.
     */
    public function walkSimpleSelectClause(AST\SimpleSelectClause $simpleSelectClause): string
    {
        return 'SELECT' . ($simpleSelectClause->isDistinct ? ' DISTINCT' : '')
            . $this->walkSimpleSelectExpression($simpleSelectClause->simpleSelectExpression);
    }

    public function walkParenthesisExpression(AST\ParenthesisExpression $parenthesisExpression): string
    {
        return sprintf('(%s)', $parenthesisExpression->expression->dispatch($this));
    }

    public function walkNewObject(AST\NewObjectExpression $newObjectExpression, string|null $newObjectResultAlias = null): string
    {
        $sqlSelectExpressions = [];
        $objIndex             = $newObjectResultAlias ?: $this->newObjectCounter++;

        foreach ($newObjectExpression->args as $argIndex => $e) {
            $resultAlias = $this->scalarResultCounter++;
            $columnAlias = $this->getSQLColumnAlias('sclr');
            $fieldType   = 'string';

            switch (true) {
                case $e instanceof AST\NewObjectExpression:
                    $sqlSelectExpressions[]                            = $e->dispatch($this, $columnAlias);
                    $this->rsm->nestedNewObjectArguments[$columnAlias] = ['ownerIndex' => $objIndex, 'argIndex' => $argIndex, 'argAlias' => $columnAlias];
                    break;

                case $e instanceof AST\Subselect:
                    $sqlSelectExpressions[] = '(' . $e->dispatch($this) . ') AS ' . $columnAlias;
                    break;

                case $e instanceof AST\PathExpression:
                    assert($e->field !== null);
                    $dqlAlias     = $e->identificationVariable;
                    $class        = $this->getMetadataForDqlAlias($dqlAlias);
                    $fieldName    = $e->field;
                    $fieldMapping = $class->fieldMappings[$fieldName];
                    $fieldType    = $fieldMapping->type;
                    $col          = trim($e->dispatch($this));

                    $type = $this->typeProvider->get($fieldType);
                    $col  = $type->convertToPHPValueSQL($col, $this->platform);

                    $sqlSelectExpressions[] = $col . ' AS ' . $columnAlias;

                    if (! empty($fieldMapping->enumType)) {
                        $this->rsm->addEnumResult($columnAlias, $fieldMapping->enumType);
                    }

                    break;

                case $e instanceof AST\Literal:
                    switch ($e->type) {
                        case AST\Literal::BOOLEAN:
                            $fieldType = 'boolean';
                            break;

                        case AST\Literal::NUMERIC:
                            $fieldType = is_float($e->value) ? 'float' : 'integer';
                            break;
                    }

                    $sqlSelectExpressions[] = trim($e->dispatch($this)) . ' AS ' . $columnAlias;
                    break;

                case $e instanceof AST\EntityAsDtoArgumentExpression:
                    $alias                                             = $e->identificationVariable ?: $columnAlias;
                    $this->rsm->nestedNewObjectArguments[$columnAlias] = ['ownerIndex' => $objIndex, 'argIndex' => $argIndex, 'argAlias' => $alias];
                    $this->rsm->nestedEntities[$alias]                 = ['parent' => $objIndex, 'argIndex' => $argIndex, 'type' => 'entity'];

                    $sqlSelectExpressions[] = trim($e->dispatch($this));
                    break;

                default:
                    $sqlSelectExpressions[] = trim($e->dispatch($this)) . ' AS ' . $columnAlias;
                    break;
            }

            $this->scalarResultAliasMap[$resultAlias] = $columnAlias;
            $this->rsm->addScalarResult($columnAlias, $resultAlias, $fieldType);

            $this->rsm->newObjectMappings[$columnAlias] = [
                'objIndex'  => $objIndex,
                'argIndex'  => $argIndex,
            ];
        }

        $this->rsm->newObject[$objIndex] = $newObjectExpression->className;

        return implode(', ', $sqlSelectExpressions);
    }

    /**
     * Walks down a SimpleSelectExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkSimpleSelectExpression(AST\SimpleSelectExpression $simpleSelectExpression): string
    {
        $expr = $simpleSelectExpression->expression;
        $sql  = ' ';

        switch (true) {
            case $expr instanceof AST\PathExpression:
                $sql .= $this->walkPathExpression($expr);
                break;

            case $expr instanceof AST\Subselect:
                $alias = $simpleSelectExpression->fieldIdentificationVariable ?: $this->scalarResultCounter++;

                $columnAlias                        = 'sclr' . $this->aliasCounter++;
                $this->scalarResultAliasMap[$alias] = $columnAlias;

                $sql .= '(' . $this->walkSubselect($expr) . ') AS ' . $columnAlias;
                break;

            case $expr instanceof AST\Functions\FunctionNode:
            case $expr instanceof AST\SimpleArithmeticExpression:
            case $expr instanceof AST\ArithmeticTerm:
            case $expr instanceof AST\ArithmeticFactor:
            case $expr instanceof AST\Literal:
            case $expr instanceof AST\NullIfExpression:
            case $expr instanceof AST\CoalesceExpression:
            case $expr instanceof AST\GeneralCaseExpression:
            case $expr instanceof AST\SimpleCaseExpression:
                $alias = $simpleSelectExpression->fieldIdentificationVariable ?: $this->scalarResultCounter++;

                $columnAlias                        = $this->getSQLColumnAlias('sclr');
                $this->scalarResultAliasMap[$alias] = $columnAlias;

                $sql .= $expr->dispatch($this) . ' AS ' . $columnAlias;
                break;

            case $expr instanceof AST\ParenthesisExpression:
                $sql .= $this->walkParenthesisExpression($expr);
                break;

            default: // IdentificationVariable
                $sql .= $this->walkEntityIdentificationVariable($expr);
                break;
        }

        return $sql;
    }

    /**
     * Walks down an AggregateExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkAggregateExpression(AST\AggregateExpression $aggExpression): string
    {
        return $aggExpression->functionName . '(' . ($aggExpression->isDistinct ? 'DISTINCT ' : '')
            . $this->walkSimpleArithmeticExpression($aggExpression->pathExpression) . ')';
    }

    /**
     * Walks down a GroupByClause AST node, thereby generating the appropriate SQL.
     */
    public function walkGroupByClause(AST\GroupByClause $groupByClause): string
    {
        $sqlParts = [];

        foreach ($groupByClause->groupByItems as $groupByItem) {
            $sqlParts[] = $this->walkGroupByItem($groupByItem);
        }

        return ' GROUP BY ' . implode(', ', $sqlParts);
    }

    /**
     * Walks down a GroupByItem AST node, thereby generating the appropriate SQL.
     */
    public function walkGroupByItem(AST\PathExpression|string $groupByItem): string
    {
        // StateFieldPathExpression
        if (! is_string($groupByItem)) {
            return $this->walkPathExpression($groupByItem);
        }

        // ResultVariable
        if (isset($this->queryComponents[$groupByItem]['resultVariable'])) {
            $resultVariable = $this->queryComponents[$groupByItem]['resultVariable'];

            if ($resultVariable instanceof AST\PathExpression) {
                return $this->walkPathExpression($resultVariable);
            }

            if ($resultVariable instanceof AST\Node && isset($resultVariable->pathExpression)) {
                return $this->walkPathExpression($resultVariable->pathExpression);
            }

            return $this->walkResultVariable($groupByItem);
        }

        // IdentificationVariable
        $sqlParts = [];

        foreach ($this->getMetadataForDqlAlias($groupByItem)->fieldNames as $field) {
            $item       = new AST\PathExpression(AST\PathExpression::TYPE_STATE_FIELD, $groupByItem, $field);
            $item->type = AST\PathExpression::TYPE_STATE_FIELD;

            $sqlParts[] = $this->walkPathExpression($item);
        }

        foreach ($this->getMetadataForDqlAlias($groupByItem)->associationMappings as $mapping) {
            if ($mapping->isToOneOwningSide()) {
                $item       = new AST\PathExpression(AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION, $groupByItem, $mapping->fieldName);
                $item->type = AST\PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION;

                $sqlParts[] = $this->walkPathExpression($item);
            }
        }

        return implode(', ', $sqlParts);
    }

    /**
     * Walks down a DeleteClause AST node, thereby generating the appropriate SQL.
     */
    public function walkDeleteClause(AST\DeleteClause $deleteClause): string
    {
        $class     = $this->em->getClassMetadata($deleteClause->abstractSchemaName);
        $tableName = $class->getTableName();
        $sql       = 'DELETE FROM ' . $this->quoteStrategy->getTableName($class, $this->platform);

        $this->setSQLTableAlias($tableName, $tableName, $deleteClause->aliasIdentificationVariable);
        $this->rootAliases[] = $deleteClause->aliasIdentificationVariable;

        return $sql;
    }

    /**
     * Walks down an UpdateClause AST node, thereby generating the appropriate SQL.
     */
    public function walkUpdateClause(AST\UpdateClause $updateClause): string
    {
        $class     = $this->em->getClassMetadata($updateClause->abstractSchemaName);
        $tableName = $class->getTableName();
        $sql       = 'UPDATE ' . $this->quoteStrategy->getTableName($class, $this->platform);

        $this->setSQLTableAlias($tableName, $tableName, $updateClause->aliasIdentificationVariable);
        $this->rootAliases[] = $updateClause->aliasIdentificationVariable;

        return $sql . ' SET ' . implode(', ', array_map($this->walkUpdateItem(...), $updateClause->updateItems));
    }

    /**
     * Walks down an UpdateItem AST node, thereby generating the appropriate SQL.
     */
    public function walkUpdateItem(AST\UpdateItem $updateItem): string
    {
        $useTableAliasesBefore    = $this->useSqlTableAliases;
        $this->useSqlTableAliases = false;

        $sql      = $this->walkPathExpression($updateItem->pathExpression) . ' = ';
        $newValue = $updateItem->newValue;

        $sql .= match (true) {
            $newValue instanceof AST\Node => $newValue->dispatch($this),
            $newValue === null => 'NULL',
        };

        $this->useSqlTableAliases = $useTableAliasesBefore;

        return $sql;
    }

    /**
     * Walks down a WhereClause AST node, thereby generating the appropriate SQL.
     *
     * WhereClause or not, the appropriate discriminator sql is added.
     */
    public function walkWhereClause(AST\WhereClause|null $whereClause): string
    {
        $condSql  = $whereClause !== null ? $this->walkConditionalExpression($whereClause->conditionalExpression) : '';
        $discrSql = $this->generateDiscriminatorColumnConditionSQL($this->rootAliases);

        if ($this->em->hasFilters()) {
            $filterClauses = [];
            foreach ($this->rootAliases as $dqlAlias) {
                $class      = $this->getMetadataForDqlAlias($dqlAlias);
                $tableAlias = $this->getSQLTableAlias($class->table['name'], $dqlAlias);

                $filterExpr = $this->generateFilterConditionSQL($class, $tableAlias);
                if ($filterExpr) {
                    $filterClauses[] = $filterExpr;
                }
            }

            if (count($filterClauses)) {
                if ($condSql) {
                    $condSql = '(' . $condSql . ') AND ';
                }

                $condSql .= implode(' AND ', $filterClauses);
            }
        }

        if ($condSql) {
            return ' WHERE ' . (! $discrSql ? $condSql : '(' . $condSql . ') AND ' . $discrSql);
        }

        if ($discrSql) {
            return ' WHERE ' . $discrSql;
        }

        return '';
    }

    /**
     * Walk down a ConditionalExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkConditionalExpression(
        AST\ConditionalExpression|AST\Phase2OptimizableConditional $condExpr,
    ): string {
        // Phase 2 AST optimization: Skip processing of ConditionalExpression
        // if only one ConditionalTerm is defined
        if (! ($condExpr instanceof AST\ConditionalExpression)) {
            return $this->walkConditionalTerm($condExpr);
        }

        return implode(' OR ', array_map($this->walkConditionalTerm(...), $condExpr->conditionalTerms));
    }

    /**
     * Walks down a ConditionalTerm AST node, thereby generating the appropriate SQL.
     */
    public function walkConditionalTerm(
        AST\ConditionalTerm|AST\ConditionalPrimary|AST\ConditionalFactor $condTerm,
    ): string {
        // Phase 2 AST optimization: Skip processing of ConditionalTerm
        // if only one ConditionalFactor is defined
        if (! ($condTerm instanceof AST\ConditionalTerm)) {
            return $this->walkConditionalFactor($condTerm);
        }

        return implode(' AND ', array_map($this->walkConditionalFactor(...), $condTerm->conditionalFactors));
    }

    /**
     * Walks down a ConditionalFactor AST node, thereby generating the appropriate SQL.
     */
    public function walkConditionalFactor(
        AST\ConditionalFactor|AST\ConditionalPrimary $factor,
    ): string {
        // Phase 2 AST optimization: Skip processing of ConditionalFactor
        // if only one ConditionalPrimary is defined
        return ! ($factor instanceof AST\ConditionalFactor)
            ? $this->walkConditionalPrimary($factor)
            : ($factor->not ? 'NOT ' : '') . $this->walkConditionalPrimary($factor->conditionalPrimary);
    }

    /**
     * Walks down a ConditionalPrimary AST node, thereby generating the appropriate SQL.
     */
    public function walkConditionalPrimary(AST\ConditionalPrimary $primary): string
    {
        if ($primary->isSimpleConditionalExpression()) {
            return $primary->simpleConditionalExpression->dispatch($this);
        }

        if ($primary->isConditionalExpression()) {
            $condExpr = $primary->conditionalExpression;

            return '(' . $this->walkConditionalExpression($condExpr) . ')';
        }

        throw new LogicException('Unexpected state of ConditionalPrimary node.');
    }

    /**
     * Walks down an ExistsExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkExistsExpression(AST\ExistsExpression $existsExpr): string
    {
        $sql = $existsExpr->not ? 'NOT ' : '';

        $sql .= 'EXISTS (' . $this->walkSubselect($existsExpr->subselect) . ')';

        return $sql;
    }

    /**
     * Walks down a CollectionMemberExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkCollectionMemberExpression(AST\CollectionMemberExpression $collMemberExpr): string
    {
        $sql  = $collMemberExpr->not ? 'NOT ' : '';
        $sql .= 'EXISTS (SELECT 1 FROM ';

        $entityExpr   = $collMemberExpr->entityExpression;
        $collPathExpr = $collMemberExpr->collectionValuedPathExpression;
        assert($collPathExpr->field !== null);

        $fieldName = $collPathExpr->field;
        $dqlAlias  = $collPathExpr->identificationVariable;

        $class = $this->getMetadataForDqlAlias($dqlAlias);

        switch (true) {
            // InputParameter
            case $entityExpr instanceof AST\InputParameter:
                $dqlParamKey = $entityExpr->name;
                $entitySql   = '?';
                break;

            // SingleValuedAssociationPathExpression | IdentificationVariable
            case $entityExpr instanceof AST\PathExpression:
                $entitySql = $this->walkPathExpression($entityExpr);
                break;

            default:
                throw new BadMethodCallException('Not implemented');
        }

        $assoc = $class->associationMappings[$fieldName];

        if ($assoc->isOneToMany()) {
            $targetClass      = $this->em->getClassMetadata($assoc->targetEntity);
            $targetTableAlias = $this->getSQLTableAlias($targetClass->getTableName());
            $sourceTableAlias = $this->getSQLTableAlias($class->getTableName(), $dqlAlias);

            $sql .= $this->quoteStrategy->getTableName($targetClass, $this->platform) . ' ' . $targetTableAlias . ' WHERE ';

            $owningAssoc = $targetClass->associationMappings[$assoc->mappedBy];
            assert($owningAssoc->isManyToOne());
            $sqlParts = [];

            foreach ($owningAssoc->targetToSourceKeyColumns as $targetColumn => $sourceColumn) {
                $targetColumn = $this->quoteStrategy->getColumnName($class->fieldNames[$targetColumn], $class, $this->platform);

                $sqlParts[] = $sourceTableAlias . '.' . $targetColumn . ' = ' . $targetTableAlias . '.' . $sourceColumn;
            }

            foreach ($this->quoteStrategy->getIdentifierColumnNames($targetClass, $this->platform) as $targetColumnName) {
                if (isset($dqlParamKey)) {
                    $this->parserResult->addParameterMapping($dqlParamKey, $this->sqlParamIndex++);
                }

                $sqlParts[] = $targetTableAlias . '.' . $targetColumnName . ' = ' . $entitySql;
            }

            $sql .= implode(' AND ', $sqlParts);
        } else { // many-to-many
            $targetClass = $this->em->getClassMetadata($assoc->targetEntity);

            $owningAssoc = $this->em->getMetadataFactory()->getOwningSide($assoc);
            assert($owningAssoc->isManyToManyOwningSide());
            $joinTable = $owningAssoc->joinTable;

            // SQL table aliases
            $joinTableAlias   = $this->getSQLTableAlias($joinTable->name);
            $sourceTableAlias = $this->getSQLTableAlias($class->getTableName(), $dqlAlias);

            $sql .= $this->quoteStrategy->getJoinTableName($owningAssoc, $targetClass, $this->platform) . ' ' . $joinTableAlias . ' WHERE ';

            $joinColumns = $assoc->isOwningSide() ? $joinTable->joinColumns : $joinTable->inverseJoinColumns;
            $sqlParts    = [];

            foreach ($joinColumns as $joinColumn) {
                $targetColumn = $this->quoteStrategy->getColumnName($class->fieldNames[$joinColumn->referencedColumnName], $class, $this->platform);

                $sqlParts[] = $joinTableAlias . '.' . $joinColumn->name . ' = ' . $sourceTableAlias . '.' . $targetColumn;
            }

            $joinColumns = $assoc->isOwningSide() ? $joinTable->inverseJoinColumns : $joinTable->joinColumns;

            foreach ($joinColumns as $joinColumn) {
                if (isset($dqlParamKey)) {
                    $this->parserResult->addParameterMapping($dqlParamKey, $this->sqlParamIndex++);
                }

                $sqlParts[] = $joinTableAlias . '.' . $joinColumn->name . ' IN (' . $entitySql . ')';
            }

            $sql .= implode(' AND ', $sqlParts);
        }

        return $sql . ')';
    }

    /**
     * Walks down an EmptyCollectionComparisonExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkEmptyCollectionComparisonExpression(AST\EmptyCollectionComparisonExpression $emptyCollCompExpr): string
    {
        $sizeFunc                           = new AST\Functions\SizeFunction('size');
        $sizeFunc->collectionPathExpression = $emptyCollCompExpr->expression;

        return $sizeFunc->getSql($this) . ($emptyCollCompExpr->not ? ' > 0' : ' = 0');
    }

    /**
     * Walks down a NullComparisonExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkNullComparisonExpression(AST\NullComparisonExpression $nullCompExpr): string
    {
        $expression = $nullCompExpr->expression;
        $comparison = ' IS' . ($nullCompExpr->not ? ' NOT' : '') . ' NULL';

        // Handle ResultVariable
        if (is_string($expression) && isset($this->queryComponents[$expression]['resultVariable'])) {
            return $this->walkResultVariable($expression) . $comparison;
        }

        // Handle InputParameter mapping inclusion to ParserResult
        if ($expression instanceof AST\InputParameter) {
            return $this->walkInputParameter($expression) . $comparison;
        }

        assert(! is_string($expression));

        return $expression->dispatch($this) . $comparison;
    }

    /**
     * Walks down an InExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkInListExpression(AST\InListExpression $inExpr): string
    {
        return $this->walkArithmeticExpression($inExpr->expression)
            . ($inExpr->not ? ' NOT' : '') . ' IN ('
            . implode(', ', array_map($this->walkInParameter(...), $inExpr->literals))
            . ')';
    }

    /**
     * Walks down an InExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkInSubselectExpression(AST\InSubselectExpression $inExpr): string
    {
        return $this->walkArithmeticExpression($inExpr->expression)
            . ($inExpr->not ? ' NOT' : '') . ' IN ('
            . $this->walkSubselect($inExpr->subselect)
            . ')';
    }

    /**
     * Walks down an InstanceOfExpression AST node, thereby generating the appropriate SQL.
     *
     * @throws QueryException
     */
    public function walkInstanceOfExpression(AST\InstanceOfExpression $instanceOfExpr): string
    {
        $sql = '';

        $dqlAlias   = $instanceOfExpr->identificationVariable;
        $discrClass = $class = $this->getMetadataForDqlAlias($dqlAlias);

        if ($class->discriminatorColumn) {
            $discrClass = $this->em->getClassMetadata($class->rootEntityName);
        }

        if ($this->useSqlTableAliases) {
            $sql .= $this->getSQLTableAlias($discrClass->getTableName(), $dqlAlias) . '.';
        }

        $sql .= $class->getDiscriminatorColumn()->name . ($instanceOfExpr->not ? ' NOT IN ' : ' IN ');
        $sql .= $this->getChildDiscriminatorsFromClassMetadata($discrClass, $instanceOfExpr);

        return $sql;
    }

    public function walkInParameter(mixed $inParam): string
    {
        return $inParam instanceof AST\InputParameter
            ? $this->walkInputParameter($inParam)
            : $this->walkArithmeticExpression($inParam);
    }

    /**
     * Walks down a literal that represents an AST node, thereby generating the appropriate SQL.
     */
    public function walkLiteral(AST\Literal $literal): string
    {
        return match ($literal->type) {
            AST\Literal::STRING => $this->conn->quote($literal->value),
            AST\Literal::BOOLEAN => (string) $this->conn->getDatabasePlatform()->convertBooleans(strtolower($literal->value) === 'true'),
            AST\Literal::NUMERIC => (string) $literal->value,
            AST\Literal::NULL => 'NULL',
            default => throw QueryException::invalidLiteral($literal),
        };
    }

    /**
     * Walks down a BetweenExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkBetweenExpression(AST\BetweenExpression $betweenExpr): string
    {
        $sql = $this->walkArithmeticExpression($betweenExpr->expression);

        if ($betweenExpr->not) {
            $sql .= ' NOT';
        }

        $sql .= ' BETWEEN ' . $this->walkArithmeticExpression($betweenExpr->leftBetweenExpression)
            . ' AND ' . $this->walkArithmeticExpression($betweenExpr->rightBetweenExpression);

        return $sql;
    }

    /**
     * Walks down a LikeExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkLikeExpression(AST\LikeExpression $likeExpr): string
    {
        $stringExpr = $likeExpr->stringExpression;
        if (is_string($stringExpr)) {
            if (! isset($this->queryComponents[$stringExpr]['resultVariable'])) {
                throw new LogicException(sprintf('No result variable found for string expression "%s".', $stringExpr));
            }

            $leftExpr = $this->walkResultVariable($stringExpr);
        } else {
            $leftExpr = $stringExpr->dispatch($this);
        }

        $sql = $leftExpr . ($likeExpr->not ? ' NOT' : '') . ' LIKE ';

        if ($likeExpr->stringPattern instanceof AST\InputParameter) {
            $sql .= $this->walkInputParameter($likeExpr->stringPattern);
        } elseif ($likeExpr->stringPattern instanceof AST\Functions\FunctionNode) {
            $sql .= $this->walkFunction($likeExpr->stringPattern);
        } elseif ($likeExpr->stringPattern instanceof AST\PathExpression) {
            $sql .= $this->walkPathExpression($likeExpr->stringPattern);
        } else {
            $sql .= $this->walkLiteral($likeExpr->stringPattern);
        }

        if ($likeExpr->escapeChar) {
            $sql .= ' ESCAPE ' . $this->walkLiteral($likeExpr->escapeChar);
        }

        return $sql;
    }

    /**
     * Walks down a StateFieldPathExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkStateFieldPathExpression(AST\PathExpression $stateFieldPathExpression): string
    {
        return $this->walkPathExpression($stateFieldPathExpression);
    }

    /**
     * Walks down a ComparisonExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkComparisonExpression(AST\ComparisonExpression $compExpr): string
    {
        $leftExpr  = $compExpr->leftExpression;
        $rightExpr = $compExpr->rightExpression;
        $sql       = '';

        $sql .= $leftExpr instanceof AST\Node
            ? $leftExpr->dispatch($this)
            : (is_numeric($leftExpr) ? $leftExpr : $this->conn->quote($leftExpr));

        $sql .= ' ' . $compExpr->operator . ' ';

        $sql .= $rightExpr instanceof AST\Node
            ? $rightExpr->dispatch($this)
            : (is_numeric($rightExpr) ? $rightExpr : $this->conn->quote($rightExpr));

        return $sql;
    }

    /**
     * Walks down an InputParameter AST node, thereby generating the appropriate SQL.
     */
    public function walkInputParameter(AST\InputParameter $inputParam): string
    {
        $this->parserResult->addParameterMapping($inputParam->name, $this->sqlParamIndex++);

        $parameter = $this->query->getParameter($inputParam->name);

        if ($parameter) {
            $type = $parameter->getType();
            if (is_string($type) && $this->typeProvider->has($type)) {
                return $this->typeProvider->get($type)->convertToDatabaseValueSQL('?', $this->platform);
            }
        }

        return '?';
    }

    /**
     * Walks down an ArithmeticExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkArithmeticExpression(AST\ArithmeticExpression $arithmeticExpr): string
    {
        return $arithmeticExpr->isSimpleArithmeticExpression()
            ? $this->walkSimpleArithmeticExpression($arithmeticExpr->simpleArithmeticExpression)
            : '(' . $this->walkSubselect($arithmeticExpr->subselect) . ')';
    }

    /**
     * Walks down an SimpleArithmeticExpression AST node, thereby generating the appropriate SQL.
     */
    public function walkSimpleArithmeticExpression(AST\Node|string $simpleArithmeticExpr): string
    {
        if (! ($simpleArithmeticExpr instanceof AST\SimpleArithmeticExpression)) {
            return $this->walkArithmeticTerm($simpleArithmeticExpr);
        }

        return implode(' ', array_map($this->walkArithmeticTerm(...), $simpleArithmeticExpr->arithmeticTerms));
    }

    /**
     * Walks down an ArithmeticTerm AST node, thereby generating the appropriate SQL.
     */
    public function walkArithmeticTerm(AST\Node|string $term): string
    {
        if (is_string($term)) {
            return isset($this->queryComponents[$term])
                ? $this->walkResultVariable($this->queryComponents[$term]['token']->value)
                : $term;
        }

        // Phase 2 AST optimization: Skip processing of ArithmeticTerm
        // if only one ArithmeticFactor is defined
        if (! ($term instanceof AST\ArithmeticTerm)) {
            return $this->walkArithmeticFactor($term);
        }

        return implode(' ', array_map($this->walkArithmeticFactor(...), $term->arithmeticFactors));
    }

    /**
     * Walks down an ArithmeticFactor that represents an AST node, thereby generating the appropriate SQL.
     */
    public function walkArithmeticFactor(AST\Node|string $factor): string
    {
        if (is_string($factor)) {
            return isset($this->queryComponents[$factor])
                ? $this->walkResultVariable($this->queryComponents[$factor]['token']->value)
                : $factor;
        }

        // Phase 2 AST optimization: Skip processing of ArithmeticFactor
        // if only one ArithmeticPrimary is defined
        if (! ($factor instanceof AST\ArithmeticFactor)) {
            return $this->walkArithmeticPrimary($factor);
        }

        $sign = $factor->isNegativeSigned() ? '-' : ($factor->isPositiveSigned() ? '+' : '');

        return $sign . $this->walkArithmeticPrimary($factor->arithmeticPrimary);
    }

    /**
     * Walks down an ArithmeticPrimary that represents an AST node, thereby generating the appropriate SQL.
     */
    public function walkArithmeticPrimary(AST\Node|string $primary): string
    {
        if ($primary instanceof AST\SimpleArithmeticExpression) {
            return '(' . $this->walkSimpleArithmeticExpression($primary) . ')';
        }

        if ($primary instanceof AST\Node) {
            return $primary->dispatch($this);
        }

        return $this->walkEntityIdentificationVariable($primary);
    }

    /**
     * Walks down a StringPrimary that represents an AST node, thereby generating the appropriate SQL.
     */
    public function walkStringPrimary(AST\Node|string $stringPrimary): string
    {
        return is_string($stringPrimary)
            ? $this->conn->quote($stringPrimary)
            : $stringPrimary->dispatch($this);
    }

    /**
     * Walks down a ResultVariable that represents an AST node, thereby generating the appropriate SQL.
     */
    public function walkResultVariable(string $resultVariable): string
    {
        if (! isset($this->scalarResultAliasMap[$resultVariable])) {
            throw new InvalidArgumentException(sprintf('Unknown result variable: %s', $resultVariable));
        }

        $resultAlias = $this->scalarResultAliasMap[$resultVariable];

        if (is_array($resultAlias)) {
            return implode(', ', $resultAlias);
        }

        return $resultAlias;
    }

    /**
     * @return string The list in parentheses of valid child discriminators from the given class
     *
     * @throws QueryException
     */
    private function getChildDiscriminatorsFromClassMetadata(
        ClassMetadata $rootClass,
        AST\InstanceOfExpression $instanceOfExpr,
    ): string {
        $sqlParameterList = [];
        $discriminators   = [];
        foreach ($instanceOfExpr->value as $parameter) {
            if ($parameter instanceof AST\InputParameter) {
                $this->rsm->discriminatorParameters[$parameter->name] = $parameter->name;
                $sqlParameterList[]                                   = $this->walkInParameter($parameter);
                continue;
            }

            $metadata = $this->em->getClassMetadata($parameter);

            if ($metadata->getName() !== $rootClass->name && ! $metadata->getReflectionClass()->isSubclassOf($rootClass->name)) {
                throw QueryException::instanceOfUnrelatedClass($parameter, $rootClass->name);
            }

            $discriminators += HierarchyDiscriminatorResolver::resolveDiscriminatorsForClass($metadata, $this->em);
        }

        foreach (array_keys($discriminators) as $discriminatorValue) {
            $sqlParameterList[] = $rootClass->getDiscriminatorColumn()->type === 'integer' && is_int($discriminatorValue)
                ? $discriminatorValue
                : $this->conn->quote((string) $discriminatorValue);
        }

        return '(' . implode(', ', $sqlParameterList) . ')';
    }
}
