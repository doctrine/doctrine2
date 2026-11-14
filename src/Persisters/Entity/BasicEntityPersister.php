<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use BackedEnum;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Cache\Persister\CompatOrderings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyAssociationMapping;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Exception\CantUseInOperatorOnCompositeKeys;
use Doctrine\ORM\Persisters\Exception\InvalidOrientation;
use Doctrine\ORM\Persisters\Exception\UnrecognizedField;
use Doctrine\ORM\Persisters\SqlExpressionVisitor;
use Doctrine\ORM\Persisters\SqlValueVisitor;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Repository\Exception\InvalidFindByCall;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\ORM\Utility\LockSqlHelper;
use Doctrine\ORM\Utility\PersisterHelper;
use LengthException;
use SortDirection;

use function array_combine;
use function array_diff_key;
use function array_fill;
use function array_flip;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function implode;
use function is_array;
use function reset;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function strtoupper;
use function trim;

/**
 * A BasicEntityPersister maps an entity to a single table in a relational database.
 *
 * A persister is always responsible for a single entity type.
 *
 * EntityPersisters are used during a UnitOfWork to apply any changes to the persistent
 * state of entities onto a relational database when the UnitOfWork is committed,
 * as well as for basic querying of entities and their associations (not DQL).
 *
 * The persisting operations that are invoked during a commit of a UnitOfWork to
 * persist the persistent entity state are:
 *
 *   - {@link addInsert} : To schedule an entity for insertion.
 *   - {@link executeInserts} : To execute all scheduled insertions.
 *   - {@link update} : To update the persistent state of an entity.
 *   - {@link delete} : To delete the persistent state of an entity.
 *
 * As can be seen from the above list, insertions are batched and executed all at once
 * for increased efficiency.
 *
 * The querying operations invoked during a UnitOfWork, either through direct find
 * requests or lazy-loading, are the following:
 *
 *   - {@link load} : Loads (the state of) a single, managed entity.
 *   - {@link loadAll} : Loads multiple, managed entities.
 *   - {@link loadOneToOneEntity} : Loads a one/many-to-one entity association (lazy-loading).
 *   - {@link loadOneToManyCollection} : Loads a one-to-many entity association (lazy-loading).
 *   - {@link loadManyToManyCollection} : Loads a many-to-many entity association (lazy-loading).
 *
 * The BasicEntityPersister implementation provides the default behavior for
 * persisting and querying entities that are mapped to a single database table.
 *
 * Subclasses can be created to provide custom persisting and querying strategies,
 * i.e. spanning multiple tables.
 */
class BasicEntityPersister implements EntityPersister
{
    use CompatOrderings;
    use LockSqlHelper;

    /** @var array<string,string> */
    private static array $comparisonMap = [
        Comparison::EQ          => '= %s',
        Comparison::NEQ         => '!= %s',
        Comparison::GT          => '> %s',
        Comparison::GTE         => '>= %s',
        Comparison::LT          => '< %s',
        Comparison::LTE         => '<= %s',
        Comparison::IN          => 'IN (%s)',
        Comparison::NIN         => 'NOT IN (%s)',
        Comparison::CONTAINS    => 'LIKE %s',
        Comparison::STARTS_WITH => 'LIKE %s',
        Comparison::ENDS_WITH   => 'LIKE %s',
    ];

    /**
     * The underlying DBAL Connection of the used EntityManager.
     */
    protected Connection $conn;

    /**
     * The database platform.
     */
    protected AbstractPlatform $platform;

    /**
     * Queued inserts.
     *
     * @phpstan-var array<int, object>
     */
    protected array $queuedInserts = [];

    /**
     * The map of column names to DBAL mapping types of all prepared columns used
     * when INSERTing or UPDATEing an entity.
     *
     * @see prepareInsertData($entity)
     * @see prepareUpdateData($entity)
     *
     * @var mixed[]
     */
    protected array $columnTypes = [];

    /**
     * The map of quoted column names.
     *
     * @see prepareInsertData($entity)
     * @see prepareUpdateData($entity)
     *
     * @var mixed[]
     */
    protected array $quotedColumns = [];

    /**
     * The quote strategy.
     */
    protected QuoteStrategy $quoteStrategy;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     */
    protected readonly IdentifierFlattener $identifierFlattener;

    protected CachedPersisterContext $currentPersisterContext;
    private readonly CachedPersisterContext $limitsHandlingContext;
    private readonly CachedPersisterContext $noLimitsContext;

    private string|null $filterHash = null;

    /**
     * Initializes a new <tt>BasicEntityPersister</tt> that uses the given EntityManager
     * and persists instances of the class described by the given ClassMetadata descriptor.
     *
     * @param ClassMetadata $class Metadata object that describes the mapping of the mapped entity class.
     */
    public function __construct(
        protected EntityManagerInterface $em,
        protected ClassMetadata $class,
    ) {
        $this->conn                  = $em->getConnection();
        $this->platform              = $this->conn->getDatabasePlatform();
        $this->quoteStrategy         = $em->getConfiguration()->getQuoteStrategy();
        $this->identifierFlattener   = new IdentifierFlattener($em->getUnitOfWork(), $em->getMetadataFactory());
        $this->noLimitsContext       = $this->currentPersisterContext = new CachedPersisterContext(
            $class,
            new Query\ResultSetMapping(),
            false,
        );
        $this->limitsHandlingContext = new CachedPersisterContext(
            $class,
            new Query\ResultSetMapping(),
            true,
        );
    }

    final protected function isFilterHashUpToDate(): bool
    {
        return $this->filterHash === $this->em->getFilters()->getHash();
    }

    final protected function updateFilterHash(): void
    {
        $this->filterHash = $this->em->getFilters()->getHash();
    }

    public function getClassMetadata(): ClassMetadata
    {
        return $this->class;
    }

    public function getResultSetMapping(): ResultSetMapping
    {
        return $this->currentPersisterContext->rsm;
    }

