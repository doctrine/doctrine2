<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Collection;

use BadMethodCallException;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Cache\Persister\CompatOrderings;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\InverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\SqlValueVisitor;
use Doctrine\ORM\Query;
use Doctrine\ORM\Utility\PersisterHelper;
use SortDirection;

use function array_fill;
use function array_merge;
use function array_pop;
use function assert;
use function count;
use function implode;
use function in_array;
use function reset;
use function sprintf;
use function substr;

/**
 * Persister for many-to-many collections.
 */
class ManyToManyPersister extends AbstractCollectionPersister
{
    use CompatOrderings;

    /**
     * Loads a batch of collections whose owners are already managed.
     *
     * @internal
     *
     * @param array<array-key, PersistentCollection<array-key, object>> $collections
     */
    public function loadCollections(array $collections, AssociationMapping&ManyToManyAssociationMapping $mapping): void
    {
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $owningSide  = $this->em->getMetadataFactory()->getOwningSide($mapping);
        $joinColumns = $mapping->isOwningSide()
            ? $owningSide->joinTable->joinColumns
            : $owningSide->joinTable->inverseJoinColumns;
        $joinColumn  = $joinColumns[0];
        $joinTable   = $this->quoteStrategy->getJoinTableName($owningSide, $targetClass, $this->platform);
        $ownerColumn = $joinTable . '.' . $this->quoteStrategy->getJoinColumnName($joinColumn, $sourceClass, $this->platform);
        $field       = $sourceClass->identifier[0];
        $typeName    = $sourceClass->fieldMappings[$field]->type;
        $type        = Type::getType($typeName);
        $ids         = [];

        foreach ($collections as $collection) {
            $ids[] = $this->uow->getEntityIdentifier($collection->getOwner())[$field];
        }

        $persister  = $this->uow->getEntityPersister($mapping->targetEntity);
        $sql        = $persister->getSelectSQL([$ownerColumn => $ids], $mapping);
        $rsm        = clone $persister->getResultSetMapping();
        $ownerAlias = $this->quoteStrategy->getColumnAlias('collection_owner', 0, $this->platform);

        // Hydrate the targets as a fetch join onto owners already in the identity map.
        // Selecting only their ID avoids repeating the owners' fields for every target.
        $rsm->addEntityResult($mapping->sourceEntity, 'collection_owner');
        $rsm->addFieldResult('collection_owner', $ownerAlias, $field);
        $rsm->addJoinedEntityResult($mapping->targetEntity, 'r', 'collection_owner', $mapping->fieldName);
        $sql = 'SELECT ' . $type->convertToPHPValueSQL($ownerColumn, $this->platform)
            . ' AS ' . $ownerAlias . ', ' . substr($sql, 7);

        $stmt = $this->conn->executeQuery($sql, $ids, array_fill(0, count($ids), $typeName));

        $this->em->newHydrator(Query::HYDRATE_OBJECT)->hydrateAll($stmt, $rsm);
    }

