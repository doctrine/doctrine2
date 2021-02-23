<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ColumnMetadata;
use Doctrine\ORM\Mapping\ComponentMetadata;
use Doctrine\ORM\Mapping\EmbeddedMetadata;
use Doctrine\ORM\Mapping\FetchMode;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\GeneratorType;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\LocalColumnMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMetadata;
use Doctrine\ORM\Mapping\Property;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Exception\CantUseInOperatorOnCompositeKeys;
use Doctrine\ORM\Persisters\Exception\InvalidOrientation;
use Doctrine\ORM\Persisters\Exception\UnrecognizedField;
use Doctrine\ORM\Persisters\SqlExpressionVisitor;
use Doctrine\ORM\Persisters\SqlValueVisitor;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Repository\Exception\InvalidFindByCall;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\StaticClassNameConverter;
use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function sprintf;
use function strpos;
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
 *   - {@link insert} : To insert the persistent state of an entity.
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
 *   - {@link loadToOneEntity} : Loads a one/many-to-one entity association (lazy-loading).
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
    /** @var string[] */
    private static $comparisonMap = [
        Comparison::IS          => '= %s',
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
     * Metadata object that describes the mapping of the mapped entity class.
     *
     * @var ClassMetadata
     */
    protected $class;

    /**
     * The underlying DBAL Connection of the used EntityManager.
     *
     * @var Connection
     */
    protected $conn;

    /**
     * The database platform.
     *
     * @var AbstractPlatform
     */
    protected $platform;

    /**
     * The EntityManager instance.
     *
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * The map of column names to DBAL columns used when INSERTing an entity.
     *
     * @see getColumnList($class, $columnPrefix = '')
     * @see prepareInsertData($entity)
     *
     * @var ColumnMetadata[]
     */
    protected $insertColumns = null;

    /**
     * The map of column names to DBAL columns used when UPDATEing an entity.
     *
     * @see getColumnList($class, $columnPrefix = '')
     * @see prepareUpdateData($entity)
     *
     * @var ColumnMetadata[]
     */
    protected $updateColumns = null;

    /**
     * The INSERT SQL statement used for entities handled by this persister.
     * This SQL is only generated once per request, if at all.
     *
     * @var string
     */
    private $insertSql;

    /** @var CachedPersisterContext */
    protected $currentPersisterContext;

    /** @var CachedPersisterContext */
    private $limitsHandlingContext;

    /** @var CachedPersisterContext */
    private $noLimitsContext;

    /**
     * Initializes a new <tt>BasicEntityPersister</tt> that uses the given EntityManager
     * and persists instances of the class described by the given ClassMetadata descriptor.
     */
    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        $this->em                    = $em;
        $this->class                 = $class;
        $this->conn                  = $em->getConnection();
        $this->platform              = $this->conn->getDatabasePlatform();
        $this->noLimitsContext       = $this->currentPersisterContext = new CachedPersisterContext(
            $class,
            new Query\ResultSetMapping(),
            false
        );
        $this->limitsHandlingContext = new CachedPersisterContext(
            $class,
            new Query\ResultSetMapping(),
            true
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getClassMetadata()
    {
        return $this->class;
    }

    /**
     * {@inheritdoc}
     */
    public function getResultSetMapping()
    {
        return $this->currentPersisterContext->rsm;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier($entity) : array
    {
        $id = [];

        foreach ($this->class->getIdentifier() as $fieldName) {
            $property = $this->class->getProperty($fieldName);
            $value    = $property->getValue($entity);

            if ($value !== null) {
                $id[$fieldName] = $value;
            }
        }

        return $id;
    }

    /**
     * Populates the entity identifier of an entity.
     *
     * @param object  $entity
     * @param mixed[] $id
     */
    public function setIdentifier($entity, array $id) : void
    {
        foreach ($id as $idField => $idValue) {
            $property = $this->class->getProperty($idField);

            $property->setValue($entity, $idValue);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function insert($entity)
    {
        $stmt           = $this->conn->prepare($this->getInsertSQL());
        $tableName      = $this->class->getTableName();
        $insertData     = $this->prepareInsertData($entity);
        $generationPlan = $this->class->getValueGenerationPlan();

        if (isset($insertData[$tableName])) {
            foreach ($insertData[$tableName] as $paramIndex => $parameter) {
                $stmt->bindValue($paramIndex + 1, $parameter->getValue(), $parameter->getType());
            }
        }

        $stmt->execute();

        if ($generationPlan->containsDeferred()) {
            $generationPlan->executeDeferred($this->em, $entity);
        }

        if ($this->class->isVersioned()) {
            $this->assignDefaultVersionValue($entity, $this->getIdentifier($entity));
        }

        $stmt->closeCursor();
    }

    /**
     * Retrieves the default version value which was created
     * by the preceding INSERT statement and assigns it back in to the
     * entities version field.
     *
     * @param object  $entity
     * @param mixed[] $id
     */
    protected function assignDefaultVersionValue($entity, array $id)
    {
        $versionProperty = $this->class->versionProperty;
        $versionValue    = $this->fetchVersionValue($versionProperty, $id);

        $versionProperty->setValue($entity, $versionValue);
    }

    /**
     * Fetches the current version value of a versioned entity.
     *
     * @param mixed[] $id
     *
     * @return mixed
     */
    protected function fetchVersionValue(FieldMetadata $versionProperty, array $id)
    {
        $versionedClass = $versionProperty->getDeclaringClass();
        $tableName      = $versionedClass->table->getQuotedQualifiedName($this->platform);
        $columnName     = $this->platform->quoteIdentifier($versionProperty->getColumnName());
        $identifier     = array_map(
            function ($columnName) {
                return $this->platform->quoteIdentifier($columnName);
            },
            array_keys($versionedClass->getIdentifierColumns($this->em))
        );

        // FIXME: Order with composite keys might not be correct
        $sql = 'SELECT ' . $columnName
             . ' FROM ' . $tableName
             . ' WHERE ' . implode(' = ? AND ', $identifier) . ' = ?';

        $flattenedId = $this->em->getIdentifierFlattener()->flattenIdentifier($versionedClass, $id);
        $versionType = $versionProperty->getType();

        $value = $this->conn->fetchColumn(
            $sql,
            array_values($flattenedId),
            $this->extractIdentifierTypes($id, $versionedClass)
        );

        return $versionType->convertToPHPValue($value, $this->platform);
    }

    /**
     * @param mixed[] $id
     *
     * @return mixed[]
     */
    private function extractIdentifierTypes(array $id, ClassMetadata $versionedClass) : array
    {
        $types = [];

        foreach ($id as $field => $value) {
            $types = array_merge($types, $this->getTypes($field, $value, $versionedClass));
        }

        return $types;
    }

    /**
     * {@inheritdoc}
     */
    public function update($entity)
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

        $isVersioned     = $this->class->isVersioned();
        $quotedTableName = $this->class->table->getQuotedQualifiedName($this->platform);

        $this->updateTable($entity, $quotedTableName, $data, $isVersioned);

        if ($isVersioned) {
            $id = $this->em->getUnitOfWork()->getEntityIdentifier($entity);

            $this->assignDefaultVersionValue($entity, $id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($entity)
    {
        $class      = $this->class;
        $unitOfWork = $this->em->getUnitOfWork();
        $identifier = $unitOfWork->getEntityIdentifier($entity);
        $tableName  = $class->table->getQuotedQualifiedName($this->platform);

        $types = [];
        $id    = [];

        foreach ($class->identifier as $field) {
            $property = $class->getProperty($field);

            if ($property instanceof FieldMetadata) {
                $columnName       = $property->getColumnName();
                $quotedColumnName = $this->platform->quoteIdentifier($columnName);

                $id[$quotedColumnName] = $identifier[$field];
                $types[]               = $property->getType();

                continue;
            }

            $targetClass = $this->em->getClassMetadata($property->getTargetEntity());
            $joinColumns = $property instanceof ManyToManyAssociationMetadata
                ? $property->getTable()->getJoinColumns()
                : $property->getJoinColumns();

            $associationValue = null;
            $value            = $identifier[$field];

            if ($value !== null) {
                // @todo guilhermeblanco Make sure we do not have flat association values.
                if (! is_array($value)) {
                    $value = [$targetClass->identifier[0] => $value];
                }

                $associationValue = $value;
            }

            foreach ($joinColumns as $joinColumn) {
                /** @var JoinColumnMetadata $joinColumn */
                $quotedColumnName     = $this->platform->quoteIdentifier($joinColumn->getColumnName());
                $referencedColumnName = $joinColumn->getReferencedColumnName();
                $targetField          = $targetClass->fieldNames[$referencedColumnName];

                $id[$quotedColumnName] = $associationValue ? $associationValue[$targetField] : null;
                $types[]               = $joinColumn->getType();
            }
        }

        $this->deleteJoinTableRecords($identifier);

        return (bool) $this->conn->delete($tableName, $id, $types);
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
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws DBALException
     */
    final protected function updateTable($entity, $quotedTableName, array $updateData, $versioned = false)
    {
        $set    = [];
        $types  = [];
        $params = [];

        foreach ($updateData as $parameter) {
            $type        = $parameter->getType();
            $placeholder = $type->convertToDatabaseValueSQL('?', $this->platform);

            $set[]    = sprintf('%s = %s', $this->platform->quoteIdentifier($parameter->getName()), $placeholder);
            $params[] = $parameter->getValue();
            $types[]  = $type;
        }

        // @todo guilhermeblanco Bring this back: $this->em->getUnitOfWork()->getEntityIdentifier($entity);
        $identifier = $this->getIdentifier($entity);
        $where      = [];

        foreach ($this->class->identifier as $idField) {
            $property = $this->class->getProperty($idField);

            switch (true) {
                case $property instanceof FieldMetadata:
                    $where[]  = $this->platform->quoteIdentifier($property->getColumnName());
                    $params[] = $identifier[$idField];
                    $types[]  = $property->getType();
                    break;

                case $property instanceof ToOneAssociationMetadata:
                    $targetPersister = $this->em->getUnitOfWork()->getEntityPersister($property->getTargetEntity());

                    foreach ($property->getJoinColumns() as $joinColumn) {
                        /** @var JoinColumnMetadata $joinColumn */
                        $quotedColumnName     = $this->platform->quoteIdentifier($joinColumn->getColumnName());
                        $referencedColumnName = $joinColumn->getReferencedColumnName();
                        $value                = $targetPersister->getColumnValue($identifier[$idField], $referencedColumnName);

                        $where[]  = $quotedColumnName;
                        $params[] = $value;
                        $types[]  = $joinColumn->getType();
                    }
                    break;
            }
        }

        if ($versioned) {
            $versionProperty   = $this->class->versionProperty;
            $versionColumnType = $versionProperty->getType();
            $versionColumnName = $this->platform->quoteIdentifier($versionProperty->getColumnName());

            $where[]  = $versionColumnName;
            $types[]  = $versionColumnType;
            $params[] = $versionProperty->getValue($entity);

            switch ($versionColumnType->getName()) {
                case Type::SMALLINT:
                case Type::INTEGER:
                case Type::BIGINT:
                    $set[] = $versionColumnName . ' = ' . $versionColumnName . ' + 1';
                    break;

                case Type::DATETIME:
                    $set[] = $versionColumnName . ' = CURRENT_TIMESTAMP';
                    break;
            }
        }

        $sql = 'UPDATE ' . $quotedTableName
             . ' SET ' . implode(', ', $set)
             . ' WHERE ' . implode(' = ? AND ', $where) . ' = ?';

        $result = $this->conn->executeUpdate($sql, $params, $types);

        if ($versioned && ! $result) {
            throw OptimisticLockException::lockFailed($entity);
        }
    }

    /**
     * @param mixed[] $identifier
     *
     * @todo Add check for platform if it supports foreign keys/cascading.
     */
    protected function deleteJoinTableRecords($identifier)
    {
        foreach ($this->class->getPropertiesIterator() as $association) {
            if (! ($association instanceof ManyToManyAssociationMetadata)) {
                continue;
            }

            // @Todo this only covers scenarios with no inheritance or of the same level. Is there something
            // like self-referential relationship between different levels of an inheritance hierarchy? I hope not!
            $selfReferential   = $association->getTargetEntity() === $association->getSourceEntity();
            $owningAssociation = $association;
            $otherColumns      = [];
            $otherKeys         = [];
            $keys              = [];

            if (! $owningAssociation->isOwningSide()) {
                $class             = $this->em->getClassMetadata($association->getTargetEntity());
                $owningAssociation = $class->getProperty($association->getMappedBy());
            }

            $joinTable     = $owningAssociation->getJoinTable();
            $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
            $joinColumns   = $association->isOwningSide()
                ? $joinTable->getJoinColumns()
                : $joinTable->getInverseJoinColumns();

            if ($selfReferential) {
                $otherColumns = ! $association->isOwningSide()
                    ? $joinTable->getJoinColumns()
                    : $joinTable->getInverseJoinColumns();
            }

            $isOnDeleteCascade = false;

            foreach ($joinColumns as $joinColumn) {
                $keys[] = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                if ($joinColumn->isOnDeleteCascade()) {
                    $isOnDeleteCascade = true;
                }
            }

            foreach ($otherColumns as $joinColumn) {
                $otherKeys[] = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                if ($joinColumn->isOnDeleteCascade()) {
                    $isOnDeleteCascade = true;
                }
            }

            if ($isOnDeleteCascade) {
                continue;
            }

            $this->conn->delete($joinTableName, array_combine($keys, $identifier));

            if ($selfReferential) {
                $this->conn->delete($joinTableName, array_combine($otherKeys, $identifier));
            }
        }
    }

    /**
     * Prepares the data changeset of a managed entity for database insertion (initial INSERT).
     * The changeset of the entity is obtained from the currently running UnitOfWork.
     *
     * @param object $entity The entity for which to prepare the data.
     *
     * @return Query\Parameter[][] The prepared data for the tables to update.
     */
    protected function prepareInsertData($entity) : array
    {
        $unitOfWork = $this->em->getUnitOfWork();
        $changeSet  = $unitOfWork->getEntityChangeSet($entity);
        $result     = [];

        $tableName    = $this->class->getTableName();
        $columnPrefix = '';

        foreach ($changeSet as $propertyName => $propertyChangeSet) {
            $property = $this->class->getProperty($propertyName);
            $newValue = $propertyChangeSet[1];

            // @todo guilhermeblanco Check if property is insertable here
            // Refactor to pull column values from properties $property->getColumValues();

            switch (true) {
                case $property instanceof FieldMetadata && $property->isVersioned():
                    // Do nothing
                    break;

                case $property instanceof LocalColumnMetadata:
                    $columnTableName = $property->getTableName() ?? $tableName;
                    $columnName      = sprintf('%s%s', $columnPrefix, $property->getColumnName());

                    $result[$columnTableName][] = new Query\Parameter($columnName, $newValue, $property->getType());
                    break;

                case $property instanceof AssociationMetadata:
                    if ($property instanceof ToOneAssociationMetadata && $property->isOwningSide()) {
                        // The associated entity $newVal is not yet persisted, so we must
                        // set $newVal = null, in order to insert a null value and schedule an
                        // extra update on the UnitOfWork.
                        if ($newValue !== null && $unitOfWork->isScheduledForInsert($newValue)) {
                            $unitOfWork->scheduleExtraUpdate($entity, [$propertyName => [null, $newValue]]);

                            $newValue = null;
                        }

                        $targetClass     = $this->em->getClassMetadata($property->getTargetEntity());
                        $targetPersister = $unitOfWork->getEntityPersister($targetClass->getClassName());

                        foreach ($property->getJoinColumns() as $joinColumn) {
                            $columnTableName = $joinColumn->getTableName() ?? $tableName;
                            $columnName      = sprintf('%s%s', $columnPrefix, $joinColumn->getColumnName());

                            $columnValue = $newValue !== null
                                ? $targetPersister->getColumnValue($newValue, $joinColumn->getReferencedColumnName())
                                : null;

                            $result[$columnTableName][] = new Query\Parameter($columnName, $columnValue, $joinColumn->getType());
                        }
                    }

                    break;
            }
        }

        return $result;
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
     * @param object $entity The entity for which to prepare the data.
     *
     * @return mixed[] The prepared data.
     */
    protected function prepareUpdateData($entity)
    {
        $unitOfWork = $this->em->getUnitOfWork();
        $changeSet  = $unitOfWork->getEntityChangeSet($entity);
        $result     = [];

        $tableName    = $this->class->getTableName();
        $columnPrefix = '';

        // Make sure the table with version column is updated even if no columns on table were affected.
        if ($this->class->isVersioned()) {
            $columnTableName = $this->class->versionProperty->getTableName() ?? $tableName;

            $result[$columnTableName] = [];
        }

        foreach ($changeSet as $propertyName => $propertyChangeSet) {
            $property = $this->class->getProperty($propertyName);
            $newValue = $propertyChangeSet[1];

            // @todo guilhermeblanco Check if property is updatable here
            // Refactor to pull column values from properties $property->getColumValues();

            switch (true) {
                case $property instanceof FieldMetadata && $property->isVersioned():
                    // Do nothing
                    break;

                case $property instanceof LocalColumnMetadata:
                    $columnTableName = $property->getTableName() ?? $tableName;
                    $columnName      = sprintf('%s%s', $columnPrefix, $property->getColumnName());

                    $result[$columnTableName][] = new Query\Parameter($columnName, $newValue, $property->getType());
                    break;

                case $property instanceof AssociationMetadata:
                    if ($property instanceof ToOneAssociationMetadata && $property->isOwningSide()) {
                        // The associated entity $newVal is not yet persisted, so we must
                        // set $newVal = null, in order to insert a null value and schedule an
                        // extra update on the UnitOfWork.
                        if ($newValue !== null && $unitOfWork->isScheduledForInsert($newValue)) {
                            $unitOfWork->scheduleExtraUpdate($entity, [$property->getName() => [null, $newValue]]);

                            $newValue = null;
                        }

                        $targetClass     = $this->em->getClassMetadata($property->getTargetEntity());
                        $targetPersister = $unitOfWork->getEntityPersister($targetClass->getClassName());

                        foreach ($property->getJoinColumns() as $joinColumn) {
                            $columnTableName = $joinColumn->getTableName() ?? $tableName;
                            $columnName      = sprintf('%s%s', $columnPrefix, $joinColumn->getColumnName());

                            $columnValue = $newValue !== null
                                ? $targetPersister->getColumnValue($newValue, $joinColumn->getReferencedColumnName())
                                : null;

                            $result[$columnTableName][] = new Query\Parameter($columnName, $columnValue, $joinColumn->getType());
                        }
                    }

                    break;
            }
        }

        return $result;
    }

    /**
     * @param object $entity
     *
     * @return mixed|null
     */
    public function getColumnValue($entity, string $columnName)
    {
        // Looking for fields by column is the easiest way to look at local columns or x-1 owning side associations
        $property = $this->class->getProperty($this->class->fieldNames[$columnName]);

        if (! $property) {
            return null;
        }

        $propertyValue = $property->getValue($entity);

        if ($property instanceof LocalColumnMetadata) {
            return $propertyValue;
        }

        /** @var ToOneAssociationMetadata $property */
        $unitOfWork      = $this->em->getUnitOfWork();
        $targetPersister = $unitOfWork->getEntityPersister($property->getTargetEntity());

        foreach ($property->getJoinColumns() as $joinColumn) {
            if ($joinColumn->getColumnName() !== $columnName) {
                continue;
            }

            return $targetPersister->getColumnValue($propertyValue, $joinColumn->getReferencedColumnName());
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function load(
        array $criteria,
        $entity = null,
        ?AssociationMetadata $association = null,
        array $hints = [],
        $lockMode = null,
        $limit = null,
        array $orderBy = []
    ) {
        $this->switchPersisterContext(null, $limit);

        $sql = $this->getSelectSQL($criteria, $association, $lockMode, $limit, null, $orderBy);

        [$params, $types] = $this->expandParameters($criteria);

        $stmt = $this->conn->executeQuery($sql, $params, $types);

        if ($entity !== null) {
            $hints[Query::HINT_REFRESH]        = true;
            $hints[Query::HINT_REFRESH_ENTITY] = $entity;
        }

        $hydrator = $this->em->newHydrator($this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT);
        $entities = $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, $hints);

        return $entities ? $entities[0] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function loadById(array $identifier, $entity = null)
    {
        return $this->load($identifier, $entity);
    }

    /**
     * {@inheritdoc}
     */
    public function loadToOneEntity(ToOneAssociationMetadata $association, $sourceEntity, array $identifier = [])
    {
        $unitOfWork   = $this->em->getUnitOfWork();
        $targetEntity = $association->getTargetEntity();
        $foundEntity  = $unitOfWork->tryGetById($identifier, $targetEntity);

        if ($foundEntity !== false) {
            return $foundEntity;
        }

        $targetClass = $this->em->getClassMetadata($targetEntity);

        if ($association->isOwningSide()) {
            $inversedBy            = $association->getInversedBy();
            $targetProperty        = $inversedBy ? $targetClass->getProperty($inversedBy) : null;
            $isInverseSingleValued = $targetProperty && $targetProperty instanceof ToOneAssociationMetadata;

            // Mark inverse side as fetched in the hints, otherwise the UoW would
            // try to load it in a separate query (remember: to-one inverse sides can not be lazy).
            $hints = [];

            if ($isInverseSingleValued) {
                $hints['fetched']['r'][$inversedBy] = true;
            }

            /* cascade read-only status
            if ($this->em->getUnitOfWork()->isReadOnly($sourceEntity)) {
                $hints[Query::HINT_READ_ONLY] = true;
            }
            */

            $entity = $this->load($identifier, null, $association, $hints);

            // Complete bidirectional association, if necessary
            if ($entity !== null && $isInverseSingleValued) {
                $targetProperty->setValue($entity, $sourceEntity);
            }

            return $entity;
        }

        $sourceClass       = $association->getDeclaringClass();
        $owningAssociation = $targetClass->getProperty($association->getMappedBy());
        $targetTableAlias  = $this->getSQLTableAlias($targetClass->getTableName());

        foreach ($owningAssociation->getJoinColumns() as $joinColumn) {
            $sourceKeyColumn = $joinColumn->getReferencedColumnName();
            $targetKeyColumn = $joinColumn->getColumnName();

            if (! isset($sourceClass->fieldNames[$sourceKeyColumn])) {
                throw MappingException::joinColumnMustPointToMappedField(
                    $sourceClass->getClassName(),
                    $sourceKeyColumn
                );
            }

            $property = $sourceClass->getProperty($sourceClass->fieldNames[$sourceKeyColumn]);
            $value    = $property->getValue($sourceEntity);

            // unset the old value and set the new sql aliased value here. By definition
            // unset($identifier[$targetKeyColumn] works here with how UnitOfWork::createEntity() calls this method.
            // @todo guilhermeblanco In master we have: $identifier[$targetClass->getFieldForColumn($targetKeyColumn)] =
            unset($identifier[$targetKeyColumn]);

            $identifier[$targetClass->fieldNames[$targetKeyColumn]] = $value;
        }

        $entity = $this->load($identifier, null, $association);

        if ($entity !== null) {
            $owningAssociation->setValue($entity, $sourceEntity);
        }

        return $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function refresh(array $id, $entity, $lockMode = null)
    {
        $sql              = $this->getSelectSQL($id, null, $lockMode);
        [$params, $types] = $this->expandParameters($id);
        $stmt             = $this->conn->executeQuery($sql, $params, $types);

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hydrator->hydrateAll($stmt, $this->currentPersisterContext->rsm, [Query::HINT_REFRESH => true]);
    }

    /**
     * {@inheritDoc}
     */
    public function count($criteria = [])
    {
        $sql = $this->getCountSQL($criteria);

        [$params, $types] = $criteria instanceof Criteria
            ? $this->expandCriteriaParameters($criteria)
            : $this->expandParameters($criteria);

        return (int) $this->conn->executeQuery($sql, $params, $types)->fetchColumn();
    }

    /**
     * {@inheritdoc}
     */
    public function loadCriteria(Criteria $criteria)
    {
        $orderBy = $criteria->getOrderings();
        $limit   = $criteria->getMaxResults();
        $offset  = $criteria->getFirstResult();
        $query   = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);

        [$params, $types] = $this->expandCriteriaParameters($criteria);

        $stmt         = $this->conn->executeQuery($query, $params, $types);
        $rsm          = $this->currentPersisterContext->rsm;
        $hints        = [UnitOfWork::HINT_DEFEREAGERLOAD => true];
        $hydratorType = $this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT;
        $hydrator     = $this->em->newHydrator($hydratorType);

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * {@inheritdoc}
     */
    public function expandCriteriaParameters(Criteria $criteria)
    {
        $expression = $criteria->getWhereExpression();
        $sqlParams  = [];
        $sqlTypes   = [];

        if ($expression === null) {
            return [$sqlParams, $sqlTypes];
        }

        $valueVisitor = new SqlValueVisitor();

        $valueVisitor->dispatch($expression);

        [$params, $types] = $valueVisitor->getParamsAndTypes();

        foreach ($params as $param) {
            $sqlParams = array_merge($sqlParams, $this->getValues($param));
        }

        foreach ($types as $type) {
            [$field, $value] = $type;
            $sqlTypes        = array_merge($sqlTypes, $this->getTypes($field, $value, $this->class));
        }

        return [$sqlParams, $sqlTypes];
    }

    /**
     * {@inheritdoc}
     */
    public function loadAll(array $criteria = [], array $orderBy = [], $limit = null, $offset = null)
    {
        $this->switchPersisterContext($offset, $limit);

        $sql = $this->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);

        [$params, $types] = $this->expandParameters($criteria);

        $stmt         = $this->conn->executeQuery($sql, $params, $types);
        $rsm          = $this->currentPersisterContext->rsm;
        $hints        = [UnitOfWork::HINT_DEFEREAGERLOAD => true];
        $hydratorType = $this->currentPersisterContext->selectJoinSql ? Query::HYDRATE_OBJECT : Query::HYDRATE_SIMPLEOBJECT;
        $hydrator     = $this->em->newHydrator($hydratorType);

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * {@inheritdoc}
     */
    public function getManyToManyCollection(
        ManyToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    ) {
        $this->switchPersisterContext($offset, $limit);

        $stmt = $this->getManyToManyStatement($association, $sourceEntity, $offset, $limit);

        return $this->loadArrayFromStatement($association, $stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function loadManyToManyCollection(
        ManyToManyAssociationMetadata $association,
        $sourceEntity,
        PersistentCollection $collection
    ) {
        $stmt = $this->getManyToManyStatement($association, $sourceEntity);

        return $this->loadCollectionFromStatement($association, $stmt, $collection);
    }

    /**
     * Loads an array of entities from a given DBAL statement.
     *
     * @param Statement $stmt
     *
     * @return mixed[]
     */
    private function loadArrayFromStatement(ToManyAssociationMetadata $association, $stmt)
    {
        $rsm = $this->currentPersisterContext->rsm;

        if ($association->getIndexedBy()) {
            $rsm = clone $this->currentPersisterContext->rsm; // this is necessary because the "default rsm" should be changed.
            $rsm->addIndexBy('r', $association->getIndexedBy());
        }

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hints    = [UnitOfWork::HINT_DEFEREAGERLOAD => true];

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * Hydrates a collection from a given DBAL statement.
     *
     * @param Statement            $stmt
     * @param PersistentCollection $collection
     *
     * @return mixed[]
     */
    private function loadCollectionFromStatement(ToManyAssociationMetadata $association, $stmt, $collection)
    {
        $rsm = $this->currentPersisterContext->rsm;

        if ($association->getIndexedBy()) {
            $rsm = clone $this->currentPersisterContext->rsm; // this is necessary because the "default rsm" should be changed.
            $rsm->addIndexBy('r', $association->getIndexedBy());
        }

        $hydrator = $this->em->newHydrator(Query::HYDRATE_OBJECT);
        $hints    = [
            UnitOfWork::HINT_DEFEREAGERLOAD => true,
            'collection' => $collection,
        ];

        return $hydrator->hydrateAll($stmt, $rsm, $hints);
    }

    /**
     * @param object   $sourceEntity
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return DriverStatement
     *
     * @throws MappingException
     */
    private function getManyToManyStatement(
        ManyToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    ) {
        $this->switchPersisterContext($offset, $limit);

        /** @var ClassMetadata $sourceClass */
        $sourceClass = $this->em->getClassMetadata($association->getSourceEntity());
        $class       = $sourceClass;
        $owningAssoc = $association;
        $criteria    = [];
        $parameters  = [];

        if (! $association->isOwningSide()) {
            $class       = $this->em->getClassMetadata($association->getTargetEntity());
            $owningAssoc = $class->getProperty($association->getMappedBy());
        }

        $joinTable     = $owningAssoc->getJoinTable();
        $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
        $joinColumns   = $association->isOwningSide()
            ? $joinTable->getJoinColumns()
            : $joinTable->getInverseJoinColumns();

        foreach ($joinColumns as $joinColumn) {
            $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());
            $fieldName        = $sourceClass->fieldNames[$joinColumn->getReferencedColumnName()];
            $property         = $sourceClass->getProperty($fieldName);
            $value            = null;

            if ($property instanceof FieldMetadata) {
                $value = $property->getValue($sourceEntity);
            } elseif ($property instanceof AssociationMetadata) {
                $property    = $sourceClass->getProperty($fieldName);
                $targetClass = $this->em->getClassMetadata($property->getTargetEntity());
                $value       = $property->getValue($sourceEntity);

                $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
                $value = $value[$targetClass->identifier[0]];
            }

            $criteria[$joinTableName . '.' . $quotedColumnName] = $value;
            $parameters[]                                       = [
                'value' => $value,
                'field' => $fieldName,
                'class' => $sourceClass,
            ];
        }

        $sql = $this->getSelectSQL($criteria, $association, null, $limit, $offset);

        [$params, $types] = $this->expandToManyParameters($parameters);

        return $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * {@inheritdoc}
     */
    public function getSelectSQL(
        $criteria,
        ?AssociationMetadata $association = null,
        $lockMode = null,
        $limit = null,
        $offset = null,
        array $orderBy = []
    ) {
        $this->switchPersisterContext($offset, $limit);

        $lockSql    = '';
        $joinSql    = '';
        $orderBySql = '';

        if ($association instanceof ManyToManyAssociationMetadata) {
            $joinSql = $this->getSelectManyToManyJoinSQL($association);
        }

        if ($association instanceof ToManyAssociationMetadata && $association->getOrderBy()) {
            $orderBy = $association->getOrderBy();
        }

        if ($orderBy) {
            $orderBySql = $this->getOrderBySQL($orderBy, $this->getSQLTableAlias($this->class->getTableName()));
        }

        $conditionSql = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria, $association);

        switch ($lockMode) {
            case LockMode::PESSIMISTIC_READ:
                $lockSql = ' ' . $this->platform->getReadLockSQL();
                break;

            case LockMode::PESSIMISTIC_WRITE:
                $lockSql = ' ' . $this->platform->getWriteLockSQL();
                break;
        }

        $columnList = $this->getSelectColumnsSQL();
        $tableAlias = $this->getSQLTableAlias($this->class->getTableName());
        $filterSql  = $this->generateFilterConditionSQL($this->class, $tableAlias);
        $tableName  = $this->class->table->getQuotedQualifiedName($this->platform);

        if ($filterSql !== '') {
            $conditionSql = $conditionSql
                ? $conditionSql . ' AND ' . $filterSql
                : $filterSql;
        }

        $select = 'SELECT ' . $columnList;
        $from   = ' FROM ' . $tableName . ' ' . $tableAlias;
        $join   = $this->currentPersisterContext->selectJoinSql . $joinSql;
        $where  = ($conditionSql ? ' WHERE ' . $conditionSql : '');
        $lock   = $this->platform->appendLockHint($from, $lockMode);
        $query  = $select
            . $lock
            . $join
            . $where
            . $orderBySql;

        return $this->platform->modifyLimitQuery($query, $limit, $offset ?? 0) . $lockSql;
    }

    /**
     * {@inheritDoc}
     */
    public function getCountSQL($criteria = [])
    {
        $tableName  = $this->class->table->getQuotedQualifiedName($this->platform);
        $tableAlias = $this->getSQLTableAlias($this->class->getTableName());

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
     * @param mixed[] $orderBy
     * @param string  $baseTableAlias
     *
     * @return string
     *
     * @throws ORMException
     */
    final protected function getOrderBySQL(array $orderBy, $baseTableAlias)
    {
        if (! $orderBy) {
            return '';
        }

        $orderByList = [];

        foreach ($orderBy as $fieldName => $orientation) {
            $orientation = strtoupper(trim($orientation));

            if (! in_array($orientation, ['ASC', 'DESC'], true)) {
                throw InvalidOrientation::fromClassNameAndField($this->class->getClassName(), $fieldName);
            }

            $property = $this->class->getProperty($fieldName);

            if ($property instanceof FieldMetadata) {
                $tableAlias = $this->getSQLTableAlias($property->getTableName());
                $columnName = $this->platform->quoteIdentifier($property->getColumnName());

                $orderByList[] = $tableAlias . '.' . $columnName . ' ' . $orientation;

                continue;
            } elseif ($property instanceof AssociationMetadata) {
                if (! $property->isOwningSide()) {
                    throw InvalidFindByCall::fromInverseSideUsage(
                        $this->class->getClassName(),
                        $fieldName
                    );
                }

                $class      = $this->class->isInheritedProperty($fieldName)
                    ? $property->getDeclaringClass()
                    : $this->class;
                $tableAlias = $this->getSQLTableAlias($class->getTableName());

                foreach ($property->getJoinColumns() as $joinColumn) {
                    /** @var JoinColumnMetadata $joinColumn */
                    $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                    $orderByList[] = $tableAlias . '.' . $quotedColumnName . ' ' . $orientation;
                }

                continue;
            }

            throw UnrecognizedField::byName($fieldName);
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
     *
     * @return string The SQL fragment.
     */
    protected function getSelectColumnsSQL()
    {
        if ($this->currentPersisterContext->selectColumnListSql !== null) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $this->currentPersisterContext->rsm->addEntityResult($this->class->getClassName(), 'r'); // r for root
        $this->currentPersisterContext->selectJoinSql = '';

        $eagerAliasCounter = 0;
        $columnList        = [];

        foreach ($this->class->getPropertiesIterator() as $fieldName => $property) {
            switch (true) {
                case $property instanceof FieldMetadata:
                    $columnList[] = $this->getSelectColumnSQL($fieldName, $this->class);
                    break;

                case $property instanceof AssociationMetadata:
                    $assocColumnSQL = $this->getSelectColumnAssociationSQL($fieldName, $property, $this->class);

                    if ($assocColumnSQL) {
                        $columnList[] = $assocColumnSQL;
                    }

                    $isAssocToOneInverseSide = $property instanceof ToOneAssociationMetadata && ! $property->isOwningSide();
                    $isAssocFromOneEager     = ! $property instanceof ManyToManyAssociationMetadata && $property->getFetchMode() === FetchMode::EAGER;

                    if (! ($isAssocFromOneEager || $isAssocToOneInverseSide)) {
                        break;
                    }

                    if ($property instanceof ToManyAssociationMetadata && $this->currentPersisterContext->handlesLimits) {
                        break;
                    }

                    $targetEntity = $property->getTargetEntity();
                    $eagerEntity  = $this->em->getClassMetadata($targetEntity);

                    if ($eagerEntity->inheritanceType !== InheritanceType::NONE) {
                        break; // now this is why you shouldn't use inheritance
                    }

                    $assocAlias = 'e' . ($eagerAliasCounter++);

                    $this->currentPersisterContext->rsm->addJoinedEntityResult($targetEntity, $assocAlias, 'r', $fieldName);

                    foreach ($eagerEntity->getPropertiesIterator() as $eagerProperty) {
                        switch (true) {
                            case $eagerProperty instanceof FieldMetadata:
                                $columnList[] = $this->getSelectColumnSQL($eagerProperty->getName(), $eagerEntity, $assocAlias);
                                break;

                            case $eagerProperty instanceof ToOneAssociationMetadata && $eagerProperty->isOwningSide():
                                $columnList[] = $this->getSelectColumnAssociationSQL(
                                    $eagerProperty->getName(),
                                    $eagerProperty,
                                    $eagerEntity,
                                    $assocAlias
                                );
                                break;
                        }
                    }

                    $owningAssociation = $property;
                    $joinCondition     = [];

                    if ($property instanceof ToManyAssociationMetadata && $property->getIndexedBy()) {
                        $this->currentPersisterContext->rsm->addIndexBy($assocAlias, $property->getIndexedBy());
                    }

                    if (! $property->isOwningSide()) {
                        $owningAssociation = $eagerEntity->getProperty($property->getMappedBy());
                    }

                    $joinTableAlias = $this->getSQLTableAlias($eagerEntity->getTableName(), $assocAlias);
                    $joinTableName  = $eagerEntity->table->getQuotedQualifiedName($this->platform);

                    $this->currentPersisterContext->selectJoinSql .= ' ' . $this->getJoinSQLForAssociation($property);

                    $sourceClass      = $this->em->getClassMetadata($owningAssociation->getSourceEntity());
                    $targetClass      = $this->em->getClassMetadata($owningAssociation->getTargetEntity());
                    $targetTableAlias = $this->getSQLTableAlias($targetClass->getTableName(), $property->isOwningSide() ? $assocAlias : '');
                    $sourceTableAlias = $this->getSQLTableAlias($sourceClass->getTableName(), $property->isOwningSide() ? '' : $assocAlias);

                    foreach ($owningAssociation->getJoinColumns() as $joinColumn) {
                        $joinCondition[] = sprintf(
                            '%s.%s = %s.%s',
                            $sourceTableAlias,
                            $this->platform->quoteIdentifier($joinColumn->getColumnName()),
                            $targetTableAlias,
                            $this->platform->quoteIdentifier($joinColumn->getReferencedColumnName())
                        );
                    }

                    $filterSql = $this->generateFilterConditionSQL($eagerEntity, $targetTableAlias);

                    // Add filter SQL
                    if ($filterSql) {
                        $joinCondition[] = $filterSql;
                    }

                    $this->currentPersisterContext->selectJoinSql .= ' ' . $joinTableName . ' ' . $joinTableAlias . ' ON ';
                    $this->currentPersisterContext->selectJoinSql .= implode(' AND ', $joinCondition);

                    break;
            }
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /**
     * Gets the SQL join fragment used when selecting entities from an association.
     *
     * @param string $field
     * @param string $alias
     *
     * @return string
     */
    protected function getSelectColumnAssociationSQL($field, AssociationMetadata $association, ClassMetadata $class, $alias = 'r')
    {
        if (! ($association->isOwningSide() && $association instanceof ToOneAssociationMetadata)) {
            return '';
        }

        $columnList    = [];
        $targetClass   = $this->em->getClassMetadata($association->getTargetEntity());
        $sqlTableAlias = $this->getSQLTableAlias($class->getTableName(), ($alias === 'r' ? '' : $alias));

        foreach ($association->getJoinColumns() as $joinColumn) {
            /** @var JoinColumnMetadata $joinColumn */
            $columnName       = $joinColumn->getColumnName();
            $quotedColumnName = $this->platform->quoteIdentifier($columnName);
            $resultColumnName = $this->getSQLColumnAlias();

            $this->currentPersisterContext->rsm->addMetaResult(
                $alias,
                $resultColumnName,
                $columnName,
                $association->isPrimaryKey(),
                $joinColumn->getType()
            );

            $columnList[] = sprintf('%s.%s AS %s', $sqlTableAlias, $quotedColumnName, $resultColumnName);
        }

        return implode(', ', $columnList);
    }

    /**
     * Gets the SQL join fragment used when selecting entities from a
     * many-to-many association.
     *
     * @return string
     */
    protected function getSelectManyToManyJoinSQL(ManyToManyAssociationMetadata $association)
    {
        $conditions        = [];
        $owningAssociation = $association;
        $sourceTableAlias  = $this->getSQLTableAlias($this->class->getTableName());

        if (! $association->isOwningSide()) {
            $targetEntity      = $this->em->getClassMetadata($association->getTargetEntity());
            $owningAssociation = $targetEntity->getProperty($association->getMappedBy());
        }

        $joinTable     = $owningAssociation->getJoinTable();
        $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
        $joinColumns   = $association->isOwningSide()
            ? $joinTable->getInverseJoinColumns()
            : $joinTable->getJoinColumns();

        foreach ($joinColumns as $joinColumn) {
            $conditions[] = sprintf(
                '%s.%s = %s.%s',
                $sourceTableAlias,
                $this->platform->quoteIdentifier($joinColumn->getReferencedColumnName()),
                $joinTableName,
                $this->platform->quoteIdentifier($joinColumn->getColumnName())
            );
        }

        return ' INNER JOIN ' . $joinTableName . ' ON ' . implode(' AND ', $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function getInsertSQL()
    {
        if ($this->insertSql !== null) {
            return $this->insertSql;
        }

        $columns   = $this->getInsertColumnList();
        $tableName = $this->class->table->getQuotedQualifiedName($this->platform);

        if (empty($columns)) {
            $property       = $this->class->getProperty($this->class->identifier[0]);
            $identityColumn = $this->platform->quoteIdentifier($property->getColumnName());

            $this->insertSql = $this->platform->getEmptyIdentityInsertSQL($tableName, $identityColumn);

            return $this->insertSql;
        }

        $quotedColumns = [];
        $values        = [];

        foreach ($columns as $columnName => $columnMetadata) {
            // @todo guilhermeblanco Introduce @ColumnTransformer->write value here
            // A ColumnTransformer mapping applies conversion read and write SQL when reading and writing data into DB.
            // We should better manage this decoupling. An AttributeConverter is what our current DBAL\Type refers:
            // - convertToDatabaseColumn(mixed)
            // - convertToEntityAttribute(string)
            $quotedColumns[] = $this->platform->quoteIdentifier($columnMetadata->getColumnName());
            $values[]        = $columnMetadata->getType()->convertToDatabaseValueSQL('?', $this->platform);
        }

        $quotedColumns = implode(', ', $quotedColumns);
        $values        = implode(', ', $values);

        $this->insertSql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $tableName, $quotedColumns, $values);

        return $this->insertSql;
    }

    /**
     * Gets the list of columns to put in the INSERT SQL statement.
     *
     * Subclasses should override this method to alter or change the list of
     * columns placed in the INSERT statements used by the persister.
     *
     * @return ColumnMetadata[] The list of insert columns.
     */
    protected function getInsertColumnList()
    {
        if ($this->insertColumns !== null) {
            return $this->insertColumns;
        }

        return $this->insertColumns = array_filter(
            $this->getColumnList($this->class),
            static function (ColumnMetadata $columnMetadata) {
                // Check for Column insertability
                return true;
            }
        );
    }

    /**
     * Gets the list of columns to be evaluated in the UPDATE SQL statement.
     *
     * Subclasses should override this method to alter or change the list of
     * columns placed in the UPDATE statements used by the persister.
     *
     * @return ColumnMetadata[] The list of update columns.
     */
    protected function getUpdateColumnList()
    {
        if ($this->updateColumns !== null) {
            return $this->updateColumns;
        }

        return $this->updateColumns = array_filter(
            $this->getColumnList($this->class),
            static function (ColumnMetadata $columnMetadata) {
                // Check for Column updatability
                return true;
            }
        );
    }

    /**
     * @return array<string, ColumnMetadata>
     */
    protected function getColumnList(ComponentMetadata $class, string $columnPrefix = '') : array
    {
        $columns = [];

        foreach ($class->getPropertiesIterator() as $name => $property) {
            $columns[] = $this->getPropertyColumnList($property, $columnPrefix);
        }

        return array_merge(...$columns);
    }

    /**
     * @return array<string, ColumnMetadata>
     */
    protected function getPropertyColumnList(Property $property, string $columnPrefix = '') : array
    {
        $columns = [];

        switch (true) {
            case $property instanceof FieldMetadata && $property->isVersioned():
                // Do nothing
                break;

            case $property instanceof LocalColumnMetadata:
                if (! $property->hasValueGenerator() || $property->getValueGenerator()->getType() !== GeneratorType::IDENTITY) {
                    $columns[sprintf('%s%s', $columnPrefix, $property->getColumnName())] = $property;
                }

                break;

            case $property instanceof EmbeddedMetadata:
                $targetClass = $this->em->getClassMetadata($property->getTargetEntity());
                $columns     = array_merge(
                    $columns,
                    $this->getColumnList(
                        $targetClass,
                        sprintf('%s%s', $columnPrefix, $property->getColumnPrefix())
                    )
                );

                break;

            case $property instanceof AssociationMetadata:
                if ($property->isOwningSide() && $property instanceof ToOneAssociationMetadata) {
                    foreach ($property->getJoinColumns() as $joinColumn) {
                        $columns[sprintf('%s%s', $columnPrefix, $joinColumn->getColumnName())] = $joinColumn;
                    }
                }

                break;
        }

        return $columns;
    }

    /**
     * Gets the SQL snippet of a qualified column name for the given field name.
     *
     * @param string        $field The field name.
     * @param ClassMetadata $class The class that declares this field. The table this class is
     *                             mapped to must own the column for the given field.
     * @param string        $alias
     *
     * @return string
     */
    protected function getSelectColumnSQL($field, ClassMetadata $class, $alias = 'r')
    {
        $property    = $class->getProperty($field);
        $columnAlias = $this->getSQLColumnAlias();
        $sql         = sprintf(
            '%s.%s',
            $this->getSQLTableAlias($property->getTableName(), ($alias === 'r' ? '' : $alias)),
            $this->platform->quoteIdentifier($property->getColumnName())
        );

        $this->currentPersisterContext->rsm->addFieldResult($alias, $columnAlias, $field, $class->getClassName());

        return $property->getType()->convertToPHPValueSQL($sql, $this->platform) . ' AS ' . $columnAlias;
    }

    /**
     * Gets the SQL table alias for the given class name.
     *
     * @param string $tableName
     * @param string $assocName
     *
     * @return string The SQL table alias.
     */
    protected function getSQLTableAlias($tableName, $assocName = '')
    {
        if ($tableName) {
            $tableName .= '#' . $assocName;
        }

        if (isset($this->currentPersisterContext->sqlTableAliases[$tableName])) {
            return $this->currentPersisterContext->sqlTableAliases[$tableName];
        }

        $tableAlias = 't' . $this->currentPersisterContext->sqlAliasCounter++;

        $this->currentPersisterContext->sqlTableAliases[$tableName] = $tableAlias;

        return $tableAlias;
    }

    /**
     * {@inheritdoc}
     */
    public function lock(array $criteria, $lockMode)
    {
        $lockSql      = '';
        $conditionSql = $this->getSelectConditionSQL($criteria);

        switch ($lockMode) {
            case LockMode::PESSIMISTIC_READ:
                $lockSql = $this->platform->getReadLockSQL();

                break;
            case LockMode::PESSIMISTIC_WRITE:
                $lockSql = $this->platform->getWriteLockSQL();
                break;
        }

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
     * @param int $lockMode One of the Doctrine\DBAL\LockMode::* constants.
     *
     * @return string
     */
    protected function getLockTablesSql($lockMode)
    {
        $tableName = $this->class->table->getQuotedQualifiedName($this->platform);

        return $this->platform->appendLockHint(
            'FROM ' . $tableName . ' ' . $this->getSQLTableAlias($this->class->getTableName()),
            $lockMode
        );
    }

    /**
     * Gets the Select Where Condition from a Criteria object.
     *
     * @return string
     */
    protected function getSelectConditionCriteriaSQL(Criteria $criteria)
    {
        $expression = $criteria->getWhereExpression();

        if ($expression === null) {
            return '';
        }

        $visitor = new SqlExpressionVisitor($this, $this->class);

        return $visitor->dispatch($expression);
    }

    /**
     * {@inheritdoc}
     */
    public function getSelectConditionStatementSQL(
        $field,
        $value,
        ?AssociationMetadata $association = null,
        $comparison = null
    ) {
        $selectedColumns = [];
        $columns         = $this->getSelectConditionStatementColumnSQL($field, $association);

        if (in_array($comparison, [Comparison::IN, Comparison::NIN], true) && isset($columns[1])) {
            // @todo try to support multi-column IN expressions. Example: (col1, col2) IN (('val1A', 'val2A'), ...)
            throw CantUseInOperatorOnCompositeKeys::create();
        }

        foreach ($columns as $column) {
            $property    = $this->class->getProperty($field);
            $placeholder = '?';

            if ($property instanceof FieldMetadata) {
                $placeholder = $property->getType()->convertToDatabaseValueSQL($placeholder, $this->platform);
            }

            if ($comparison !== null) {
                // special case null value handling
                if (($comparison === Comparison::EQ || $comparison === Comparison::IS) && $value ===null) {
                    $selectedColumns[] = $column . ' IS NULL';

                    continue;
                }

                if ($comparison === Comparison::NEQ && $value === null) {
                    $selectedColumns[] = $column . ' IS NOT NULL';

                    continue;
                }

                $selectedColumns[] = $column . ' ' . sprintf(self::$comparisonMap[$comparison], $placeholder);

                continue;
            }

            if (is_array($value)) {
                $in = sprintf('%s IN (%s)', $column, $placeholder);

                if (in_array(null, $value, true)) {
                    $selectedColumns[] = sprintf('(%s OR %s IS NULL)', $in, $column);

                    continue;
                }

                $selectedColumns[] = $in;

                continue;
            }

            if ($value === null) {
                $selectedColumns[] = sprintf('%s IS NULL', $column);

                continue;
            }

            $selectedColumns[] = sprintf('%s = %s', $column, $placeholder);
        }

        return implode(' AND ', $selectedColumns);
    }

    /**
     * Builds the left-hand-side of a where condition statement.
     *
     * @param string $field
     *
     * @return string[]
     *
     * @throws ORMException
     */
    private function getSelectConditionStatementColumnSQL($field, ?AssociationMetadata $association = null)
    {
        $property = $this->class->getProperty($field);

        if ($property instanceof FieldMetadata) {
            $tableAlias = $this->getSQLTableAlias($property->getTableName());
            $columnName = $this->platform->quoteIdentifier($property->getColumnName());

            return [$tableAlias . '.' . $columnName];
        }

        if ($property instanceof AssociationMetadata) {
            $owningAssociation = $property;
            $columns           = [];

            // Many-To-Many requires join table check for joinColumn
            if ($owningAssociation instanceof ManyToManyAssociationMetadata) {
                if (! $owningAssociation->isOwningSide()) {
                    $owningAssociation = $association;
                }

                $joinTable     = $owningAssociation->getJoinTable();
                $joinTableName = $joinTable->getQuotedQualifiedName($this->platform);
                $joinColumns   = $association->isOwningSide()
                    ? $joinTable->getJoinColumns()
                    : $joinTable->getInverseJoinColumns();

                foreach ($joinColumns as $joinColumn) {
                    $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                    $columns[] = $joinTableName . '.' . $quotedColumnName;
                }
            } else {
                if (! $owningAssociation->isOwningSide()) {
                    throw InvalidFindByCall::fromInverseSideUsage(
                        $this->class->getClassName(),
                        $field
                    );
                }

                $class      = $this->class->isInheritedProperty($field)
                    ? $owningAssociation->getDeclaringClass()
                    : $this->class;
                $tableAlias = $this->getSQLTableAlias($class->getTableName());

                foreach ($owningAssociation->getJoinColumns() as $joinColumn) {
                    $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());

                    $columns[] = $tableAlias . '.' . $quotedColumnName;
                }
            }

            return $columns;
        }

        // very careless developers could potentially open up this normally hidden api for userland attacks,
        // therefore checking for spaces and function calls which are not allowed.
        // found a join column condition, not really a "field"
        if ($association !== null && strpos($field, ' ') === false && strpos($field, '(') === false) {
            return [$field];
        }

        throw UnrecognizedField::byName($field);
    }

    /**
     * Gets the conditional SQL fragment used in the WHERE clause when selecting
     * entities in this persister.
     *
     * Subclasses are supposed to override this method if they intend to change
     * or alter the criteria by which entities are selected.
     *
     * @param mixed[] $criteria
     *
     * @return string
     */
    protected function getSelectConditionSQL(array $criteria, ?AssociationMetadata $association = null)
    {
        $conditions = [];

        foreach ($criteria as $field => $value) {
            $conditions[] = $this->getSelectConditionStatementSQL($field, $value, $association);
        }

        return implode(' AND ', $conditions);
    }

    /**
     * {@inheritdoc}
     */
    public function getOneToManyCollection(
        OneToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    ) {
        $this->switchPersisterContext($offset, $limit);

        $stmt = $this->getOneToManyStatement($association, $sourceEntity, $offset, $limit);

        return $this->loadArrayFromStatement($association, $stmt);
    }

    /**
     * {@inheritdoc}
     */
    public function loadOneToManyCollection(
        OneToManyAssociationMetadata $association,
        $sourceEntity,
        PersistentCollection $collection
    ) {
        $stmt = $this->getOneToManyStatement($association, $sourceEntity);

        return $this->loadCollectionFromStatement($association, $stmt, $collection);
    }

    /**
     * Builds criteria and execute SQL statement to fetch the one to many entities from.
     *
     * @param object   $sourceEntity
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return Statement
     */
    private function getOneToManyStatement(
        OneToManyAssociationMetadata $association,
        $sourceEntity,
        $offset = null,
        $limit = null
    ) {
        $this->switchPersisterContext($offset, $limit);

        $criteria    = [];
        $parameters  = [];
        $owningAssoc = $this->class->getProperty($association->getMappedBy());
        $sourceClass = $this->em->getClassMetadata($association->getSourceEntity());
        $class       = $owningAssoc->getDeclaringClass();
        $tableAlias  = $this->getSQLTableAlias($class->getTableName());

        foreach ($owningAssoc->getJoinColumns() as $joinColumn) {
            $quotedColumnName = $this->platform->quoteIdentifier($joinColumn->getColumnName());
            $fieldName        = $sourceClass->fieldNames[$joinColumn->getReferencedColumnName()];
            $property         = $sourceClass->getProperty($fieldName);
            $value            = null;

            if ($property instanceof FieldMetadata) {
                $value = $property->getValue($sourceEntity);
            } elseif ($property instanceof AssociationMetadata) {
                $targetClass = $this->em->getClassMetadata($property->getTargetEntity());
                $value       = $property->getValue($sourceEntity);

                $value = $this->em->getUnitOfWork()->getEntityIdentifier($value);
                $value = $value[$targetClass->identifier[0]];
            }

            $criteria[$tableAlias . '.' . $quotedColumnName] = $value;
            $parameters[]                                    = [
                'value' => $value,
                'field' => $fieldName,
                'class' => $sourceClass,
            ];
        }

        $sql              = $this->getSelectSQL($criteria, $association, null, $limit, $offset);
        [$params, $types] = $this->expandToManyParameters($parameters);

        return $this->conn->executeQuery($sql, $params, $types);
    }

    /**
     * {@inheritdoc}
     */
    public function expandParameters($criteria)
    {
        $params = [];
        $types  = [];

        foreach ($criteria as $field => $value) {
            if ($value === null) {
                continue; // skip null values.
            }

            $types  = array_merge($types, $this->getTypes($field, $value, $this->class));
            $params = array_merge($params, $this->getValues($value));
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
     */
    private function expandToManyParameters($criteria)
    {
        $params = [];
        $types  = [];

        foreach ($criteria as $criterion) {
            if ($criterion['value'] === null) {
                continue; // skip null values.
            }

            $types  = array_merge($types, $this->getTypes($criterion['field'], $criterion['value'], $criterion['class']));
            $params = array_merge($params, $this->getValues($criterion['value']));
        }

        return [$params, $types];
    }

    /**
     * Infers field types to be used by parameter type casting.
     *
     * @param string $field
     * @param mixed  $value
     *
     * @return mixed[]
     *
     * @throws QueryException
     */
    private function getTypes($field, $value, ClassMetadata $class)
    {
        $property = $class->getProperty($field);
        $types    = [];

        switch (true) {
            case $property instanceof FieldMetadata:
                $types = array_merge($types, [$property->getType()]);
                break;

            case $property instanceof AssociationMetadata:
                $class = $this->em->getClassMetadata($property->getTargetEntity());

                if (! $property->isOwningSide()) {
                    $property = $class->getProperty($property->getMappedBy());
                }

                $joinColumns = $property instanceof ManyToManyAssociationMetadata
                    ? $property->getJoinTable()->getInverseJoinColumns()
                    : $property->getJoinColumns();

                foreach ($joinColumns as $joinColumn) {
                    $types[] = $joinColumn->getType();
                }

                break;

            default:
                $types[] = null;
                break;
        }

        if (is_array($value)) {
            return array_map(static function ($type) {
                return $type->getBindingType() + Connection::ARRAY_PARAM_OFFSET;
            }, $types);
        }

        return $types;
    }

    /**
     * Retrieves the parameters that identifies a value.
     *
     * @param mixed $value
     *
     * @return mixed[]
     */
    private function getValues($value)
    {
        if (is_array($value)) {
            $newValue = [];

            foreach ($value as $itemValue) {
                $newValue = array_merge($newValue, $this->getValues($itemValue));
            }

            return [$newValue];
        }

        $metadataFactory = $this->em->getMetadataFactory();
        $unitOfWork      = $this->em->getUnitOfWork();

        if (is_object($value) && $metadataFactory->hasMetadataFor(StaticClassNameConverter::getClass($value))) {
            $class     = $metadataFactory->getMetadataFor(get_class($value));
            $persister = $unitOfWork->getEntityPersister($class->getClassName());

            if ($class->isIdentifierComposite()) {
                $newValue = [];

                foreach ($persister->getIdentifier($value) as $innerValue) {
                    $newValue = array_merge($newValue, $this->getValues($innerValue));
                }

                return $newValue;
            }
        }

        return [$this->getIndividualValue($value)];
    }

    /**
     * Retrieves an individual parameter value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function getIndividualValue($value)
    {
        if (! is_object($value) || ! $this->em->getMetadataFactory()->hasMetadataFor(StaticClassNameConverter::getClass($value))) {
            return $value;
        }

        return $this->em->getUnitOfWork()->getSingleIdentifierValue($value);
    }

    /**
     * {@inheritdoc}
     */
    public function exists($entity, ?Criteria $extraConditions = null)
    {
        $criteria = $this->getIdentifier($entity);

        if (! $criteria) {
            return false;
        }

        $alias = $this->getSQLTableAlias($this->class->getTableName());

        $sql = 'SELECT 1 '
             . $this->getLockTablesSql(null)
             . ' WHERE ' . $this->getSelectConditionSQL($criteria);

        [$params, $types] = $this->expandParameters($criteria);

        if ($extraConditions !== null) {
            $sql                             .= ' AND ' . $this->getSelectConditionCriteriaSQL($extraConditions);
            [$criteriaParams, $criteriaTypes] = $this->expandCriteriaParameters($extraConditions);

            $params = array_merge($params, $criteriaParams);
            $types  = array_merge($types, $criteriaTypes);
        }

        $filterSql = $this->generateFilterConditionSQL($this->class, $alias);

        if ($filterSql) {
            $sql .= ' AND ' . $filterSql;
        }

        return (bool) $this->conn->fetchColumn($sql, $params, $types);
    }

    /**
     * Generates the appropriate join SQL for the given association.
     *
     * @return string LEFT JOIN if one of the columns is nullable, INNER JOIN otherwise.
     */
    protected function getJoinSQLForAssociation(AssociationMetadata $association)
    {
        if (! $association->isOwningSide()) {
            return 'LEFT JOIN';
        }

        // if one of the join columns is nullable, return left join
        foreach ($association->getJoinColumns() as $joinColumn) {
            if (! $joinColumn->isNullable()) {
                continue;
            }

            return 'LEFT JOIN';
        }

        return 'INNER JOIN';
    }

    /**
     * Gets an SQL column alias for a column name.
     *
     * @return string
     */
    public function getSQLColumnAlias()
    {
        return $this->platform->getSQLResultCasing('c' . $this->currentPersisterContext->sqlAliasCounter++);
    }

    /**
     * Generates the filter SQL for a given entity and table alias.
     *
     * @param ClassMetadata $targetEntity     Metadata of the target entity.
     * @param string        $targetTableAlias The table alias of the joined/selected table.
     *
     * @return string The SQL query part to add to a query.
     */
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, $targetTableAlias)
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
     *
     * @param int|null $offset
     * @param int|null $limit
     */
    protected function switchPersisterContext($offset, $limit)
    {
        if ($offset === null && $limit === null) {
            $this->currentPersisterContext = $this->noLimitsContext;

            return;
        }

        $this->currentPersisterContext = $this->limitsHandlingContext;
    }
}