    public function addInsert(object $entity): void
    {
        $this->queuedInserts[spl_object_id($entity)] = $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function getInserts(): array
    {
        return $this->queuedInserts;
    }

    public function executeInserts(): void
    {
        if (! $this->queuedInserts) {
            return;
        }

        $uow            = $this->em->getUnitOfWork();
        $idGenerator    = $this->class->idGenerator;
        $isPostInsertId = $idGenerator->isPostInsertGenerator();

        $stmt      = $this->conn->prepare($this->getInsertSQL());
        $tableName = $this->class->getTableName();

        foreach ($this->queuedInserts as $key => $entity) {
            $insertData = $this->prepareInsertData($entity);

            if (isset($insertData[$tableName])) {
                $paramIndex = 1;

                foreach ($insertData[$tableName] as $column => $value) {
                    $stmt->bindValue($paramIndex++, $value, $this->columnTypes[$column]);
                }
            }

            $stmt->executeStatement();

            if ($isPostInsertId) {
                $generatedId = $idGenerator->generateId($this->em, $entity);
                $id          = [$this->class->identifier[0] => $generatedId];

                $uow->assignPostInsertId($entity, $generatedId);
            } else {
                $id = $this->class->getIdentifierValues($entity);
            }

            if ($this->class->requiresFetchAfterChange) {
                $this->assignDefaultVersionAndUpsertableValues($entity, $id);
            }

            // Unset this queued insert, so that the prepareUpdateData() method (called via prepareInsertData() method)
            // knows right away (for the next entity already) that the current entity has been written to the database
            // and no extra updates need to be scheduled to refer to it.
            //
            // In \Doctrine\ORM\UnitOfWork::executeInserts(), the UoW already removed entities
            // from its own list (\Doctrine\ORM\UnitOfWork::$entityInsertions) right after they
            // were given to our addInsert() method.
            unset($this->queuedInserts[$key]);
        }
    }

    /**
     * Retrieves the default version value which was created
     * by the preceding INSERT statement and assigns it back in to the
     * entities version field if the given entity is versioned.
     * Also retrieves values of columns marked as 'non insertable' and / or
     * 'not updatable' and assigns them back to the entities corresponding fields.
     *
     * @param mixed[] $id
     */
    protected function assignDefaultVersionAndUpsertableValues(object $entity, array $id): void
    {
        $values = $this->fetchVersionAndNotUpsertableValues($this->class, $id);
        $uow    = $this->em->getUnitOfWork();
        $oid    = spl_object_id($entity);

        foreach ($values as $field => $value) {
            $value = Type::getType($this->class->fieldMappings[$field]->type)->convertToPHPValue($value, $this->platform);

            $this->class->setFieldValue($entity, $field, $value);
            $uow->setOriginalEntityProperty($oid, $field, $value);
        }
    }

    /**
     * Fetches the current version value of a versioned entity and / or the values of fields
     * marked as 'not insertable' and / or 'not updatable'.
     *
     * @param mixed[] $id
     */
    protected function fetchVersionAndNotUpsertableValues(ClassMetadata $versionedClass, array $id): mixed
    {
        $columnNames = [];
        foreach ($this->class->fieldMappings as $key => $column) {
            if (isset($column->generated) || ($this->class->isVersioned && $key === $versionedClass->versionField)) {
                $columnNames[$key] = $this->quoteStrategy->getColumnName($key, $versionedClass, $this->platform);
            }
        }

        $tableName  = $this->quoteStrategy->getTableName($versionedClass, $this->platform);
        $identifier = $this->quoteStrategy->getIdentifierColumnNames($versionedClass, $this->platform);

        // FIXME: Order with composite keys might not be correct
        $sql = 'SELECT ' . implode(', ', $columnNames)
            . ' FROM ' . $tableName
            . ' WHERE ' . implode(' = ? AND ', $identifier) . ' = ?';

        $flatId = $this->identifierFlattener->flattenIdentifier($versionedClass, $id);

        $values = $this->conn->fetchNumeric(
            $sql,
            array_values($flatId),
            $this->extractIdentifierTypes($id, $versionedClass),
        );

        if ($values === false) {
            throw new LengthException('Unexpected empty result for database query.');
        }

        $values = array_combine(array_keys($columnNames), $values);

        if (! $values) {
            throw new LengthException('Unexpected number of database columns.');
        }

        return $values;
    }

    /**
     * @param mixed[] $id
     *
     * @return list<ParameterType|int|string>
     * @phpstan-return list<ParameterType::*|ArrayParameterType::*|string>
     */
    final protected function extractIdentifierTypes(array $id, ClassMetadata $versionedClass): array
    {
        $types = [];

        foreach ($id as $field => $value) {
            $types = [...$types, ...PersisterHelper::inferParameterTypes($field, $value, $versionedClass, $this->em)];
        }

        return $types;
    }

    public function update(object $entity): void
    {
        $tableName  = $this->class->getTableName();
        $updateData = $this->prepareUpdateData($entity);

        if (! isset($updateData[$tableName])) {
            return;
        }

        $data = $updateData[$tableName];

        if (! $data) {
            return;
        }

        $isVersioned     = $this->class->isVersioned;
        $quotedTableName = $this->quoteStrategy->getTableName($this->class, $this->platform);

        $this->updateTable($entity, $quotedTableName, $data, $isVersioned);

        if ($this->class->requiresFetchAfterChange) {
            $id = $this->class->getIdentifierValues($entity);

            $this->assignDefaultVersionAndUpsertableValues($entity, $id);
        }
    }

    /**
     * Performs an UPDATE statement for an entity on a specific table.
     * The UPDATE can optionally be versioned, which requires the entity to have a version field.
     *
     * @param object  $entity          The entity object being updated.
     * @param string  $quotedTableName The quoted name of the table to apply the UPDATE on.
     * @param mixed[] $updateData      The map of columns to update (column => value).
     * @param bool    $versioned       Whether the UPDATE should be versioned.
     *
     * @throws UnrecognizedField
     * @throws OptimisticLockException
     */
    final protected function updateTable(
        object $entity,
        string $quotedTableName,
        array $updateData,
        bool $versioned = false,
    ): void {
        $set    = [];
        $types  = [];
        $params = [];

        foreach ($updateData as $columnName => $value) {
            $placeholder = '?';
            $column      = $columnName;

            switch (true) {
                case isset($this->class->fieldNames[$columnName]):
                    $fieldName = $this->class->fieldNames[$columnName];
                    $column    = $this->quoteStrategy->getColumnName($fieldName, $this->class, $this->platform);

                    if (isset($this->class->fieldMappings[$fieldName])) {
                        $type        = Type::getType($this->columnTypes[$columnName]);
                        $placeholder = $type->convertToDatabaseValueSQL('?', $this->platform);
                    }

                    break;

                case isset($this->quotedColumns[$columnName]):
                    $column = $this->quotedColumns[$columnName];

                    break;
            }

            $params[] = $value;
            $set[]    = $column . ' = ' . $placeholder;
            $types[]  = $this->columnTypes[$columnName];
        }

        $where      = [];
        $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);

        foreach ($this->class->identifier as $idField) {
            if (! isset($this->class->associationMappings[$idField])) {
                $params[] = $identifier[$idField];
                $types[]  = $this->class->fieldMappings[$idField]->type;
                $where[]  = $this->quoteStrategy->getColumnName($idField, $this->class, $this->platform);

                continue;
            }

            assert($this->class->associationMappings[$idField]->isToOneOwningSide());

            $params[] = $identifier[$idField];
            $where[]  = $this->quoteStrategy->getJoinColumnName(
                $this->class->associationMappings[$idField]->joinColumns[0],
                $this->class,
                $this->platform,
            );

            $targetMapping = $this->em->getClassMetadata($this->class->associationMappings[$idField]->targetEntity);
            $targetType    = PersisterHelper::getTypeOfField($targetMapping->identifier[0], $targetMapping, $this->em);

            if ($targetType === []) {
                throw UnrecognizedField::byFullyQualifiedName($this->class->name, $targetMapping->identifier[0]);
            }

            $types[] = reset($targetType);
        }

        if ($versioned) {
            $versionField = $this->class->versionField;
            assert($versionField !== null);
            $versionFieldType = $this->class->fieldMappings[$versionField]->type;
            $versionColumn    = $this->quoteStrategy->getColumnName($versionField, $this->class, $this->platform);

            $where[]  = $versionColumn;
            $types[]  = $this->class->fieldMappings[$versionField]->type;
            $params[] = $this->class->propertyAccessors[$versionField]->getValue($entity);

            switch ($versionFieldType) {
                case Types::SMALLINT:
                case Types::INTEGER:
                case Types::BIGINT:
                    $set[] = $versionColumn . ' = ' . $versionColumn . ' + 1';
                    break;

                case Types::DATETIME_MUTABLE:
                    $set[] = $versionColumn . ' = CURRENT_TIMESTAMP';
                    break;
            }
        }

        $sql = 'UPDATE ' . $quotedTableName
             . ' SET ' . implode(', ', $set)
             . ' WHERE ' . implode(' = ? AND ', $where) . ' = ?';

        $result = $this->conn->executeStatement($sql, $params, $types);

        if ($versioned && ! $result) {
            throw OptimisticLockException::lockFailed($entity);
        }
    }

