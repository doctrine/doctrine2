<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Utility\LockSqlHelper;
use Doctrine\ORM\Utility\PersisterHelper;
use LengthException;

use function array_combine;
use function array_keys;
use function array_values;
use function implode;
use function spl_object_id;

/**
 * The joined subclass persister maps a single entity instance to several tables in the
 * database as it is defined by the <tt>Class Table Inheritance</tt> strategy.
 *
 * @see https://martinfowler.com/eaaCatalog/classTableInheritance.html
 */
class JoinedSubclassPersister extends AbstractEntityInheritancePersister
{
    use LockSqlHelper;
    use SQLResultCasing;

    /**
     * Map that maps column names to the table names that own them.
     * This is mainly a temporary cache, used during a single request.
     *
     * @phpstan-var array<string, string>
     */
    private array $owningTableMap = [];

    /**
     * Map of table to quoted table names.
     *
     * @phpstan-var array<string, string>
     */
    private array $quotedTableMap = [];

    protected function getDiscriminatorColumnTableName(): string
    {
        $class = $this->class->name !== $this->class->rootEntityName
            ? $this->em->getClassMetadata($this->class->rootEntityName)
            : $this->class;

        return $class->getTableName();
    }

    /**
     * This function finds the ClassMetadata instance in an inheritance hierarchy
     * that is responsible for enabling versioning.
     */
    private function getVersionedClassMetadata(): ClassMetadata
    {
        if ($this->class->versionField !== null && isset($this->class->fieldMappings[$this->class->versionField]->inherited)) {
            $definingClassName = $this->class->fieldMappings[$this->class->versionField]->inherited;

            return $this->em->getClassMetadata($definingClassName);
        }

        return $this->class;
    }

    /**
     * Gets the name of the table that owns the column the given field is mapped to.
     */
    public function getOwningTable(string $fieldName): string
    {
        if (isset($this->owningTableMap[$fieldName])) {
            return $this->owningTableMap[$fieldName];
        }

        $cm = match (true) {
            isset($this->class->associationMappings[$fieldName]->inherited)
                => $this->em->getClassMetadata($this->class->associationMappings[$fieldName]->inherited),
            isset($this->class->fieldMappings[$fieldName]->inherited)
                => $this->em->getClassMetadata($this->class->fieldMappings[$fieldName]->inherited),
            default => $this->class,
        };

        $tableName       = $cm->getTableName();
        $quotedTableName = $this->quoteStrategy->getTableName($cm, $this->platform);

        $this->owningTableMap[$fieldName] = $tableName;
        $this->quotedTableMap[$tableName] = $quotedTableName;

        return $tableName;
    }

