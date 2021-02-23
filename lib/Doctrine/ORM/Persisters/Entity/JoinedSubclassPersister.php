<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ColumnMetadata;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\Query\Parameter;
use function array_combine;
use function array_filter;
use function array_keys;
use function array_merge;
use function implode;

/**
 * The joined subclass persister maps a single entity instance to several tables in the
 * database as it is defined by the <tt>Class Table Inheritance</tt> strategy.
 *
 * @see https://martinfowler.com/eaaCatalog/classTableInheritance.html
 */
class JoinedSubclassPersister extends AbstractEntityInheritancePersister
{
    /**
     * {@inheritdoc}
     */
    public function insert($entity)
    {
        $rootClass      = ! $this->class->isRootEntity()
            ? $this->em->getClassMetadata($this->class->getRootClassName())
            : $this->class;
        $generationPlan = $this->class->getValueGenerationPlan();

        // Prepare statement for the root table
        $rootPersister = $this->em->getUnitOfWork()->getEntityPersister($rootClass->getClassName());
        $rootTableName = $rootClass->getTableName();
        $rootTableStmt = $this->conn->prepare($rootPersister->getInsertSQL());

        // Prepare statements for sub tables.
        $subTableStmts = [];

        if ($rootClass !== $this->class) {
            $subTableStmts[$this->class->getTableName()] = $this->conn->prepare($this->getInsertSQL());
        }

        $parentClass = $this->class;

        while (($parentClass = $parentClass->getParent()) !== null) {
            $parentTableName = $parentClass->getTableName();

            if ($parentClass !== $rootClass) {
                $parentPersister = $this->em->getUnitOfWork()->getEntityPersister($parentClass->getClassName());

                $subTableStmts[$parentTableName] = $this->conn->prepare($parentPersister->getInsertSQL());
            }
        }

        // Execute all inserts. For each entity:
        // 1) Insert on root table
        // 2) Insert on sub tables
        $insertData = $this->prepareInsertData($entity);

        // Execute insert on root table
        foreach ($insertData[$rootTableName] as $paramIndex => $parameter) {
            $rootTableStmt->bindValue($paramIndex + 1, $parameter->getValue(), $parameter->getType());
        }

        $rootTableStmt->execute();

        if ($generationPlan->containsDeferred()) {
            $generationPlan->executeDeferred($this->em, $entity);
        }

        // Collect identifier column values
        $id           = [];
        $idParameters = [];

        foreach ($this->class->getIdentifier() as $propertyName) {
            $property      = $this->class->getProperty($propertyName);
            $propertyValue = $property->getValue($entity);

            $id[$propertyName] = $propertyValue;

            switch (true) {
                case $property instanceof FieldMetadata:
                    $columnName = $property->getColumnName();

                    $idParameters[$columnName] = new Parameter($columnName, $propertyValue, $property->getType());
                    break;

                case $property instanceof ToOneAssociationMetadata:
                    $targetPersister = $this->em->getUnitOfWork()->getEntityPersister($property->getTargetEntity());

                    foreach ($property->getJoinColumns() as $joinColumn) {
                        $columnName           = $joinColumn->getColumnName();
                        $referencedColumnName = $joinColumn->getReferencedColumnName();
                        $columnValue          = $targetPersister->getColumnValue($propertyValue, $referencedColumnName);

                        $idParameters[$columnName] = new Parameter($columnName, $columnValue, $joinColumn->getType());
                    }

                    break;
            }
        }

        if ($this->class->isVersioned()) {
            $this->assignDefaultVersionValue($entity, $id);
        }

        // Execute inserts on subtables.
        // The order doesn't matter because all child tables link to the root table via FK.
        foreach ($subTableStmts as $tableName => $stmt) {
            /** @var Statement $stmt */
            $paramIndex = 1;
            $tableData  = $insertData[$tableName] ?? [];

            foreach ($idParameters as $parameter) {
                $stmt->bindValue($paramIndex++, $parameter->getValue(), $parameter->getType());
            }

            foreach ($tableData as $parameter) {
                if (! isset($idParameters[$parameter->getName()])) {
                    $stmt->bindValue($paramIndex++, $parameter->getValue(), $parameter->getType());
                }
            }

            $stmt->execute();
        }

        $rootTableStmt->closeCursor();

        foreach ($subTableStmts as $stmt) {
            $stmt->closeCursor();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update($entity)
    {
        $updateData = $this->prepareUpdateData($entity);

        if (! $updateData) {
            return;
        }

        $isVersioned = $this->class->isVersioned();

        foreach ($updateData as $tableName => $tableData) {
            $isTableVersioned = $isVersioned && $this->class->versionProperty->getTableName() === $tableName;

            $this->updateTable($entity, $this->platform->quoteIdentifier($tableName), $tableData, $isTableVersioned);
        }

        if ($isVersioned) {
            $identifiers = $this->em->getUnitOfWork()->getEntityIdentifier($entity);

            $this->assignDefaultVersionValue($entity, $identifiers);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($entity)
    {
        $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entity);
        $id         = array_combine(array_keys($this->class->getIdentifierColumns($this->em)), $identifier);

        $this->deleteJoinTableRecords($identifier);

        // If the database platform supports FKs, just
        // delete the row from the root table. Cascades do the rest.
        if ($this->platform->supportsForeignKeyConstraints()) {
            $rootClass = $this->em->getClassMetadata($this->class->getRootClassName());
            $rootTable = $rootClass->table->getQuotedQualifiedName($this->platform);

            return (bool) $this->conn->delete($rootTable, $id);
        }

        // Delete from all tables individually, starting from this class' table up to the root table.
        $rootTable    = $this->class->table->getQuotedQualifiedName($this->platform);
        $affectedRows = $this->conn->delete($rootTable, $id);
        $parentClass  = $this->class;

        while (($parentClass = $parentClass->getParent()) !== null) {
            $parentTable = $parentClass->table->getQuotedQualifiedName($this->platform);

            $this->conn->delete($parentTable, $id);
        }

        return (bool) $affectedRows;
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

        $baseTableAlias = $this->getSQLTableAlias($this->class->getTableName());
        $joinSql        = $this->getJoinSql($baseTableAlias);

        if ($association instanceof ManyToManyAssociationMetadata) {
            $joinSql .= $this->getSelectManyToManyJoinSQL($association);
        }

        if ($association instanceof ToManyAssociationMetadata && $association->getOrderBy()) {
            $orderBy = $association->getOrderBy();
        }

        $orderBySql   = $this->getOrderBySQL($orderBy, $baseTableAlias);
        $conditionSql = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria, $association);

        // If the current class in the root entity, add the filters
        $rootClass  = $this->em->getClassMetadata($this->class->getRootClassName());
        $tableAlias = $this->getSQLTableAlias($rootClass->getTableName());
        $filterSql  = $this->generateFilterConditionSQL($rootClass, $tableAlias);

        if ($filterSql) {
            $conditionSql .= $conditionSql
                ? ' AND ' . $filterSql
                : $filterSql;
        }

        $lockSql = '';

        switch ($lockMode) {
            case LockMode::PESSIMISTIC_READ:
                $lockSql = ' ' . $this->platform->getReadLockSQL();
                break;

            case LockMode::PESSIMISTIC_WRITE:
                $lockSql = ' ' . $this->platform->getWriteLockSQL();
                break;
        }

        $tableName  = $this->class->table->getQuotedQualifiedName($this->platform);
        $from       = ' FROM ' . $tableName . ' ' . $baseTableAlias;
        $where      = $conditionSql !== '' ? ' WHERE ' . $conditionSql : '';
        $lock       = $this->platform->appendLockHint($from, $lockMode);
        $columnList = $this->getSelectColumnsSQL();
        $query      = 'SELECT ' . $columnList . $lock . $joinSql . $where . $orderBySql;

        return $this->platform->modifyLimitQuery($query, $limit, $offset ?? 0) . $lockSql;
    }

    /**
     * {@inheritDoc}
     */
    public function getCountSQL($criteria = [])
    {
        $tableName      = $this->class->table->getQuotedQualifiedName($this->platform);
        $baseTableAlias = $this->getSQLTableAlias($this->class->getTableName());
        $joinSql        = $this->getJoinSql($baseTableAlias);
        $conditionSql   = $criteria instanceof Criteria
            ? $this->getSelectConditionCriteriaSQL($criteria)
            : $this->getSelectConditionSQL($criteria);

        $rootClass  = $this->em->getClassMetadata($this->class->getRootClassName());
        $tableAlias = $this->getSQLTableAlias($rootClass->getTableName());
        $filterSql  = $this->generateFilterConditionSQL($rootClass, $tableAlias);

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

    /**
     * {@inheritdoc}
     */
    protected function getLockTablesSql($lockMode)
    {
        $joinSql           = '';
        $identifierColumns = $this->class->getIdentifierColumns($this->em);
        $baseTableAlias    = $this->getSQLTableAlias($this->class->getTableName());

        // INNER JOIN parent tables
        $parentClass = $this->class;

        while (($parentClass = $parentClass->getParent()) !== null) {
            $conditions = [];
            $tableName  = $parentClass->table->getQuotedQualifiedName($this->platform);
            $tableAlias = $this->getSQLTableAlias($parentClass->getTableName());
            $joinSql   .= ' INNER JOIN ' . $tableName . ' ' . $tableAlias . ' ON ';

            foreach ($identifierColumns as $idColumn) {
                $quotedColumnName = $this->platform->quoteIdentifier($idColumn->getColumnName());

                $conditions[] = $baseTableAlias . '.' . $quotedColumnName . ' = ' . $tableAlias . '.' . $quotedColumnName;
            }

            $joinSql .= implode(' AND ', $conditions);
        }

        return parent::getLockTablesSql($lockMode) . $joinSql;
    }

    /**
     * Ensure this method is never called. This persister overrides getSelectEntitiesSQL directly.
     *
     * @return string
     */
    protected function getSelectColumnsSQL()
    {
        // Create the column list fragment only once
        if ($this->currentPersisterContext->selectColumnListSql !== null) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $this->currentPersisterContext->rsm->addEntityResult($this->class->getClassName(), 'r');

        $columnList = [];

        // Add columns
        foreach ($this->class->getPropertiesIterator() as $fieldName => $property) {
            if ($property instanceof FieldMetadata) {
                $columnList[] = $this->getSelectColumnSQL($fieldName, $property->getDeclaringClass());

                continue;
            }

            if (! ($property instanceof ToOneAssociationMetadata) || ! $property->isOwningSide()) {
                continue;
            }

            foreach ($property->getJoinColumns() as $joinColumn) {
                $columnList[] = $this->getSelectJoinColumnSQL($joinColumn);
            }
        }

        // Add discriminator column (DO NOT ALIAS, see AbstractEntityInheritancePersister#processSQLResult).
        $discrColumn      = $this->class->discriminatorColumn;
        $discrTableAlias  = $this->getSQLTableAlias($discrColumn->getTableName());
        $discrColumnName  = $discrColumn->getColumnName();
        $discrColumnType  = $discrColumn->getType();
        $resultColumnName = $this->platform->getSQLResultCasing($discrColumnName);
        $quotedColumnName = $this->platform->quoteIdentifier($discrColumn->getColumnName());

        $this->currentPersisterContext->rsm->setDiscriminatorColumn('r', $resultColumnName);
        $this->currentPersisterContext->rsm->addMetaResult('r', $resultColumnName, $discrColumnName, false, $discrColumnType);

        $columnList[] = $discrColumnType->convertToDatabaseValueSQL($discrTableAlias . '.' . $quotedColumnName, $this->platform);

        // sub tables
        foreach ($this->class->getSubClasses() as $subClassName) {
            $subClass = $this->em->getClassMetadata($subClassName);

            // Add columns
            foreach ($subClass->getPropertiesIterator() as $fieldName => $property) {
                if ($subClass->isInheritedProperty($fieldName)) {
                    continue;
                }

                switch (true) {
                    case $property instanceof FieldMetadata:
                        $columnList[] = $this->getSelectColumnSQL($fieldName, $subClass);
                        break;

                    case $property instanceof ToOneAssociationMetadata && $property->isOwningSide():
                        foreach ($property->getJoinColumns() as $joinColumn) {
                            $columnList[] = $this->getSelectJoinColumnSQL($joinColumn);
                        }

                        break;
                }
            }
        }

        $this->currentPersisterContext->selectColumnListSql = implode(', ', $columnList);

        return $this->currentPersisterContext->selectColumnListSql;
    }

    /**
     * {@inheritdoc}
     */
    protected function getInsertColumnList() : array
    {
        if ($this->insertColumns !== null) {
            return $this->insertColumns;
        }

        // Identifier columns must always come first in the column list of subclasses.
        $parentColumns = $this->class->getParent()
            ? $this->class->getIdentifierColumns($this->em)
            : [];

        $classColumns = [];

        foreach ($this->class->getPropertiesIterator() as $name => $property) {
            if ($this->class->isInheritedProperty($name)) {
                continue;
            }

            $classColumns[] = $this->getPropertyColumnList($property);
        }

        $columns = array_merge($parentColumns, ...$classColumns);

        // Add discriminator column if it is the topmost class.
        if ($this->class->isRootEntity()) {
            $discrColumn     = $this->class->discriminatorColumn;
            $discrColumnName = $discrColumn->getColumnName();

            $columns[$discrColumnName] = $discrColumn;
        }

        return $this->insertColumns = array_filter(
            $columns,
            static function (ColumnMetadata $columnMetadata) {
                // Check for Column insertability
                return true;
            }
        );
    }

    /**
     * @param string $baseTableAlias
     *
     * @return string
     */
    private function getJoinSql($baseTableAlias)
    {
        $joinSql           = '';
        $identifierColumns = $this->class->getIdentifierColumns($this->em);

        // INNER JOIN parent tables
        $parentClass = $this->class;

        while (($parentClass = $parentClass->getParent()) !== null) {
            $conditions = [];
            $tableName  = $parentClass->table->getQuotedQualifiedName($this->platform);
            $tableAlias = $this->getSQLTableAlias($parentClass->getTableName());
            $joinSql   .= ' INNER JOIN ' . $tableName . ' ' . $tableAlias . ' ON ';

            foreach ($identifierColumns as $idColumn) {
                $quotedColumnName = $this->platform->quoteIdentifier($idColumn->getColumnName());

                $conditions[] = $baseTableAlias . '.' . $quotedColumnName . ' = ' . $tableAlias . '.' . $quotedColumnName;
            }

            $joinSql .= implode(' AND ', $conditions);
        }

        // OUTER JOIN sub tables
        foreach ($this->class->getSubClasses() as $subClassName) {
            $conditions = [];
            $subClass   = $this->em->getClassMetadata($subClassName);
            $tableName  = $subClass->table->getQuotedQualifiedName($this->platform);
            $tableAlias = $this->getSQLTableAlias($subClass->getTableName());
            $joinSql   .= ' LEFT JOIN ' . $tableName . ' ' . $tableAlias . ' ON ';

            foreach ($identifierColumns as $idColumn) {
                $quotedColumnName = $this->platform->quoteIdentifier($idColumn->getColumnName());

                $conditions[] = $baseTableAlias . '.' . $quotedColumnName . ' = ' . $tableAlias . '.' . $quotedColumnName;
            }

            $joinSql .= implode(' AND ', $conditions);
        }

        return $joinSql;
    }
}