    /**
     * @param array<mixed> $identifier
     * @param string[]     $types
     *
     * @todo Add check for platform if it supports foreign keys/cascading.
     */
    protected function deleteJoinTableRecords(array $identifier, array $types): void
    {
        foreach ($this->class->associationMappings as $mapping) {
            if (! $mapping->isManyToMany() || $mapping->isOnDeleteCascade) {
                continue;
            }

            // @Todo this only covers scenarios with no inheritance or of the same level. Is there something
            // like self-referential relationship between different levels of an inheritance hierarchy? I hope not!
            $selfReferential = ($mapping->targetEntity === $mapping->sourceEntity);
            $class           = $this->class;
            $association     = $mapping;
            $otherColumns    = [];
            $otherKeys       = [];
            $keys            = [];

            if (! $mapping->isOwningSide()) {
                $class = $this->em->getClassMetadata($mapping->targetEntity);
            }

            $association = $this->em->getMetadataFactory()->getOwningSide($association);
            $joinColumns = $mapping->isOwningSide()
                ? $association->joinTable->joinColumns
                : $association->joinTable->inverseJoinColumns;

            if ($selfReferential) {
                $otherColumns = ! $mapping->isOwningSide()
                    ? $association->joinTable->joinColumns
                    : $association->joinTable->inverseJoinColumns;
            }

            foreach ($joinColumns as $joinColumn) {
                $keys[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            }

            foreach ($otherColumns as $joinColumn) {
                $otherKeys[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            }

            $joinTableName = $this->quoteStrategy->getJoinTableName($association, $this->class, $this->platform);

            $this->conn->delete($joinTableName, array_combine($keys, $identifier), $types);

            if ($selfReferential) {
                $this->conn->delete($joinTableName, array_combine($otherKeys, $identifier), $types);
            }
        }
    }

    public function delete(object $entity): bool
    {
        $class      = $this->class;
        $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
        $tableName  = $this->quoteStrategy->getTableName($class, $this->platform);
        $idColumns  = $this->quoteStrategy->getIdentifierColumnNames($class, $this->platform);
        $id         = array_combine($idColumns, $identifier);
        $types      = $this->getClassIdentifiersTypes($class);

        $this->deleteJoinTableRecords($identifier, $types);

        return (bool) $this->conn->delete($tableName, $id, $types);
    }

    /**
     * Prepares the changeset of an entity for database insertion (UPDATE).
     *
     * The changeset is obtained from the currently running UnitOfWork.
     *
     * During this preparation the array that is passed as the second parameter is filled with
     * <columnName> => <value> pairs, grouped by table name.
     *
     * Example:
     * <code>
     * array(
     *    'foo_table' => array('column1' => 'value1', 'column2' => 'value2', ...),
     *    'bar_table' => array('columnX' => 'valueX', 'columnY' => 'valueY', ...),
     *    ...
     * )
     * </code>
     *
     * @param object $entity   The entity for which to prepare the data.
     * @param bool   $isInsert Whether the data to be prepared refers to an insert statement.
     *
     * @return mixed[][] The prepared data.
     * @phpstan-return array<string, array<array-key, mixed|null>>
     */
    protected function prepareUpdateData(object $entity, bool $isInsert = false): array
    {
        $versionField = null;
        $result       = [];
        $uow          = $this->em->getUnitOfWork();

        $versioned = $this->class->isVersioned;
        if ($versioned !== false) {
            $versionField = $this->class->versionField;
        }

        foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
            if (isset($versionField) && $versionField === $field) {
                continue;
            }

            if (isset($this->class->embeddedClasses[$field])) {
                continue;
            }

            $newVal = $change[1];

            if (! isset($this->class->associationMappings[$field])) {
                $fieldMapping = $this->class->fieldMappings[$field];
                $columnName   = $fieldMapping->columnName;

                if (! $isInsert && isset($fieldMapping->notUpdatable)) {
                    continue;
                }

                if ($isInsert && isset($fieldMapping->notInsertable)) {
                    continue;
                }

                $this->columnTypes[$columnName] = $fieldMapping->type;

                $result[$this->getOwningTable($field)][$columnName] = $newVal;

                continue;
            }

            $assoc = $this->class->associationMappings[$field];

            // Only owning side of x-1 associations can have a FK column.
            if (! $assoc->isToOneOwningSide()) {
                continue;
            }

            if ($newVal !== null) {
                $oid = spl_object_id($newVal);

                // If the associated entity $newVal is not yet persisted and/or does not yet have
                // an ID assigned, we must set $newVal = null. This will insert a null value and
                // schedule an extra update on the UnitOfWork.
                //
                // This gives us extra time to a) possibly obtain a database-generated identifier
                // value for $newVal, and b) insert $newVal into the database before the foreign
                // key reference is being made.
                //
                // When looking at $this->queuedInserts and $uow->isScheduledForInsert, be aware
                // of the implementation details that our own executeInserts() method will remove
                // entities from the former as soon as the insert statement has been executed and
                // a post-insert ID has been assigned (if necessary), and that the UnitOfWork has
                // already removed entities from its own list at the time they were passed to our
                // addInsert() method.
                //
                // Then, there is one extra exception we can make: An entity that references back to itself
                // _and_ uses an application-provided ID (the "NONE" generator strategy) also does not
                // need the extra update, although it is still in the list of insertions itself.
                // This looks like a minor optimization at first, but is the capstone for being able to
                // use non-NULLable, self-referencing associations in applications that provide IDs (like UUIDs).
                if (
                    (isset($this->queuedInserts[$oid]) || $uow->isScheduledForInsert($newVal))
                    && ! ($newVal === $entity && $this->class->isIdentifierNatural())
                ) {
                    $uow->scheduleExtraUpdate($entity, [$field => [null, $newVal]]);

                    $newVal = null;
                }
            }

            $newValId = null;

            if ($newVal !== null) {
                $newValId = $uow->getEntityIdentifier($newVal);
            }

            $targetClass = $this->em->getClassMetadata($assoc->targetEntity);
            $owningTable = $this->getOwningTable($field);

            foreach ($assoc->joinColumns as $joinColumn) {
                $sourceColumn = $joinColumn->name;
                $targetColumn = $joinColumn->referencedColumnName;
                $quotedColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);

                $this->quotedColumns[$sourceColumn] = $quotedColumn;
                $this->columnTypes[$sourceColumn]   = PersisterHelper::getTypeOfColumn($targetColumn, $targetClass, $this->em);

                $newValue                            = $newValId ? $newValId[$targetClass->getFieldForColumn($targetColumn)] : null;
                $result[$owningTable][$sourceColumn] = $newValue instanceof BackedEnum ? $newValue->value : $newValue;
            }
        }

        return $result;
    }

    /**
     * Prepares the data changeset of a managed entity for database insertion (initial INSERT).
     * The changeset of the entity is obtained from the currently running UnitOfWork.
     *
     * The default insert data preparation is the same as for updates.
     *
     * @see prepareUpdateData
     *
     * @param object $entity The entity for which to prepare the data.
     *
     * @return mixed[][] The prepared data for the tables to update.
     * @phpstan-return array<string, mixed[]>
     */
    protected function prepareInsertData(object $entity): array
    {
        return $this->prepareUpdateData($entity, true);
    }

    public function getOwningTable(string $fieldName): string
    {
        return $this->class->getTableName();
    }