    public function executeInserts(): void
    {
        if (! $this->queuedInserts) {
            return;
        }

        $uow            = $this->em->getUnitOfWork();
        $idGenerator    = $this->class->idGenerator;
        $isPostInsertId = $idGenerator->isPostInsertGenerator();
        $rootClass      = $this->class->name !== $this->class->rootEntityName
            ? $this->em->getClassMetadata($this->class->rootEntityName)
            : $this->class;

        // Prepare statement for the root table
        $rootPersister = $this->em->getUnitOfWork()->getEntityPersister($rootClass->name);
        $rootTableName = $rootClass->getTableName();
        $rootTableStmt = $this->conn->prepare($rootPersister->getInsertSQL());

        // Prepare statements for sub tables.
        $subTableStmts = [];

        if ($rootClass !== $this->class) {
            $subTableStmts[$this->class->getTableName()] = $this->conn->prepare($this->getInsertSQL());
        }

        foreach ($this->class->parentClasses as $parentClassName) {
            $parentClass     = $this->em->getClassMetadata($parentClassName);
            $parentTableName = $parentClass->getTableName();

            if ($parentClass !== $rootClass) {
                $parentPersister                 = $this->em->getUnitOfWork()->getEntityPersister($parentClassName);
                $subTableStmts[$parentTableName] = $this->conn->prepare($parentPersister->getInsertSQL());
            }
        }

        // Execute all inserts. For each entity:
        // 1) Insert on root table
        // 2) Insert on sub tables
        foreach ($this->queuedInserts as $key => $entity) {
            $insertData = $this->prepareInsertData($entity);

            // Execute insert on root table
            $paramIndex = 1;

            foreach ($insertData[$rootTableName] as $columnName => $value) {
                $rootTableStmt->bindValue($paramIndex++, $value, $this->columnTypes[$columnName]);
            }

            $rootTableStmt->executeStatement();

            if ($isPostInsertId) {
                $generatedId = $idGenerator->generateId($this->em, $entity);
                $id          = [$this->class->identifier[0] => $generatedId];

                $uow->assignPostInsertId($entity, $generatedId);
            } else {
                $id = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
            }

            // Execute inserts on subtables.
            // The order doesn't matter because all child tables link to the root table via FK.
            foreach ($subTableStmts as $tableName => $stmt) {
                $paramIndex = 1;
                $data       = $insertData[$tableName] ?? [];

                foreach ($id as $idName => $idVal) {
                    $type = $this->columnTypes[$idName] ?? Types::STRING;

                    $stmt->bindValue($paramIndex++, $idVal, $type);
                }

                foreach ($data as $columnName => $value) {
                    if (! isset($id[$columnName])) {
                        $stmt->bindValue($paramIndex++, $value, $this->columnTypes[$columnName]);
                    }
                }

                $stmt->executeStatement();
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

    public function update(object $entity): void
    {
        $updateData = $this->prepareUpdateData($entity);

        if (! $updateData) {
            return;
        }

        $isVersioned = $this->class->isVersioned;

        $versionedClass = $this->getVersionedClassMetadata();
        $versionedTable = $versionedClass->getTableName();

        foreach ($updateData as $tableName => $data) {
            $tableName = $this->quotedTableMap[$tableName];
            $versioned = $isVersioned && $versionedTable === $tableName;

            $this->updateTable($entity, $tableName, $data, $versioned);
        }

        if ($this->class->requiresFetchAfterChange) {
            // Make sure the table with the version column is updated even if no columns on that
            // table were affected.
            if ($isVersioned && ! isset($updateData[$versionedTable])) {
                $tableName = $this->quoteStrategy->getTableName($versionedClass, $this->platform);

                $this->updateTable($entity, $tableName, [], true);
            }

            $identifiers = $this->em->getUnitOfWork()->getEntityIdentifier($entity);

            $this->assignDefaultVersionAndUpsertableValues($entity, $identifiers);
        }
    }

    public function delete(object $entity): bool
    {
        $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
        $id         = array_combine($this->class->getIdentifierColumnNames(), $identifier);
        $types      = $this->getClassIdentifiersTypes($this->class);

        $this->deleteJoinTableRecords($identifier, $types);

        // Delete the row from the root table. Cascades do the rest.
        $rootClass = $this->em->getClassMetadata($this->class->rootEntityName);
        $rootTable = $this->quoteStrategy->getTableName($rootClass, $this->platform);
        $rootTypes = $this->getClassIdentifiersTypes($rootClass);

        return (bool) $this->conn->delete($rootTable, $id, $rootTypes);
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

        $baseTableAlias = $this->getSQLTableAlias($this->class->name);
        $joinSql        = $this->getJoinSql($baseTableAlias);

        if ($assoc !== null && $assoc->isManyToMany()) {
            $joinSql .= $this->getSelectManyToManyJoinSQL($assoc);
        }

        $conditionSql = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria, $assoc);

        $filterSql = $this->generateFilterConditionSQL(
            $this->em->getClassMetadata($this->class->rootEntityName),
            $this->getSQLTableAlias($this->class->rootEntityName),
        );
        // If the current class in the root entity, add the filters
        if ($filterSql) {
            $conditionSql .= $conditionSql
                ? ' AND ' . $filterSql
                : $filterSql;
        }

        $orderBySql = '';

        if ($assoc !== null && $assoc->isOrdered()) {
            $orderBy = $assoc->orderBy();
        }

        if ($orderBy) {
            $orderBySql = $this->getOrderBySQL($orderBy, $baseTableAlias);
        }

        $lockSql = '';

        switch ($lockMode) {
            case LockMode::PESSIMISTIC_READ:
                $lockSql = ' ' . $this->getReadLockSQL($this->platform);

                break;

            case LockMode::PESSIMISTIC_WRITE:
                $lockSql = ' ' . $this->getWriteLockSQL($this->platform);

                break;
        }

        $tableName  = $this->quoteStrategy->getTableName($this->class, $this->platform);
        $from       = ' FROM ' . $tableName . ' ' . $baseTableAlias;
        $where      = $conditionSql !== '' ? ' WHERE ' . $conditionSql : '';
        $lock       = $this->platform->appendLockHint($from, $lockMode ?? LockMode::NONE);
        $columnList = $this->getSelectColumnsSQL();
        $query      = 'SELECT ' . $columnList
                    . $lock
                    . $joinSql
                    . $where
                    . $orderBySql;

        return $this->platform->modifyLimitQuery($query, $limit, $offset ?? 0) . $lockSql;
    }

    public function getCountSQL(array|Criteria $criteria = []): string
    {
        $tableName      = $this->quoteStrategy->getTableName($this->class, $this->platform);
        $baseTableAlias = $this->getSQLTableAlias($this->class->name);
        $joinSql        = $this->getJoinSql($baseTableAlias);

        $conditionSql = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria);

        $filterSql = $this->generateFilterConditionSQL($this->em->getClassMetadata($this->class->rootEntityName), $this->getSQLTableAlias($this->class->rootEntityName));

        if ($filterSql !== '') {
            $conditionSql = $conditionSql
                ? $conditionSql . ' AND ' . $filterSql
                : $filterSql;
        }

        return 'SELECT COUNT(*) '
            . 'FROM ' . $tableName . ' ' . $baseTableAlias
            . $joinSql
            . (empty($conditionSql) ? '' : ' WHERE ' . $conditionSql);
    }

    protected function getLockTablesSql(LockMode|int $lockMode): string
    {
        $joinSql           = '';
        $identifierColumns = $this->class->getIdentifierColumnNames();
        $baseTableAlias    = $this->getSQLTableAlias($this->class->name);

        // INNER JOIN parent tables
        foreach ($this->class->parentClasses as $parentClassName) {
            $conditions  = [];
            $tableAlias  = $this->getSQLTableAlias($parentClassName);
            $parentClass = $this->em->getClassMetadata($parentClassName);
            $joinSql    .= ' INNER JOIN ' . $this->quoteStrategy->getTableName($parentClass, $this->platform) . ' ' . $tableAlias . ' ON ';

            foreach ($identifierColumns as $idColumn) {
                $conditions[] = $baseTableAlias . '.' . $idColumn . ' = ' . $tableAlias . '.' . $idColumn;
            }

            $joinSql .= implode(' AND ', $conditions);
        }

        return parent::getLockTablesSql($lockMode) . $joinSql;
    }

    /**
     * Ensure this method is never called. This persister overrides getSelectEntitiesSQL directly.
     */
    protected function getSelectColumnsSQL(): string
    {
        // Create the column list fragment only once
        if ($this->currentPersisterContext->selectColumnListSql !== null && $this->isFilterHashUpToDate()) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $columnList       = [];
        $discrColumn      = $this->class->getDiscriminatorColumn();
        $discrColumnName  = $discrColumn->name;
        $discrColumnType  = $discrColumn->type;
        $baseTableAlias   = $this->getSQLTableAlias($this->class->name);
        $resultColumnName = $this->getSQLResultCasing($this->platform, $discrColumnName);

        $this->currentPersisterContext->rsm->addEntityResult($this->class->name, 'r');
        $this->currentPersisterContext->rsm->setDiscriminatorColumn('r', $resultColumnName);
        $this->currentPersisterContext->rsm->addMetaResult('r', $resultColumnName, $discrColumnName, false, $discrColumnType);

        // Add regular columns
        foreach ($this->class->fieldMappings as $fieldName => $mapping) {
            $class = isset($mapping->inherited)
                ? $this->em->getClassMetadata($mapping->inherited)
                : $this->class;

            $columnList[] = $this->getSelectColumnSQL($fieldName, $class);
        }

        // Add foreign key columns
        foreach ($this->class->associationMappings as $mapping) {
            if (! $mapping->isToOneOwningSide()) {
                continue;
            }

            $tableAlias = isset($mapping->inherited)
                ? $this->getSQLTableAlias($mapping->inherited)
                : $baseTableAlias;

            $targetClass = $this->em->getClassMetadata($mapping->targetEntity);

            foreach ($mapping->joinColumns as $joinColumn) {
                $columnList[] = $this->getSelectJoinColumnSQL(
                    $tableAlias,
                    $joinColumn->name,
                    $this->quoteStrategy->getJoinColumnName($joinColumn, $this->class, $this->platform),
                    PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em),
                );
            }
        }

        // Add discriminator column (DO NOT ALIAS, see AbstractEntityInheritancePersister#processSQLResult).
        $tableAlias = $this->class->rootEntityName === $this->class->name
            ? $baseTableAlias
            : $this->getSQLTableAlias($this->class->rootEntityName);

        $columnList[] = $tableAlias . '.' . $discrColumnName;

        // sub tables
        foreach ($this->class->subClasses as $subClassName) {
            $subClass   = $this->em->getClassMetadata($subClassName);
            $tableAlias = $this->getSQLTableAlias($subClassName);

            // Add subclass columns
            foreach ($subClass->fieldMappings as $fieldName => $mapping) {
                if (isset($mapping->inherited)) {
                    continue;
                }

                $columnList[] = $this->getSelectColumnSQL($fieldName, $subClass);
            }

            // Add join columns (foreign keys)
            foreach ($subClass->associationMappings as $mapping) {
                if (! $mapping->isToOneOwningSide() || isset($mapping->inherited)) {
                    continue;
                }

                $targetClass = $this->em->getClassMetadata($mapping->targetEntity);

                foreach ($mapping->joinColumns as $joinColumn) {
                    $columnList[] = $this->getSelectJoinColumnSQL(
                        $tableAlias,
                        $joinColumn->name,
                        $this->quoteStrategy->getJoinColumnName($joinColumn, $subClass, $this->platform),
                        PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em),
                    );
                }
            }
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);
        $this->updateFilterHash();

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /**
     * {@inheritDoc}
     */
    protected function getInsertColumnList(): array
    {
        // Identifier columns must always come first in the column list of subclasses.
        $columns = $this->class->parentClasses
            ? $this->class->getIdentifierColumnNames()
            : [];

        foreach ($this->class->propertyAccessors as $name => $field) {
            if (
                isset($this->class->fieldMappings[$name]->inherited)
                    && ! isset($this->class->fieldMappings[$name]->id)
                    || isset($this->class->associationMappings[$name]->inherited)
                    || ($this->class->isVersioned && $this->class->versionField === $name)
                    || isset($this->class->embeddedClasses[$name])
                    || isset($this->class->fieldMappings[$name]->notInsertable)
            ) {
                continue;
            }

            if (isset($this->class->associationMappings[$name])) {
                $assoc = $this->class->associationMappings[$name];
                if ($assoc->isToOneOwningSide()) {
                    foreach ($assoc->targetToSourceKeyColumns as $sourceCol) {
                        $columns[] = $sourceCol;
                    }
                }
            } elseif (
                $this->class->name !== $this->class->rootEntityName ||
                    ! $this->class->isIdGeneratorIdentity() || $this->class->identifier[0] !== $name
            ) {
                $columns[]                = $this->quoteStrategy->getColumnName($name, $this->class, $this->platform);
                $this->columnTypes[$name] = $this->class->fieldMappings[$name]->type;
            }
        }

        // Add discriminator column if it is the topmost class.
        if ($this->class->name === $this->class->rootEntityName) {
            $columns[] = $this->class->getDiscriminatorColumn()->name;
        }

        return $columns;
    }

