<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Utility\PersisterHelper;
use Override;

use function array_flip;
use function array_intersect;
use function array_map;
use function array_unshift;
use function implode;
use function strval;

/**
 * Persister for entities that participate in a hierarchy mapped with the
 * SINGLE_TABLE strategy.
 *
 * @link https://martinfowler.com/eaaCatalog/singleTableInheritance.html
 */
class SingleTablePersister extends AbstractEntityInheritancePersister
{
    use SQLResultCasing;

    #[Override]
    protected function getDiscriminatorColumnTableName(): string
    {
        return $this->class->getTableName();
    }

    #[Override]
    protected function getSelectColumnsSQL(): string
    {
        $columnList = [];
        if ($this->currentPersisterContext->selectColumnListSql !== null && $this->isFilterHashUpToDate()) {
            return $this->currentPersisterContext->selectColumnListSql;
        }

        $columnList[] = parent::getSelectColumnsSQL();

        $rootClass  = $this->em->getClassMetadata($this->class->rootEntityName);
        $tableAlias = $this->getSQLTableAlias($rootClass->name);

        // Append discriminator column
        $discrColumn     = $this->class->getDiscriminatorColumn();
        $discrColumnName = $discrColumn->name;
        $discrColumnType = $discrColumn->type;

        $columnList[] = $tableAlias . '.' . $discrColumnName;

        $resultColumnName = $this->getSQLResultCasing($this->platform, $discrColumnName);

        $this->currentPersisterContext->rsm->setDiscriminatorColumn('r', $resultColumnName);
        $this->currentPersisterContext->rsm->addMetaResult('r', $resultColumnName, $discrColumnName, false, $discrColumnType);

        // Append subclass columns
        foreach ($this->class->subClasses as $subClassName) {
            $subClass = $this->em->getClassMetadata($subClassName);

            // Regular columns
            foreach ($subClass->fieldMappings as $fieldName => $mapping) {
                if (isset($mapping->inherited)) {
                    continue;
                }

                $columnList[] = $this->getSelectColumnSQL($fieldName, $subClass);
            }

            // Foreign key columns
            foreach ($subClass->associationMappings as $assoc) {
                if (! $assoc->isToOneOwningSide() || isset($assoc->inherited)) {
                    continue;
                }

                $targetClass = $this->em->getClassMetadata($assoc->targetEntity);

                foreach ($assoc->joinColumns as $joinColumn) {
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
    #[Override]
    protected function getInsertColumnList(): array
    {
        $columns = parent::getInsertColumnList();

        // Add discriminator column to the INSERT SQL
        $columns[] = $this->class->getDiscriminatorColumn()->name;

        return $columns;
    }

    #[Override]
    protected function getSQLTableAlias(string $className, string $assocName = ''): string
    {
        return parent::getSQLTableAlias($this->class->rootEntityName, $assocName);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function getSelectConditionSQL(array $criteria, AssociationMapping|null $assoc = null): string
    {
        $conditionSql = parent::getSelectConditionSQL($criteria, $assoc);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    #[Override]
    protected function getSelectConditionCriteriaSQL(Criteria $criteria): string
    {
        $conditionSql = parent::getSelectConditionCriteriaSQL($criteria);

        if ($conditionSql) {
            $conditionSql .= ' AND ';
        }

        return $conditionSql . $this->getSelectConditionDiscriminatorValueSQL();
    }

    protected function getSelectConditionDiscriminatorValueSQL(): string
    {
        $values = array_map($this->conn->quote(...), array_map(
            strval(...),
            array_flip(array_intersect($this->class->discriminatorMap, $this->class->subClasses)),
        ));

        if ($this->class->discriminatorValue !== null) { // discriminators can be 0
            array_unshift($values, $this->conn->quote((string) $this->class->discriminatorValue));
        }

        $discColumnName = $this->class->getDiscriminatorColumn()->name;

        $values     = implode(', ', $values);
        $tableAlias = $this->getSQLTableAlias($this->class->name);

        return $tableAlias . '.' . $discColumnName . ' IN (' . $values . ')';
    }

    #[Override]
    protected function generateFilterConditionSQL(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        // Ensure that the filters are applied to the root entity of the inheritance tree
        $targetEntity = $this->em->getClassMetadata($targetEntity->rootEntityName);
        // we don't care about the $targetTableAlias, in a STI there is only one table.

        return parent::generateFilterConditionSQL($targetEntity, $targetTableAlias);
    }
}