    public function delete(PersistentCollection $collection): void
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isOwningSide()) {
            return; // ignore inverse side
        }

        assert($mapping->isManyToManyOwningSide());

        $types = [];
        $class = $this->em->getClassMetadata($mapping->sourceEntity);

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $types[] = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $class, $this->em);
        }

        $this->conn->executeStatement($this->getDeleteSQL($collection), $this->getDeleteSQLParameters($collection), $types);
    }

    public function update(PersistentCollection $collection): void
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isOwningSide()) {
            return; // ignore inverse side
        }

        [$deleteSql, $deleteTypes] = $this->getDeleteRowSQL($collection);
        [$insertSql, $insertTypes] = $this->getInsertRowSQL($collection);

        foreach ($collection->getDeleteDiff() as $element) {
            $this->conn->executeStatement(
                $deleteSql,
                $this->getDeleteRowSQLParameters($collection, $element),
                $deleteTypes,
            );
        }

        foreach ($collection->getInsertDiff() as $element) {
            $this->conn->executeStatement(
                $insertSql,
                $this->getInsertRowSQLParameters($collection, $element),
                $insertTypes,
            );
        }
    }

    public function get(PersistentCollection $collection, mixed $index): object|null
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        $persister = $this->uow->getEntityPersister($mapping->targetEntity);
        $mappedKey = $mapping->isOwningSide()
            ? $mapping->inversedBy
            : $mapping->mappedBy;

        assert($mappedKey !== null);

        return $persister->load(
            [$mappedKey => $collection->getOwner(), $mapping->indexBy() => $index],
            null,
            $mapping,
            [],
            LockMode::NONE,
            1,
        );
    }

    /** @return non-negative-int */
    public function count(PersistentCollection $collection): int
    {
        $conditions  = [];
        $params      = [];
        $types       = [];
        $mapping     = $this->getMapping($collection);
        $id          = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $association = $this->em->getMetadataFactory()->getOwningSide($mapping);

        $joinTableName = $this->quoteStrategy->getJoinTableName($association, $sourceClass, $this->platform);
        $joinColumns   = ! $mapping->isOwningSide()
            ? $association->joinTable->inverseJoinColumns
            : $association->joinTable->joinColumns;

        foreach ($joinColumns as $joinColumn) {
            $columnName     = $this->quoteStrategy->getJoinColumnName($joinColumn, $sourceClass, $this->platform);
            $referencedName = $joinColumn->referencedColumnName;
            $conditions[]   = 't.' . $columnName . ' = ?';
            $params[]       = $id[$sourceClass->getFieldForColumn($referencedName)];
            $types[]        = PersisterHelper::getTypeOfColumn($referencedName, $sourceClass, $this->em);
        }

        [$joinTargetEntitySQL, $filterSql] = $this->getFilterSql($mapping);

        if ($filterSql) {
            $conditions[] = $filterSql;
        }

        // If there is a provided criteria, make part of conditions
        // @todo Fix this. Current SQL returns something like:
        /*if ($criteria && ($expression = $criteria->getWhereExpression()) !== null) {
            // A join is needed on the target entity
            $targetTableName = $this->quoteStrategy->getTableName($targetClass, $this->platform);
            $targetJoinSql   = ' JOIN ' . $targetTableName . ' te'
                . ' ON' . implode(' AND ', $this->getOnConditionSQL($association));

            // And criteria conditions needs to be added
            $persister    = $this->uow->getEntityPersister($targetClass->name);
            $visitor      = new SqlExpressionVisitor($persister, $targetClass);
            $conditions[] = $visitor->dispatch($expression);

            $joinTargetEntitySQL = $targetJoinSql . $joinTargetEntitySQL;
        }*/

        $sql = 'SELECT COUNT(*)'
            . ' FROM ' . $joinTableName . ' t'
            . $joinTargetEntitySQL
            . ' WHERE ' . implode(' AND ', $conditions);

        return (int) $this->conn->fetchOne($sql, $params, $types);
    }

    /**
     * {@inheritDoc}
     */
    public function slice(PersistentCollection $collection, int $offset, int|null $length = null): array
    {
        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->getManyToManyCollection($mapping, $collection->getOwner(), $offset, $length);
    }

    public function containsKey(PersistentCollection $collection, mixed $key): bool
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        [$quotedJoinTable, $whereClauses, $params, $types] = $this->getJoinTableRestrictionsWithKey(
            $collection,
            (string) $key,
            true,
        );

        $sql = 'SELECT 1 FROM ' . $quotedJoinTable . ' WHERE ' . implode(' AND ', $whereClauses);

        return (bool) $this->conn->fetchOne($sql, $params, $types);
    }

    public function contains(PersistentCollection $collection, object $element): bool
    {
        if (! $this->isValidEntityState($element)) {
            return false;
        }

        [$quotedJoinTable, $whereClauses, $params, $types] = $this->getJoinTableRestrictions(
            $collection,
            $element,
            true,
        );

        $sql = 'SELECT 1 FROM ' . $quotedJoinTable . ' WHERE ' . implode(' AND ', $whereClauses);

        return (bool) $this->conn->fetchOne($sql, $params, $types);
    }

    /**
     * {@inheritDoc}
     */
    public function loadCriteria(PersistentCollection $collection, Criteria $criteria): array
    {
        $mapping       = $this->getMapping($collection);
        $owner         = $collection->getOwner();
        $ownerMetadata = $this->em->getClassMetadata($owner::class);
        $id            = $this->uow->getEntityIdentifier($owner);
        $targetClass   = $this->em->getClassMetadata($mapping->targetEntity);
        $onConditions  = $this->getOnConditionSQL($mapping);
        $whereClauses  = $params = [];
        $paramTypes    = [];

        if (! $mapping->isOwningSide()) {
            assert($mapping instanceof InverseSideMapping);
            $associationSourceClass = $targetClass;
            $sourceRelationMode     = 'relationToTargetKeyColumns';
        } else {
            $associationSourceClass = $ownerMetadata;
            $sourceRelationMode     = 'relationToSourceKeyColumns';
        }

        $mapping = $this->em->getMetadataFactory()->getOwningSide($mapping);

        foreach ($mapping->$sourceRelationMode as $key => $value) {
            $whereClauses[] = sprintf('t.%s = ?', $key);
            $params[]       = $ownerMetadata->containsForeignIdentifier
                ? $id[$ownerMetadata->getFieldForColumn($value)]
                : $id[$ownerMetadata->fieldNames[$value]];
            $paramTypes[]   = PersisterHelper::getTypeOfColumn($value, $ownerMetadata, $this->em);
        }

        $parameters = $this->expandCriteriaParameters($criteria);

        foreach ($parameters as $parameter) {
            [$name, $value, $operator] = $parameter;

            $field = $this->quoteStrategy->getColumnName($name, $targetClass, $this->platform);

            if ($value === null && ($operator === Comparison::EQ || $operator === Comparison::NEQ)) {
                $whereClauses[] = sprintf('te.%s %s NULL', $field, $operator === Comparison::EQ ? 'IS' : 'IS NOT');
            } elseif ($operator === Comparison::IN || $operator === Comparison::NIN) {
                $whereClauses[] = sprintf('te.%s %s (%s)', $field, $operator === Comparison::IN ? 'IN' : 'NOT IN', implode(', ', array_fill(0, count($value), '?')));
                foreach ($value as $item) {
                    $params     = array_merge($params, PersisterHelper::convertToParameterValue($item, $this->em));
                    $paramTypes = array_merge($paramTypes, PersisterHelper::inferParameterTypes($name, $item, $targetClass, $this->em));
                }
            } else {
                $whereClauses[] = sprintf('te.%s %s ?', $field, $operator);

                $params     = [...$params, ...PersisterHelper::convertToParameterValue($value, $this->em)];
                $paramTypes = [...$paramTypes, ...PersisterHelper::inferParameterTypes($name, $value, $targetClass, $this->em)];
            }
        }

        $tableName = $this->quoteStrategy->getTableName($targetClass, $this->platform);
        $joinTable = $this->quoteStrategy->getJoinTableName($mapping, $associationSourceClass, $this->platform);

        $rsm = new Query\ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata($targetClass->name, 'te');

        $sql = 'SELECT ' . $rsm->generateSelectClause()
            . ' FROM ' . $tableName . ' te'
            . ' JOIN ' . $joinTable . ' t ON'
            . implode(' AND ', $onConditions)
            . ' WHERE ' . implode(' AND ', $whereClauses);

        $sql .= $this->getOrderingSql($criteria, $targetClass);

        $sql .= $this->getLimitSql($criteria);

        $stmt = $this->conn->executeQuery($sql, $params, $paramTypes);

        return $this
            ->em
            ->newHydrator(Query::HYDRATE_OBJECT)
            ->hydrateAll($stmt, $rsm);
    }

    /**
     * Generates the filter SQL for a given mapping.
     *
     * This method is not used for actually grabbing the related entities
     * but when the extra-lazy collection methods are called on a filtered
     * association. This is why besides the many to many table we also
     * have to join in the actual entities table leading to additional
     * JOIN.
     *
     * @param AssociationMapping $mapping Array containing mapping information.
     *
     * @return string[] ordered tuple:
     *                   - JOIN condition to add to the SQL
     *                   - WHERE condition to add to the SQL
     * @phpstan-return array{0: string, 1: string}
     */
    public function getFilterSql(AssociationMapping $mapping): array
    {
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $rootClass   = $this->em->getClassMetadata($targetClass->rootEntityName);
        $filterSql   = $this->generateFilterConditionSQL($rootClass, 'te');

        if ($filterSql === '') {
            return ['', ''];
        }

        // A join is needed if there is filtering on the target entity
        $tableName = $this->quoteStrategy->getTableName($rootClass, $this->platform);
        $joinSql   = ' JOIN ' . $tableName . ' te'
            . ' ON' . implode(' AND ', $this->getOnConditionSQL($mapping));

        return [$joinSql, $filterSql];
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
            if ($filterExpr) {
                $filterClauses[] = '(' . $filterExpr . ')';
            }
        }

        return $filterClauses
            ? '(' . implode(' AND ', $filterClauses) . ')'
            : '';
    }

    /**
     * Generate ON condition
     *
     * @return string[]
     * @phpstan-return list<string>
     */
    protected function getOnConditionSQL(AssociationMapping $mapping): array
    {
        $association = $this->em->getMetadataFactory()->getOwningSide($mapping);
        $joinColumns = $mapping->isOwningSide()
            ? $association->joinTable->inverseJoinColumns
            : $association->joinTable->joinColumns;

        $conditions = [];

        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        foreach ($joinColumns as $joinColumn) {
            $joinColumnName = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $refColumnName  = $this->quoteStrategy->getReferencedJoinColumnName($joinColumn, $targetClass, $this->platform);

            $conditions[] = ' t.' . $joinColumnName . ' = te.' . $refColumnName;
        }

        return $conditions;
    }

    protected function getDeleteSQL(PersistentCollection $collection): string
    {
        $columns = [];
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $class     = $this->em->getClassMetadata($collection->getOwner()::class);
        $joinTable = $this->quoteStrategy->getJoinTableName($mapping, $class, $this->platform);

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
        }

        return 'DELETE FROM ' . $joinTable
            . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?';
    }

    /**
     * Internal note: Order of the parameters must be the same as the order of the columns in getDeleteSql.
     *
     * @return list<mixed>
     */
    protected function getDeleteSQLParameters(PersistentCollection $collection): array
    {
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $identifier = $this->uow->getEntityIdentifier($collection->getOwner());

        // Optimization for single column identifier
        if (count($mapping->relationToSourceKeyColumns) === 1) {
            return [reset($identifier)];
        }

        // Composite identifier
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $params      = [];

        foreach ($mapping->relationToSourceKeyColumns as $columnName => $refColumnName) {
            $params[] = isset($sourceClass->fieldNames[$refColumnName])
                ? $identifier[$sourceClass->fieldNames[$refColumnName]]
                : $identifier[$sourceClass->getFieldForColumn($refColumnName)];
        }

        return $params;
    }

    /**
     * Gets the SQL statement used for deleting a row from the collection.
     *
     * @return string[]|string[][] ordered tuple containing the SQL to be executed and an array
     *                             of types for bound parameters
     * @phpstan-return array{0: string, 1: list<string>}
     */
    protected function getDeleteRowSQL(PersistentCollection $collection): array
    {
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $class       = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $columns     = [];
        $types       = [];

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $class, $this->em);
        }

        foreach ($mapping->joinTable->inverseJoinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);
        }

        return [
            'DELETE FROM ' . $this->quoteStrategy->getJoinTableName($mapping, $class, $this->platform)
            . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?',
            $types,
        ];
    }

    /**
     * Gets the SQL parameters for the corresponding SQL statement to delete the given
     * element from the given collection.
     *
     * Internal note: Order of the parameters must be the same as the order of the columns in getDeleteRowSql.
     *
     * @return mixed[]
     * @phpstan-return list<mixed>
     */
    protected function getDeleteRowSQLParameters(PersistentCollection $collection, object $element): array
    {
        return $this->collectJoinTableColumnParameters($collection, $element);
    }

    /**
     * Gets the SQL statement used for inserting a row in the collection.
     *
     * @return string[]|string[][] ordered tuple containing the SQL to be executed and an array
     *                             of types for bound parameters
     * @phpstan-return array{0: string, 1: list<string>}
     */
    protected function getInsertRowSQL(PersistentCollection $collection): array
    {
        $columns = [];
        $types   = [];
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $class       = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);

        foreach ($mapping->joinTable->joinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $class, $this->em);
        }

        foreach ($mapping->joinTable->inverseJoinColumns as $joinColumn) {
            $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $types[]   = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);
        }

        return [
            'INSERT INTO ' . $this->quoteStrategy->getJoinTableName($mapping, $class, $this->platform)
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES'
            . ' (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            $types,
        ];
    }

    /**
     * Gets the SQL parameters for the corresponding SQL statement to insert the given
     * element of the given collection into the database.
     *
     * Internal note: Order of the parameters must be the same as the order of the columns in getInsertRowSql.
     *
     * @return mixed[]
     * @phpstan-return list<mixed>
     */
    protected function getInsertRowSQLParameters(PersistentCollection $collection, object $element): array
    {
        return $this->collectJoinTableColumnParameters($collection, $element);
    }

    /**
     * Collects the parameters for inserting/deleting on the join table in the order
     * of the join table columns as specified in ManyToManyMapping#joinTableColumns.
     *
     * @return mixed[]
     * @phpstan-return list<mixed>
     */
    private function collectJoinTableColumnParameters(
        PersistentCollection $collection,
        object $element,
    ): array {
        $params  = [];
        $mapping = $this->getMapping($collection);
        assert($mapping->isManyToManyOwningSide());
        $isComposite = count($mapping->joinTableColumns) > 2;

        $identifier1 = $this->uow->getEntityIdentifier($collection->getOwner());
        $identifier2 = $this->uow->getEntityIdentifier($element);

        $class1 = $class2 = null;
        if ($isComposite) {
            $class1 = $this->em->getClassMetadata($collection->getOwner()::class);
            $class2 = $collection->getTypeClass();
        }

        foreach ($mapping->joinTableColumns as $joinTableColumn) {
            $isRelationToSource = isset($mapping->relationToSourceKeyColumns[$joinTableColumn]);

            if (! $isComposite) {
                $params[] = $isRelationToSource ? array_pop($identifier1) : array_pop($identifier2);

                continue;
            }

            if ($isRelationToSource) {
                $params[] = $identifier1[$class1->getFieldForColumn($mapping->relationToSourceKeyColumns[$joinTableColumn])];

                continue;
            }

            $params[] = $identifier2[$class2->getFieldForColumn($mapping->relationToTargetKeyColumns[$joinTableColumn])];
        }

        return $params;
    }

    /**
     * @param bool $addFilters Whether the filter SQL should be included or not.
     *
     * @return mixed[] ordered vector:
     *                - quoted join table name
     *                - where clauses to be added for filtering
     *                - parameters to be bound for filtering
     *                - types of the parameters to be bound for filtering
     * @phpstan-return array{0: string, 1: list<string>, 2: list<mixed>, 3: list<string>}
     */
    private function getJoinTableRestrictionsWithKey(
        PersistentCollection $collection,
        string $key,
        bool $addFilters,
    ): array {
        $filterMapping = $this->getMapping($collection);
        $mapping       = $filterMapping;
        $indexBy       = $mapping->indexBy();
        $id            = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass   = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass   = $this->em->getClassMetadata($mapping->targetEntity);

        if (! $mapping->isOwningSide()) {
            assert($mapping instanceof InverseSideMapping);
            $associationSourceClass = $this->em->getClassMetadata($mapping->targetEntity);
            $mapping                = $associationSourceClass->associationMappings[$mapping->mappedBy];
            assert($mapping->isManyToManyOwningSide());
            $joinColumns        = $mapping->joinTable->joinColumns;
            $sourceRelationMode = 'relationToTargetKeyColumns';
            $targetRelationMode = 'relationToSourceKeyColumns';
        } else {
            assert($mapping->isManyToManyOwningSide());
            $associationSourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
            $joinColumns            = $mapping->joinTable->inverseJoinColumns;
            $sourceRelationMode     = 'relationToSourceKeyColumns';
            $targetRelationMode     = 'relationToTargetKeyColumns';
        }

        $quotedJoinTable = $this->quoteStrategy->getJoinTableName($mapping, $associationSourceClass, $this->platform) . ' t';
        $whereClauses    = [];
        $params          = [];
        $types           = [];

        $joinNeeded = ! in_array($indexBy, $targetClass->identifier, true);

        if ($joinNeeded) { // extra join needed if indexBy is not a @id
            $joinConditions = [];

            foreach ($joinColumns as $joinTableColumn) {
                $joinConditions[] = 't.' . $joinTableColumn->name . ' = tr.' . $joinTableColumn->referencedColumnName;
            }

            $tableName        = $this->quoteStrategy->getTableName($targetClass, $this->platform);
            $quotedJoinTable .= ' JOIN ' . $tableName . ' tr ON ' . implode(' AND ', $joinConditions);
            $columnName       = $targetClass->getColumnName($indexBy);

            $whereClauses[] = 'tr.' . $columnName . ' = ?';
            $params[]       = $key;
            $types[]        = PersisterHelper::getTypeOfColumn($columnName, $targetClass, $this->em);
        }

        foreach ($mapping->joinTableColumns as $joinTableColumn) {
            if (isset($mapping->{$sourceRelationMode}[$joinTableColumn])) {
                $column         = $mapping->{$sourceRelationMode}[$joinTableColumn];
                $whereClauses[] = 't.' . $joinTableColumn . ' = ?';
                $params[]       = $sourceClass->containsForeignIdentifier
                    ? $id[$sourceClass->getFieldForColumn($column)]
                    : $id[$sourceClass->fieldNames[$column]];
                $types[]        = PersisterHelper::getTypeOfColumn($column, $sourceClass, $this->em);
            } elseif (! $joinNeeded) {
                $column = $mapping->{$targetRelationMode}[$joinTableColumn];

                $whereClauses[] = 't.' . $joinTableColumn . ' = ?';
                $params[]       = $key;
                $types[]        = PersisterHelper::getTypeOfColumn($column, $targetClass, $this->em);
            }
        }

        if ($addFilters) {
            [$joinTargetEntitySQL, $filterSql] = $this->getFilterSql($filterMapping);

            if ($filterSql) {
                $quotedJoinTable .= ' ' . $joinTargetEntitySQL;
                $whereClauses[]   = $filterSql;
            }
        }

        return [$quotedJoinTable, $whereClauses, $params, $types];
    }

    /**
     * @param bool $addFilters Whether the filter SQL should be included or not.
     *
     * @return mixed[] ordered vector:
     *                - quoted join table name
     *                - where clauses to be added for filtering
     *                - parameters to be bound for filtering
     *                - types of the parameters to be bound for filtering
     * @phpstan-return array{0: string, 1: list<string>, 2: list<mixed>, 3: list<string>}
     */
    private function getJoinTableRestrictions(
        PersistentCollection $collection,
        object $element,
        bool $addFilters,
    ): array {
        $filterMapping = $this->getMapping($collection);
        $mapping       = $filterMapping;

        if (! $mapping->isOwningSide()) {
            $sourceClass = $this->em->getClassMetadata($mapping->targetEntity);
            $targetClass = $this->em->getClassMetadata($mapping->sourceEntity);
            $sourceId    = $this->uow->getEntityIdentifier($element);
            $targetId    = $this->uow->getEntityIdentifier($collection->getOwner());
        } else {
            $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
            $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
            $sourceId    = $this->uow->getEntityIdentifier($collection->getOwner());
            $targetId    = $this->uow->getEntityIdentifier($element);
        }

        $mapping = $this->em->getMetadataFactory()->getOwningSide($mapping);

        $quotedJoinTable = $this->quoteStrategy->getJoinTableName($mapping, $sourceClass, $this->platform);
        $whereClauses    = [];
        $params          = [];
        $types           = [];

        foreach ($mapping->joinTableColumns as $joinTableColumn) {
            $whereClauses[] = ($addFilters ? 't.' : '') . $joinTableColumn . ' = ?';

            if (isset($mapping->relationToTargetKeyColumns[$joinTableColumn])) {
                $targetColumn = $mapping->relationToTargetKeyColumns[$joinTableColumn];
                $params[]     = $targetId[$targetClass->getFieldForColumn($targetColumn)];
                $types[]      = PersisterHelper::getTypeOfColumn($targetColumn, $targetClass, $this->em);

                continue;
            }

            // relationToSourceKeyColumns
            $targetColumn = $mapping->relationToSourceKeyColumns[$joinTableColumn];
            $params[]     = $sourceId[$sourceClass->getFieldForColumn($targetColumn)];
            $types[]      = PersisterHelper::getTypeOfColumn($targetColumn, $sourceClass, $this->em);
        }

        if ($addFilters) {
            $quotedJoinTable .= ' t';

            [$joinTargetEntitySQL, $filterSql] = $this->getFilterSql($filterMapping);

            if ($filterSql) {
                $quotedJoinTable .= ' ' . $joinTargetEntitySQL;
                $whereClauses[]   = $filterSql;
            }
        }

        return [$quotedJoinTable, $whereClauses, $params, $types];
    }

    /**
     * Expands Criteria Parameters by walking the expressions and grabbing all
     * parameters and types from it.
     *
     * @return mixed[][]
     */
    private function expandCriteriaParameters(Criteria $criteria): array
    {
        $expression = $criteria->getWhereExpression();

        if ($expression === null) {
            return [];
        }

        $valueVisitor = new SqlValueVisitor();

        $valueVisitor->dispatch($expression);

        [, $types] = $valueVisitor->getParamsAndTypes();

        return $types;
    }

    private function getOrderingSql(Criteria $criteria, ClassMetadata $targetClass): string
    {
        $orderings = $this->getOrderings($criteria);

        if ($orderings) {
            $orderBy = [];
            foreach ($orderings as $name => $direction) {
                $field = $this->quoteStrategy->getColumnName(
                    $name,
                    $targetClass,
                    $this->platform,
                );

                if ($direction instanceof SortDirection) {
                    $directionString = $direction === SortDirection::Ascending ? 'ASC' : 'DESC';
                } else {
                    /** @phpstan-ignore property.deprecatedEnum */
                    $directionString = $direction->value;
                }

                $orderBy[] = $field . ' ' . $directionString;
            }

            return ' ORDER BY ' . implode(', ', $orderBy);
        }

        return '';
    }

    /** @throws DBALException */
    private function getLimitSql(Criteria $criteria): string
    {
        $limit  = $criteria->getMaxResults();
        $offset = $criteria->getFirstResult();

        return $this->platform->modifyLimitQuery('', $limit, $offset ?? 0);
    }

    private function getMapping(PersistentCollection $collection): AssociationMapping&ManyToManyAssociationMapping
    {
        $mapping = $collection->getMapping();

        assert($mapping instanceof ManyToManyAssociationMapping);

        return $mapping;
    }
}
