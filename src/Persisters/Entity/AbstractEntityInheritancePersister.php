<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\ORM\Mapping\ClassMetadata;
use Override;

use function sprintf;

/**
 * Base class for entity persisters that implement a certain inheritance mapping strategy.
 * All these persisters are assumed to use a discriminator column to discriminate entity
 * types in the hierarchy.
 */
abstract class AbstractEntityInheritancePersister extends BasicEntityPersister
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    protected function prepareInsertData(object $entity): array
    {
        $data = parent::prepareInsertData($entity);

        // Populate the discriminator column
        $discColumn                                                        = $this->class->getDiscriminatorColumn();
        $this->columnTypes[$discColumn->name]                              = $discColumn->type;
        $data[$this->getDiscriminatorColumnTableName()][$discColumn->name] = $this->class->discriminatorValue;

        return $data;
    }

    /**
     * Gets the name of the table that contains the discriminator column.
     */
    abstract protected function getDiscriminatorColumnTableName(): string;

    #[Override]
    protected function getSelectColumnSQL(string $field, ClassMetadata $class, string $alias = 'r'): string
    {
        $tableAlias   = $alias === 'r' ? '' : $alias;
        $fieldMapping = $class->fieldMappings[$field];
        $sql          = sprintf(
            '%s.%s',
            $this->getSQLTableAlias($class->name, $tableAlias),
            $this->quoteStrategy->getColumnName($field, $class, $this->platform),
        );

        // Reuse the alias if the field was already selected before; the SQL is regenerated
        // when the filters change, and a second alias for the same field breaks indexBy().
        // getColumnAliasByField() is of no use here, as it misses fields inherited from a
        // parent class - the map is keyed by the declaring class.
        $columnAlias = $this->currentPersisterContext->rsm->columnAliasMappings[$class->name][$alias][$field]
            ?? $this->getSQLColumnAlias($fieldMapping->columnName);

        $this->currentPersisterContext->rsm->addFieldResult($alias, $columnAlias, $field, $class->name);

        $type = $this->getType($fieldMapping->type);
        $sql  = $type->convertToPHPValueSQL($sql, $this->platform);

        return $sql . ' AS ' . $columnAlias;
    }

    protected function getSelectJoinColumnSQL(string $tableAlias, string $joinColumnName, string $quotedColumnName, string $type): string
    {
        $columnAlias = $this->getSQLColumnAlias($joinColumnName);

        $this->currentPersisterContext->rsm->addMetaResult('r', $columnAlias, $joinColumnName, false, $type);

        return $tableAlias . '.' . $quotedColumnName . ' AS ' . $columnAlias;
    }
}