    /**
     * {@inheritDoc}
     */
    public function load(
        array $criteria,
        object|null $entity = null,
        AssociationMapping|null $assoc = null,
        array $hints = [],
        LockMode|int|null $lockMode = null,
        int|null $limit = null,
        array|null $orderBy = null,
    ): object|null {
        $this->switchPersisterContext(null, $limit);

        $sql              = $this->getSelectSQL($criteria, $assoc, $lockMode, $limit, null, $orderBy);
        [$params, $types] = $this->expandParameters($criteria);
        $stmt             = $this->conn->executeQuery($sql, $params, $types);

        if ($entity !== null) {
            $hints[Query::HINT_REFRESH]        = true;
            $hints[Query::HINT_REFRESH_ENTITY] = $entity;
        }

        $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);
        $entities = $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, $hints);

        return $entities ? $entities[0] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function loadById(array $identifier, object|null $entity = null): object|null
    {
        return $this->load($identifier, $entity);
    }

    /**
     * {@inheritDoc}
     */
    public function loadOneToOneEntity(AssociationMapping $assoc, object $sourceEntity, array $identifier = []): object|null
    {
        $foundEntity = $this->em->getUnitOfWork()->tryGetById($identifier, $assoc->targetEntity);
        if ($foundEntity !== false) {
            return $foundEntity;
        }

        $targetClass = $this->em->getClassMetadata($assoc->targetEntity);

        if ($assoc->isOwningSide()) {
            $isInverseSingleValued = $assoc->inversedBy !== null && ! $targetClass->isCollectionValuedAssociation($assoc->inversedBy);

            // Mark inverse side as fetched in the hints, otherwise the UoW would
            // try to load it in a separate query (remember: to-one inverse sides can not be lazy).
            $hints = [];

            if ($isInverseSingleValued) {
                $hints['fetched']['r'][$assoc->inversedBy] = true;
            }

            $targetEntity = $this->load($identifier, null, $assoc, $hints);

            // Complete bidirectional association, if necessary
            if ($targetEntity !== null && $isInverseSingleValued) {
                $targetClass->propertyAccessors[$assoc->inversedBy]->setValue($targetEntity, $sourceEntity);
            }

            return $targetEntity;
        }

        assert(isset($assoc->mappedBy));
        $sourceClass = $this->em->getClassMetadata($assoc->sourceEntity);
        $owningAssoc = $targetClass->getAssociationMapping($assoc->mappedBy);
        assert($owningAssoc->isOneToOneOwningSide());

        $computedIdentifier = [];

        /** @var array<string,mixed>|null $sourceEntityData */
        $sourceEntityData = null;

        // TRICKY: since the association is specular source and target are flipped
        foreach ($owningAssoc->targetToSourceKeyColumns as $sourceKeyColumn => $targetKeyColumn) {
            if (! isset($sourceClass->fieldNames[$sourceKeyColumn])) {
                // The likely case here is that the column is a join column
                // in an association mapping. However, there is no guarantee
                // at this point that a corresponding (generally identifying)
                // association has been mapped in the source entity. To handle
                // this case we directly reference the column-keyed data used
                // to initialize the source entity before throwing an exception.
                $resolvedSourceData = false;
                if (! isset($sourceEntityData)) {
                    $sourceEntityData = $this->em->getUnitOfWork()->getOriginalEntityData($sourceEntity);
                }

                if (isset($sourceEntityData[$sourceKeyColumn])) {
                    $dataValue = $sourceEntityData[$sourceKeyColumn];
                    if ($dataValue !== null) {
                        $resolvedSourceData                                                    = true;
                        $computedIdentifier[$targetClass->getFieldForColumn($targetKeyColumn)] =
                            $dataValue;
                    }
                }

                if (! $resolvedSourceData) {
                    throw MappingException::joinColumnMustPointToMappedField(
                        $sourceClass->name,
                        $sourceKeyColumn,
                    );
                }
            } else {
                $computedIdentifier[$targetClass->getFieldForColumn($targetKeyColumn)] =
                    $sourceClass->propertyAccessors[$sourceClass->fieldNames[$sourceKeyColumn]]->getValue($sourceEntity);
            }
        }

        $targetEntity = $this->load($computedIdentifier, null, $assoc);

        if ($targetEntity !== null) {
            $targetClass->setFieldValue($targetEntity, $assoc->mappedBy, $sourceEntity);
        }

        return $targetEntity;
    }

    /**
     * {@inheritDoc}
     */
    public function refresh(array $id, object $entity, LockMode|int|null $lockMode = null): void
    {
        $sql              = $this->getSelectSQL($id, null, $lockMode);
        [$params, $types] = $this->expandParameters($id);
        $stmt             = $this->conn->executeQuery($sql, $params, $types);

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [Query::HINT_REFRESH => true]);
    }

    public function count(array|Criteria $criteria = []): int
    {
        $sql = $this->getCountSQL($criteria);

        [$params, $types] = $criteria instanceof Criteria
            ? $this->expandCriteriaParameters($criteria)
            : $this->expandParameters($criteria);

        $count = (int) $this->conn->executeQuery($sql, $params, $types)->fetchOne();
        assert($count >= 0);

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function loadCriteria(Criteria $criteria): array
    {
        $orderBy = $this->getOrderingsAsStringMap($criteria);

        $limit  = $criteria->getMaxResults();
        $offset = $criteria->getFirstResult();
        $query  = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);

        [$params, $types] = $this->expandCriteriaParameters($criteria);

        $stmt     = $this->conn->executeQuery($query, $params, $types);
        $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);

        return $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [UnitOfWork::HINT_DEFEREAGERLOAD => true]);
    }

    /**
     * {@inheritDoc}
     */
    public function expandCriteriaParameters(Criteria $criteria): array
    {
        $expression = $criteria->getWhereExpression();
        $sqlParams  = [];
        $sqlTypes   = [];

        if ($expression === null) {
            return [$sqlParams, $sqlTypes];
        }

        $valueVisitor = new SqlValueVisitor();

        $valueVisitor->dispatch($expression);

        [, $types] = $valueVisitor->getParamsAndTypes();

        foreach ($types as $type) {
            [$field, $value, $operator] = $type;

            if ($value === null && ($operator === Comparison::EQ || $operator === Comparison::NEQ)) {
                continue;
            }

            if ($operator === Comparison::IN || $operator === Comparison::NIN) {
                if (! is_array($value)) {
                    $value = [$value];
                }

                foreach ($value as $item) {
                    if ($item === null) {
                        /*
                         * Compare this to how \Doctrine\ORM\Persisters\Entity\BasicEntityPersister::getSelectConditionStatementSQL
                         * creates the "[NOT] IN (...)" expression - for NULL values, it does _not_ insert a placeholder in the
                         * SQL and instead adds an extra ... OR ... IS NULL condition. So we need to skip NULL values here as
                         * well to create a parameters list that matches the SQL.
                         */
                        continue;
                    }

                    $sqlParams = [...$sqlParams, ...PersisterHelper::convertToParameterValue($item, $this->em)];
                    $sqlTypes  = [...$sqlTypes, ...PersisterHelper::inferParameterTypes($field, $item, $this->class, $this->em)];
                }

                continue;
            }

            $sqlParams = [...$sqlParams, ...PersisterHelper::convertToParameterValue($value, $this->em)];
            $sqlTypes  = [...$sqlTypes, ...PersisterHelper::inferParameterTypes($field, $value, $this->class, $this->em)];
        }

        return [$sqlParams, $sqlTypes];
    }

    /**
     * {@inheritDoc}
     */
    public function loadAll(
        array $criteria = [],
        array|null $orderBy = null,
        int|null $limit = null,
        int|null $offset = null,
    ): array {
        $this->switchPersisterContext($offset, $limit);

        $sql              = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);
        [$params, $types] = $this->expandParameters($criteria);
        $stmt             = $this->conn->executeQuery($sql, $params, $types);

        $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);

        return $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [UnitOfWork::HINT_DEFEREAGERLOAD => true]);
    }

    /**
     * {@inheritDoc}
     */
    public function getManyToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array {
        assert($assoc->isManyToMany());
        $this->switchPersisterContext($offset, $limit);

        $stmt = $this->getManyToManyStatement($assoc, $sourceEntity, $offset, $limit);

        return $this->loadArrayFromResult($assoc, $stmt);
    }

    /**
     * Loads an array of entities from a given DBAL statement.
     *
     * @return mixed[]
     */
    private function loadArrayFromResult(AssociationMapping $assoc, Result $stmt): array
    {
        $rsm   = $this->currentPersisterContext->rsm;
        $hints = [UnitOfWork::HINT_DEFEREAGERLOAD => true];

        if ($assoc->isIndexed()) {
            $rsm = clone $this->currentPersisterContext->rsm; // this is necessary because the "default rsm" should be changed.
            $rsm->addIndexBy('r', $assoc->indexBy());
        }

        return $this->em->newHydrator(Query::HYDRATE_OBJECT)->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * Hydrates a collection from a given DBAL statement.
     *
     * @return mixed[]
     */
    private function loadCollectionFromStatement(
        AssociationMapping $assoc,
        Result $stmt,
        PersistentCollection $coll,
    ): array {
        $rsm   = $this->currentPersisterContext->rsm;
        $hints = [
            UnitOfWork::HINT_DEFEREAGERLOAD => true,
            'collection' => $coll,
        ];

        if ($assoc->isIndexed()) {
            $rsm = clone $this->currentPersisterContext->rsm; // this is necessary because the "default rsm" should be changed.
            $rsm->addIndexBy('r', $assoc->indexBy());
        }

        return $this->em->newHydrator(Query::HYDRATE_OBJECT)->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * {@inheritDoc}
     */
    public function loadManyToManyCollection(AssociationMapping $assoc, object $sourceEntity, PersistentCollection $collection): array
    {
        assert($assoc->isManyToMany());
        $stmt = $this->getManyToManyStatement($assoc, $sourceEntity);

        return $this->loadCollectionFromStatement($assoc, $stmt, $collection);
    }

    /** @throws MappingException */
    private function getManyToManyStatement(
        AssociationMapping&ManyToManyAssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): Result {
        $this->switchPersisterContext($offset, $limit);

        $sourceClass = $this->em->getClassMetadata($assoc->sourceEntity);
        $class       = $sourceClass;
        $association = $assoc;
        $criteria    = [];
        $parameters  = [];

        if (! $assoc->isOwningSide()) {
            $class = $this->em->getClassMetadata($assoc->targetEntity);
        }

        $association = $this->em->getMetadataFactory()->getOwningSide($assoc);
        $joinColumns = $assoc->isOwningSide()
            ? $association->joinTable->joinColumns
            : $association->joinTable->inverseJoinColumns;

        $quotedJoinTable = $this->quoteStrategy->getJoinTableName($association, $class, $this->platform);

        foreach ($joinColumns as $joinColumn) {
            $sourceKeyColumn = $joinColumn->referencedColumnName;
            $quotedKeyColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);

            switch (true) {
                case $sourceClass->containsForeignIdentifier:
                    $field = $sourceClass->getFieldForColumn($sourceKeyColumn);
                    $value = $sourceClass->propertyAccessors[$field]->getValue($sourceEntity);

                    if (isset($sourceClass->associationMappings[$field])) {
                        $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
                        $value = $value[$this->em->getClassMetadata($sourceClass->associationMappings[$field]->targetEntity)->identifier[0]];
                    }

                    break;

                case isset($sourceClass->fieldNames[$sourceKeyColumn]):
                    $field = $sourceClass->fieldNames[$sourceKeyColumn];
                    $value = $sourceClass->propertyAccessors[$field]->getValue($sourceEntity);

                    break;

                default:
                    throw MappingException::joinColumnMustPointToMappedField(
                        $sourceClass->name,
                        $sourceKeyColumn,
                    );
            }

            $criteria[$quotedJoinTable . '.' . $quotedKeyColumn] = $value;
            $parameters[]                                        = [
                'value' => $value,
                'field' => $field,
                'class' => $sourceClass,
            ];
        }

        $sql              = $this->getSelectSQL($criteria, $assoc, null, $limit, $offset);
        [$params, $types] = $this->expandToManyParameters($parameters);

        return $this->conn->executeQuery($sql, $params, $types);
    }

    public function getSelectSQL(
        array|Criteria $criteria,
        AssociationMapping|null $assoc = null,
        LockMode|int|null $lockMode = null,
        int|null $limit = null,
        int|null $offset = null,
        array|null $orderBy = null,
    ): string {
        $this->switchPersisterContext($offset, $limit);

        $joinSql    = '';
        $orderBySql = '';

        if ($assoc !== null && $assoc->isManyToMany()) {
            $joinSql = $this->getSelectManyToManyJoinSQL($assoc);
        }

        if ($assoc !== null && $assoc->isOrdered()) {
            $orderBy = $assoc->orderBy();
        }

        if ($orderBy) {
            $orderBySql = $this->getOrderBySQL($orderBy, $this->getSQLTableAlias($this->class->name));
        }

        $conditionSql = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria, $assoc);

        $lockSql = match ($lockMode) {
            LockMode::PESSIMISTIC_READ => ' ' . $this->getReadLockSQL($this->platform),
            LockMode::PESSIMISTIC_WRITE => ' ' . $this->getWriteLockSQL($this->platform),
            default => '',
        };

        $columnList = $this->getSelectColumnsSQL();
        $tableAlias = $this->getSQLTableAlias($this->class->name);
        $filterSql  = $this->generateFilterConditionSQL($this->class, $tableAlias);
        $tableName  = $this->quoteStrategy->getTableName($this->class, $this->platform);

        if ($filterSql !== '') {
            $conditionSql = $conditionSql
                ? $conditionSql . ' AND ' . $filterSql
                : $filterSql;
        }

        $select = 'SELECT ' . $columnList;
        $from   = ' FROM ' . $tableName . ' ' . $tableAlias;
        $join   = $this->currentPersisterContext->selectJoinSql . $joinSql;
        $where  = ($conditionSql ? ' WHERE ' . $conditionSql : '');
        $lock   = $this->platform->appendLockHint($from, $lockMode ?? LockMode::NONE);
        $query  = $select
            . $lock
            . $join
            . $where
            . $orderBySql;

        return $this->platform->modifyLimitQuery($query, $limit, $offset ?? 0) . $lockSql;
    }

    public function getCountSQL(array|Criteria $criteria = []): string
    {
        $tableName  = $this->quoteStrategy->getTableName($this->class, $this->platform);
        $tableAlias = $this->getSQLTableAlias($this->class->name);

        $conditionSql = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria);

        $filterSql = $this->generateFilterConditionSQL($this->class, $tableAlias);

        if ($filterSql !== '') {
            $conditionSql = $conditionSql
                ? $conditionSql . ' AND ' . $filterSql
                : $filterSql;
        }

        return 'SELECT COUNT(*) '
            . 'FROM ' . $tableName . ' ' . $tableAlias
            . (empty($conditionSql) ? '' : ' WHERE ' . $conditionSql);
    }

    /**
     * Gets the ORDER BY SQL snippet for ordered collections.
     *
     * @phpstan-param array<string, SortDirection|string> $orderBy
     *
     * @throws InvalidOrientation
     * @throws InvalidFindByCall
     * @throws UnrecognizedField
     */
    final protected function getOrderBySQL(array $orderBy, string $baseTableAlias): string
    {
        $orderByList = [];

        foreach ($orderBy as $fieldName => $orientation) {
            if ($orientation instanceof SortDirection) {
                $orientation = ($orientation === SortDirection::Ascending ? 'ASC' : 'DESC');
            }

            $orientation = strtoupper(trim($orientation));

            if ($orientation !== 'ASC' && $orientation !== 'DESC') {
                throw InvalidOrientation::fromClassNameAndField($this->class->name, $fieldName);
            }

            if (isset($this->class->fieldMappings[$fieldName])) {
                $tableAlias = isset($this->class->fieldMappings[$fieldName]->inherited)
                    ? $this->getSQLTableAlias($this->class->fieldMappings[$fieldName]->inherited)
                    : $baseTableAlias;

                $columnName    = $this->quoteStrategy->getColumnName($fieldName, $this->class, $this->platform);
                $orderByList[] = $tableAlias . '.' . $columnName . ' ' . $orientation;

                continue;
            }

            if (isset($this->class->associationMappings[$fieldName])) {
                $association = $this->class->associationMappings[$fieldName];
                if (! $association->isOwningSide()) {
                    throw InvalidFindByCall::fromInverseSideUsage($this->class->name, $fieldName);
                }

                assert($association->isToOneOwningSide());

                $tableAlias = isset($association->inherited)
                    ? $this->getSQLTableAlias($association->inherited)
                    : $baseTableAlias;

                foreach ($association->joinColumns as $joinColumn) {
                    $columnName    = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
                    $orderByList[] = $tableAlias . '.' . $columnName . ' ' . $orientation;
                }

                continue;
            }

            throw UnrecognizedField::byFullyQualifiedName($this->class->name, $fieldName);
        }

        return ' ORDER BY ' . implode(', ', $orderByList);
    }

    /**
     * Gets the SQL fragment with the list of columns to select when querying for
     * an entity in this persister.
     *
     * Subclasses should override this method to alter or change the select column
     * list SQL fragment. Note that in the implementation of BasicEntityPersister
     * the resulting SQL fragment is generated only once and cached in {@link selectColumnListSql}.
     * Subclasses may or may not do the same.
     */
    protected function getSelectColumnsSQL(): string
    {
        if ($this->currentPersisterContext->selectColumnListSql !== null && $this->isFilterHashUpToDate()) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $columnList = [];
        $this->currentPersisterContext->rsm->addEntityResult($this->class->name, 'r'); // r for root

        // Add regular columns to select list
        foreach ($this->class->fieldNames as $field) {
            $columnList[] = $this->getSelectColumnSQL($field, $this->class);
        }

        $this->currentPersisterContext->selectJoinSql = '';
        $eagerAliasCounter                            = 0;

        foreach ($this->class->associationMappings as $assocField => $assoc) {
            $assocColumnSQL = $this->getSelectColumnAssociationSQL($assocField, $assoc, $this->class);

            if ($assocColumnSQL) {
                $columnList[] = $assocColumnSQL;
            }

            $isAssocToOneInverseSide = $assoc->isToOne() && ! $assoc->isOwningSide();
            $isAssocFromOneEager     = $assoc->isToOne() && $assoc->fetch === ClassMetadata::FETCH_EAGER;

            if (! ($isAssocFromOneEager || $isAssocToOneInverseSide)) {
                continue;
            }

            if ($assoc->isToMany() && $this->currentPersisterContext->handlesLimits) {
                continue;
            }

            $eagerEntity = $this->em->getClassMetadata($assoc->targetEntity);

            if ($eagerEntity->inheritanceType !== ClassMetadata::INHERITANCE_TYPE_NONE) {
                continue; // now this is why you shouldn't use inheritance
            }

            $assocAlias = 'e' . ($eagerAliasCounter++);
            $this->currentPersisterContext->rsm->addJoinedEntityResult($assoc->targetEntity, $assocAlias, 'r', $assocField);

            foreach ($eagerEntity->fieldNames as $field) {
                $columnList[] = $this->getSelectColumnSQL($field, $eagerEntity, $assocAlias);
            }

            foreach ($eagerEntity->associationMappings as $eagerAssocField => $eagerAssoc) {
                $eagerAssocColumnSQL = $this->getSelectColumnAssociationSQL(
                    $eagerAssocField,
                    $eagerAssoc,
                    $eagerEntity,
                    $assocAlias,
                );

                if ($eagerAssocColumnSQL) {
                    $columnList[] = $eagerAssocColumnSQL;
                }
            }

            $association   = $assoc;
            $joinCondition = [];

            if ($assoc->isIndexed()) {
                assert($assoc->isToMany());
                $this->currentPersisterContext->rsm->addIndexBy($assocAlias, $assoc->indexBy());
            }

            if (! $assoc->isOwningSide()) {
                $eagerEntity = $this->em->getClassMetadata($assoc->targetEntity);
                $association = $eagerEntity->getAssociationMapping($assoc->mappedBy);
            }

            assert($association->isToOneOwningSide());

            $joinTableAlias = $this->getSQLTableAlias($eagerEntity->name, $assocAlias);
            $joinTableName  = $this->quoteStrategy->getTableName($eagerEntity, $this->platform);

            if ($assoc->isOwningSide()) {
                $tableAlias                                    = $this->getSQLTableAlias($association->targetEntity, $assocAlias);
                $this->currentPersisterContext->selectJoinSql .= ' ' . $this->getJoinSQLForJoinColumns($association->joinColumns);

                foreach ($association->joinColumns as $joinColumn) {
                    $sourceCol       = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
                    $targetCol       = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $this->class, $this->platform);
                    $joinCondition[] = $this->getSQLTableAlias($association->sourceEntity)
                                        . '.' . $sourceCol . ' = ' . $tableAlias . '.' . $targetCol;
                }

                // Add filter SQL
                $filterSql = $this->generateFilterConditionSQL($eagerEntity, $tableAlias);
                if ($filterSql) {
                    $joinCondition[] = $filterSql;
                }
            } else {
                $this->currentPersisterContext->selectJoinSql .= ' LEFT JOIN';

                foreach ($association->joinColumns as $joinColumn) {
                    $sourceCol = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
                    $targetCol = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $this->class, $this->platform);

                    $joinCondition[] = $this->getSQLTableAlias($association->sourceEntity, $assocAlias) . '.' . $sourceCol . ' = '
                        . $this->getSQLTableAlias($association->targetEntity) . '.' . $targetCol;
                }

                // Add filter SQL
                $filterSql = $this->generateFilterConditionSQL($eagerEntity, $joinTableAlias);
                if ($filterSql) {
                    $joinCondition[] = $filterSql;
                }
            }

            $this->currentPersisterContext->selectJoinSql .= ' ' . $joinTableName . ' ' . $joinTableAlias . ' ON ';
            $this->currentPersisterContext->selectJoinSql .= implode(' AND ', $joinCondition);
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);
        $this->updateFilterHash();

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /** Gets the SQL join fragment used when selecting entities from an association. */
    protected function getSelectColumnAssociationSQL(
        string $field,
        AssociationMapping $assoc,
        ClassMetadata $class,
        string $alias = 'r',
    ): string {
        if (! $assoc->isToOneOwningSide()) {
            return '';
        }

        $columnList    = [];
        $targetClass   = $this->em->getClassMetadata($assoc->targetEntity);
        $isIdentifier  = isset($assoc->id) && $assoc->id === true;
        $sqlTableAlias = $this->getSQLTableAlias($class->name, ($alias === 'r' ? '' : $alias));

        foreach ($assoc->joinColumns as $joinColumn) {
            $quotedColumn     = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
            $resultColumnName = $this->getSQLColumnAlias($joinColumn->name);
            $type             = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);

            $this->currentPersisterContext->rsm->addMetaResult($alias, $resultColumnName, $joinColumn->name, $isIdentifier, $type);

            $columnList[] = sprintf('%s.%s AS %s', $sqlTableAlias, $quotedColumn, $resultColumnName);
        }

        return implode(', ', $columnList);
    }

    /**
     * Gets the SQL join fragment used when selecting entities from a
     * many-to-many association.
     */
    protected function getSelectManyToManyJoinSQL(AssociationMapping&ManyToManyAssociationMapping $manyToMany): string
    {
        $conditions       = [];
        $association      = $manyToMany;
        $sourceTableAlias = $this->getSQLTableAlias($this->class->name);

        $association   = $this->em->getMetadataFactory()->getOwningSide($manyToMany);
        $joinTableName = $this->quoteStrategy->getJoinTableName($association, $this->class, $this->platform);
        $joinColumns   = $manyToMany->isOwningSide()
            ? $association->joinTable->inverseJoinColumns
            : $association->joinTable->joinColumns;

        foreach ($joinColumns as $joinColumn) {
            $quotedSourceColumn = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
            $quotedTargetColumn = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $this->class, $this->platform);
            $conditions[]       = $sourceTableAlias . '.' . $quotedTargetColumn . ' = ' . $joinTableName . '.' . $quotedSourceColumn;
        }

        return ' INNER JOIN ' . $joinTableName . ' ON ' . implode(' AND ', $conditions);
    }

    public function getInsertSQL(): string
    {
        $columns   = $this->getInsertColumnList();
        $tableName = $this->quoteStrategy->getTableName($this->class, $this->platform);

        if ($columns === []) {
            $identityColumn = $this->quoteStrategy->getColumnName($this->class->identifier[0], $this->class, $this->platform);

            return $this->platform->getEmptyIdentityInsertSQL($tableName, $identityColumn);
        }

        $placeholders = [];
        $columns      = array_unique($columns);

        foreach ($columns as $column) {
            $placeholder = '?';

            if (
                isset($this->class->fieldNames[$column])
                && isset($this->columnTypes[$this->class->fieldNames[$column]])
                && isset($this->class->fieldMappings[$this->class->fieldNames[$column]])
            ) {
                $type        = Type::getType($this->columnTypes[$this->class->fieldNames[$column]]);
                $placeholder = $type->convertToDatabaseValueSQL('?', $this->platform);
            }

            $placeholders[] = $placeholder;
        }

        $columns      = implode(', ', $columns);
        $placeholders = implode(', ', $placeholders);

        return sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $columns, $placeholders);
    }

    /**
     * Gets the list of columns to put in the INSERT SQL statement.
     *
     * Subclasses should override this method to alter or change the list of
     * columns placed in the INSERT statements used by the persister.
     *
     * @phpstan-return list<string>
     */
    protected function getInsertColumnList(): array
    {
        $columns = [];

        foreach ($this->class->propertyAccessors as $name => $field) {
            if ($this->class->isVersioned && $this->class->versionField === $name) {
                continue;
            }

            if (isset($this->class->embeddedClasses[$name])) {
                continue;
            }

            if (isset($this->class->associationMappings[$name])) {
                $assoc = $this->class->associationMappings[$name];

                if ($assoc->isToOneOwningSide()) {
                    foreach ($assoc->joinColumns as $joinColumn) {
                        $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
                    }
                }

                continue;
            }

            if (! $this->class->isIdGeneratorIdentity() || $this->class->identifier[0] !== $name) {
                if (isset($this->class->fieldMappings[$name]->notInsertable)) {
                    continue;
                }

                $columns[]                = $this->quoteStrategy->getColumnName($name, $this->class, $this->platform);
                $this->columnTypes[$name] = $this->class->fieldMappings[$name]->type;
            }
        }

        return $columns;
    }

    /**
     * Gets the SQL snippet of a qualified column name for the given field name.
     *
     * @param ClassMetadata $class The class that declares this field. The table this class is
     *                             mapped to must own the column for the given field.
     */
    protected function getSelectColumnSQL(string $field, ClassMetadata $class, string $alias = 'r'): string
    {
        $root         = $alias === 'r' ? '' : $alias;
        $tableAlias   = $this->getSQLTableAlias($class->name, $root);
        $fieldMapping = $class->fieldMappings[$field];
        $sql          = sprintf('%s.%s', $tableAlias, $this->quoteStrategy->getColumnName($field, $class, $this->platform));

        $columnAlias = null;
        if ($this->currentPersisterContext->rsm->hasColumnAliasByField($alias, $field)) {
            $columnAlias = $this->currentPersisterContext->rsm->getColumnAliasByField($alias, $field);
        }

        if ($columnAlias === null) {
            $columnAlias = $this->getSQLColumnAlias($fieldMapping->columnName);
        }

        $this->currentPersisterContext->rsm->addFieldResult($alias, $columnAlias, $field);
        if (! empty($fieldMapping->enumType)) {
            $this->currentPersisterContext->rsm->addEnumResult($columnAlias, $fieldMapping->enumType);
        }

        $type = Type::getType($fieldMapping->type);
        $sql  = $type->convertToPHPValueSQL($sql, $this->platform);

        return $sql . ' AS ' . $columnAlias;
    }

    /**
     * Gets the SQL table alias for the given class name.
     *
     * @todo Reconsider. Binding table aliases to class names is not such a good idea.
     */
    protected function getSQLTableAlias(string $className, string $assocName = ''): string
    {
        if ($assocName) {
            $className .= '#' . $assocName;
        }

        if (isset($this->currentPersisterContext->sqlTableAliases[$className])) {
            return $this->currentPersisterContext->sqlTableAliases[$className];
        }

        $tableAlias = 't' . $this->currentPersisterContext->sqlAliasCounter++;

        $this->currentPersisterContext->sqlTableAliases[$className] = $tableAlias;

        return $tableAlias;
    }

    /**
     * {@inheritDoc}
     */
    public function lock(array $criteria, LockMode|int $lockMode): void
    {
        $conditionSql = $this->getSelectConditionSQL($criteria);

        $lockSql = match ($lockMode) {
            LockMode::PESSIMISTIC_READ => $this->getReadLockSQL($this->platform),
            LockMode::PESSIMISTIC_WRITE => $this->getWriteLockSQL($this->platform),
            default => '',
        };

        $lock  = $this->getLockTablesSql($lockMode);
        $where = ($conditionSql ? ' WHERE ' . $conditionSql : '') . ' ';
        $sql   = 'SELECT 1 '
             . $lock
             . $where
             . $lockSql;

        [$params, $types] = $this->expandParameters($criteria);

        $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * Gets the FROM and optionally JOIN conditions to lock the entity managed by this persister.
     *
     * @phpstan-param LockMode::* $lockMode
     */
    protected function getLockTablesSql(LockMode|int $lockMode): string
    {
        return $this->platform->appendLockHint(
            'FROM '
            . $this->quoteStrategy->getTableName($this->class, $this->platform) . ' '
            . $this->getSQLTableAlias($this->class->name),
            $lockMode,
        );
    }

    /**
     * Gets the Select Where Condition from a Criteria object.
     */
    protected function getSelectConditionCriteriaSQL(Criteria $criteria): string
    {
        $expression = $criteria->getWhereExpression();

        if ($expression === null) {
            return '';
        }

        $visitor = new SqlExpressionVisitor($this, $this->class);

        return $visitor->dispatch($expression);
    }

    public function getSelectConditionStatementSQL(
        string $field,
        mixed $value,
        AssociationMapping|null $assoc = null,
        string|null $comparison = null,
    ): string {
        $comparison ??= (is_array($value) ? Comparison::IN : Comparison::EQ);

        $selectedColumns = [];
        $columns         = $this->getSelectConditionStatementColumnSQL($field, $assoc);

        if (count($columns) > 1 && $comparison === Comparison::IN) {
            /*
             *  @todo try to support multi-column IN expressions.
             *  Example: (col1, col2) IN (('val1A', 'val2A'), ('val1B', 'val2B'))
             */
            throw CantUseInOperatorOnCompositeKeys::create();
        }

        foreach ($columns as $column) {
            $placeholder = '?';

            if (isset($this->class->fieldMappings[$field])) {
                $type        = Type::getType($this->class->fieldMappings[$field]->type);
                $placeholder = $type->convertToDatabaseValueSQL($placeholder, $this->platform);
            }

            // special case null value handling
            if (($comparison === Comparison::EQ || $comparison === Comparison::IS) && $value === null) {
                $selectedColumns[] = $column . ' IS NULL';

                continue;
            }

            if ($comparison === Comparison::NEQ && $value === null) {
                $selectedColumns[] = $column . ' IS NOT NULL';

                continue;
            }

            if ($comparison === Comparison::IN || $comparison === Comparison::NIN) {
                if (! is_array($value)) {
                    $value = [$value];
                }

                if ($value === []) {
                    $selectedColumns[] = '1=0';
                    continue;
                }

                $nullKeys      = array_keys($value, null, true);
                $nonNullValues = array_diff_key($value, array_flip($nullKeys));

                $placeholders = implode(', ', array_fill(0, count($nonNullValues), $placeholder));

                $in = $column . ' ' . sprintf(self::$comparisonMap[$comparison], $placeholders);

                if ($nullKeys) {
                    if ($nonNullValues) {
                        $selectedColumns[] = sprintf('(%s OR %s IS NULL)', $in, $column);
                    } else {
                        $selectedColumns[] = $column . ' IS NULL';
                    }
                } else {
                    $selectedColumns[] = $in;
                }

                continue;
            }

            $selectedColumns[] = $column . ' ' . sprintf(self::$comparisonMap[$comparison], $placeholder);
        }

        return implode(' AND ', $selectedColumns);
    }

    /**
     * Builds the left-hand-side of a where condition statement.
     *
     * @return string[]
     * @phpstan-return list<string>
     *
     * @throws InvalidFindByCall
     * @throws UnrecognizedField
     */
    private function getSelectConditionStatementColumnSQL(
        string $field,
        AssociationMapping|null $assoc = null,
    ): array {
        if (isset($this->class->fieldMappings[$field])) {
            $className = $this->class->fieldMappings[$field]->inherited ?? $this->class->name;

            return [$this->getSQLTableAlias($className) . '.' . $this->quoteStrategy->getColumnName($field, $this->class, $this->platform)];
        }

        if (isset($this->class->associationMappings[$field])) {
            $association = $this->class->associationMappings[$field];
            // Many-To-Many requires join table check for joinColumn
            $columns = [];
            $class   = $this->class;

            if ($association->isManyToMany()) {
                assert($assoc !== null);
                if (! $association->isOwningSide()) {
                    $association = $assoc;
                }

                assert($association->isManyToManyOwningSide());

                $joinTableName = $this->quoteStrategy->getJoinTableName($association, $class, $this->platform);
                $joinColumns   = $assoc->isOwningSide()
                    ? $association->joinTable->joinColumns
                    : $association->joinTable->inverseJoinColumns;

                foreach ($joinColumns as $joinColumn) {
                    $columns[] = $joinTableName . '.' . $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                }
            } else {
                if (! $association->isOwningSide()) {
                    throw InvalidFindByCall::fromInverseSideUsage(
                        $this->class->name,
                        $field,
                    );
                }

                assert($association->isToOneOwningSide());

                $className = $association->inherited ?? $this->class->name;

                foreach ($association->joinColumns as $joinColumn) {
                    $columns[] = $this->getSQLTableAlias($className) . '.' . $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform);
                }
            }

            return $columns;
        }

        if ($assoc !== null && ! str_contains($field, ' ') && ! str_contains($field, '(')) {
            // very careless developers could potentially open up this normally hidden api for userland attacks,
            // therefore checking for spaces and function calls which are not allowed.

            // found a join column condition, not really a "field"
            return [$field];
        }

        throw UnrecognizedField::byFullyQualifiedName($this->class->name, $field);
    }

    /**
     * Gets the conditional SQL fragment used in the WHERE clause when selecting
     * entities in this persister.
     *
     * Subclasses are supposed to override this method if they intend to change
     * or alter the criteria by which entities are selected.
     *
     * @phpstan-param array<string, mixed> $criteria
     */
    protected function getSelectConditionSQL(array $criteria, AssociationMapping|null $assoc = null): string
    {
        $conditions = [];

        foreach ($criteria as $field => $value) {
            $conditions[] = $this->getSelectConditionStatementSQL($field, $value, $assoc);
        }

        return implode(' AND ', $conditions);
    }

    /**
     * {@inheritDoc}
     */
    public function getOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array {
        assert($assoc instanceof OneToManyAssociationMapping);
        $this->switchPersisterContext($offset, $limit);

        $stmt = $this->getOneToManyStatement($assoc, $sourceEntity, $offset, $limit);

        return $this->loadArrayFromResult($assoc, $stmt);
    }

    public function loadOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        PersistentCollection $collection,
    ): mixed {
        assert($assoc instanceof OneToManyAssociationMapping);
        $stmt = $this->getOneToManyStatement($assoc, $sourceEntity);

        return $this->loadCollectionFromStatement($assoc, $stmt, $collection);
    }

    /** Builds criteria and execute SQL statement to fetch the one to many entities from. */
    private function getOneToManyStatement(
        OneToManyAssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): Result {
        $this->switchPersisterContext($offset, $limit);

        $criteria    = [];
        $parameters  = [];
        $owningAssoc = $this->class->associationMappings[$assoc->mappedBy];
        $sourceClass = $this->em->getClassMetadata($assoc->sourceEntity);
        $tableAlias  = $this->getSQLTableAlias($owningAssoc->inherited ?? $this->class->name);
        assert($owningAssoc->isManyToOne());

        foreach ($owningAssoc->targetToSourceKeyColumns as $sourceKeyColumn => $targetKeyColumn) {
            if ($sourceClass->containsForeignIdentifier) {
                $field = $sourceClass->getFieldForColumn($sourceKeyColumn);
                $value = $sourceClass->propertyAccessors[$field]->getValue($sourceEntity);

                if (isset($sourceClass->associationMappings[$field])) {
                    $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
                    $value = $value[$this->em->getClassMetadata($sourceClass->associationMappings[$field]->targetEntity)->identifier[0]];
                }

                $criteria[$tableAlias . '.' . $targetKeyColumn] = $value;
                $parameters[]                                   = [
                    'value' => $value,
                    'field' => $field,
                    'class' => $sourceClass,
                ];

                continue;
            }

            $field = $sourceClass->fieldNames[$sourceKeyColumn];
            $value = $sourceClass->propertyAccessors[$field]->getValue($sourceEntity);

            $criteria[$tableAlias . '.' . $targetKeyColumn] = $value;
            $parameters[]                                   = [
                'value' => $value,
                'field' => $field,
                'class' => $sourceClass,
            ];
        }

        $sql              = $this->getSelectSQL($criteria, $assoc, null, $limit, $offset);
        [$params, $types] = $this->expandToManyParameters($parameters);

        return $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * {@inheritDoc}
     */
    public function expandParameters(array $criteria): array
    {
        $params = [];
        $types  = [];

        foreach ($criteria as $field => $value) {
            if ($value === null) {
                continue; // skip null values.
            }

            if (is_array($value)) {
                $nonNullValues = array_diff_key($value, array_flip(array_keys($value, null, true)));
                foreach ($nonNullValues as $item) {
                    $types  = [...$types, ...PersisterHelper::inferParameterTypes($field, $item, $this->class, $this->em)];
                    $params = [...$params, ...PersisterHelper::convertToParameterValue($item, $this->em)];
                }

                continue;
            }

            $types  = [...$types, ...PersisterHelper::inferParameterTypes($field, $value, $this->class, $this->em)];
            $params = [...$params, ...PersisterHelper::convertToParameterValue($value, $this->em)];
        }

        return [$params, $types];
    }

    /**
     * Expands the parameters from the given criteria and use the correct binding types if found,
     * specialized for OneToMany or ManyToMany associations.
     *
     * @param mixed[][] $criteria an array of arrays containing following:
     *                             - field to which each criterion will be bound
     *                             - value to be bound
     *                             - class to which the field belongs to
     *
     * @return mixed[][]
     * @phpstan-return array{0: array, 1: list<ParameterType::*|ArrayParameterType::*|string>}
     */
    private function expandToManyParameters(array $criteria): array
    {
        $params = [];
        $types  = [];

        foreach ($criteria as $criterion) {
            if ($criterion['value'] === null) {
                continue; // skip null values.
            }

            $types  = [...$types, ...PersisterHelper::inferParameterTypes($criterion['field'], $criterion['value'], $criterion['class'], $this->em)];
            $params = [...$params, ...PersisterHelper::convertToParameterValue($criterion['value'], $this->em)];
        }

        return [$params, $types];
    }

    public function exists(object $entity, Criteria|null $extraConditions = null): bool
    {
        $criteria = $this->class->getIdentifierValues($entity);

        if (! $criteria) {
            return false;
        }

        $alias = $this->getSQLTableAlias($this->class->name);

        $sql = 'SELECT 1 '
             . $this->getLockTablesSql(LockMode::NONE)
             . ' WHERE ' . $this->getSelectConditionSQL($criteria);

        [$params, $types] = $this->expandParameters($criteria);

        if ($extraConditions !== null) {
            $sql                             .= ' AND ' . $this->getSelectConditionCriteriaSQL($extraConditions);
            [$criteriaParams, $criteriaTypes] = $this->expandCriteriaParameters($extraConditions);

            $params = [...$params, ...$criteriaParams];
            $types  = [...$types, ...$criteriaTypes];
        }

        $filterSql = $this->generateFilterConditionSQL($this->class, $alias);
        if ($filterSql) {
            $sql .= ' AND ' . $filterSql;
        }

        return (bool) $this->conn->fetchOne($sql, $params, $types);
    }

    /**
     * Generates the appropriate join SQL for the given join column.
     *
     * @param list<JoinColumnMapping> $joinColumns The join columns definition of an association.
     *
     * @return string LEFT JOIN if one of the columns is nullable, INNER JOIN otherwise.
     */
    protected function getJoinSQLForJoinColumns(array $joinColumns): string
    {
        // if one of the join columns is nullable, return left join
        foreach ($joinColumns as $joinColumn) {
            if (! isset($joinColumn->nullable) || $joinColumn->nullable) {
                return 'LEFT JOIN';
            }
        }

        return 'INNER JOIN';
    }

    public function getSQLColumnAlias(string $columnName): string
    {
        return $this->quoteStrategy->getColumnAlias($columnName, $this->currentPersisterContext->sqlAliasCounter++, $this->platform);
    }

    /**
     * Generates the filter SQL for a given entity and table alias.
     *
     * @param ClassMetadata $targetEntity     Metadata of the target entity.
     * @param string        $targetTableAlias The table alias of the joined/selected table.
     *
     * @return string The SQL query part to add to a query.
     */
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $filterClauses = [];

        foreach ($this->em->getFilters()->getEnabledFilters() as $filter) {
            $filterExpr = $filter->addFilterConstraint($targetEntity, $targetTableAlias);
            if ($filterExpr !== '') {
                $filterClauses[] = '(' . $filterExpr . ')';
            }
        }

        $sql = implode(' AND ', $filterClauses);

        return $sql ? '(' . $sql . ')' : ''; // Wrap again to avoid "X or Y and FilterConditionSQL"
    }

    /**
     * Switches persister context according to current query offset/limits
     *
     * This is due to the fact that to-many associations cannot be fetch-joined when a limit is involved
     */
    protected function switchPersisterContext(int|null $offset, int|null $limit): void
    {
        if ($offset === null && $limit === null) {
            $this->currentPersisterContext = $this->noLimitsContext;

            return;
        }

        $this->currentPersisterContext = $this->limitsHandlingContext;
    }

    /**
     * @return string[]
     * @phpstan-return list<string>
     */
    protected function getClassIdentifiersTypes(ClassMetadata $class): array
    {
        $entityManager = $this->em;

        return array_map(
            static function ($fieldName) use ($class, $entityManager): string {
                $types = PersisterHelper::getTypeOfField($fieldName, $class, $entityManager);
                assert(isset($types[0]));

                return $types[0];
            },
            $class->identifier,
        );
    }
}