    /**
     * {@inheritDoc}
     */
    protected function assignDefaultVersionAndUpsertableValues(object $entity, array $id): void
    {
        $values = $this->fetchVersionAndNotUpsertableValues($this->getVersionedClassMetadata(), $id);
        $uow    = $this->em->getUnitOfWork();
        $oid    = spl_object_id($entity);

        foreach ($values as $field => $value) {
            $value = Type::getType($this->class->fieldMappings[$field]->type)->convertToPHPValue($value, $this->platform);

            $this->class->setFieldValue($entity, $field, $value);
            $uow->setOriginalEntityProperty($oid, $field, $value);
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchVersionAndNotUpsertableValues(ClassMetadata $versionedClass, array $id): mixed
    {
        $columnNames = [];
        foreach ($this->class->fieldMappings as $key => $column) {
            $class = null;
            if ($this->class->isVersioned && $key === $versionedClass->versionField) {
                $class = $versionedClass;
            } elseif (isset($column->generated)) {
                $class = isset($column->inherited)
                    ? $this->em->getClassMetadata($column->inherited)
                    : $this->class;
            } else {
                continue;
            }

            $columnNames[$key] = $this->getSelectColumnSQL($key, $class);
        }

        $tableName      = $this->quoteStrategy->getTableName($versionedClass, $this->platform);
        $baseTableAlias = $this->getSQLTableAlias($this->class->name);
        $joinSql        = $this->getJoinSql($baseTableAlias);
        $identifier     = $this->quoteStrategy->getIdentifierColumnNames($versionedClass, $this->platform);
        foreach ($identifier as $i => $idValue) {
            $identifier[$i] = $baseTableAlias . '.' . $idValue;
        }

        $sql = 'SELECT ' . implode(', ', $columnNames)
            . ' FROM ' . $tableName . ' ' . $baseTableAlias
            . $joinSql
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

    private function getJoinSql(string $baseTableAlias): string
    {
        $joinSql          = '';
        $identifierColumn = $this->class->getIdentifierColumnNames();

        // INNER JOIN parent tables
        foreach ($this->class->parentClasses as $parentClassName) {
            $conditions  = [];
            $parentClass = $this->em->getClassMetadata($parentClassName);
            $tableAlias  = $this->getSQLTableAlias($parentClassName);
            $joinSql    .= ' INNER JOIN ' . $this->quoteStrategy->getTableName($parentClass, $this->platform) . ' ' . $tableAlias . ' ON ';

            foreach ($identifierColumn as $idColumn) {
                $conditions[] = $baseTableAlias . '.' . $idColumn . ' = ' . $tableAlias . '.' . $idColumn;
            }

            $joinSql .= implode(' AND ', $conditions);
        }

        // OUTER JOIN sub tables
        foreach ($this->class->subClasses as $subClassName) {
            $conditions = [];
            $subClass   = $this->em->getClassMetadata($subClassName);
            $tableAlias = $this->getSQLTableAlias($subClassName);
            $joinSql   .= ' LEFT JOIN ' . $this->quoteStrategy->getTableName($subClass, $this->platform) . ' ' . $tableAlias . ' ON ';

            foreach ($identifierColumn as $idColumn) {
                $conditions[] = $baseTableAlias . '.' . $idColumn . ' = ' . $tableAlias . '.' . $idColumn;
            }

            $joinSql .= implode(' AND ', $conditions);
        }

        return $joinSql;
    }
}
