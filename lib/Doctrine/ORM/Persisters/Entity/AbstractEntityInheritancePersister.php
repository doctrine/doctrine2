<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadata;

use function sprintf;

/**
 * Base class for entity persisters that implement a certain inheritance mapping strategy.
 * All these persisters are assumed to use a discriminator column to discriminate entity
 * types in the hierarchy.
 */
abstract class AbstractEntityInheritancePersister extends BasicEntityPersister
{
    /**
     * {@inheritdoc}
     */
    protected function prepareInsertData($entity)
    {
        $data = parent::prepareInsertData($entity);

        // Populate the discriminator column
        $discColumn                                                          = $this->class->discriminatorColumn;
        $this->columnTypes[$discColumn['name']]                              = $discColumn['type'];
        $data[$this->getDiscriminatorColumnTableName()][$discColumn['name']] = $this->class->discriminatorValue;

        return $data;
    }

    /**
     * Gets the name of the table that contains the discriminator column.
     *
     * @return string The table name.
     */
    abstract protected function getDiscriminatorColumnTableName();

    /**
     * {@inheritdoc}
     */
    protected function getSelectColumnSQL($field, ClassMetadata $class, $alias = 'r')
    {
        $tableAlias   = $alias === 'r' ? '' : $alias;
        $fieldMapping = $class->fieldMappings[$field];
        $columnAlias  = $this->getSQLColumnAlias($fieldMapping['columnName']);
        $sql          = sprintf(
            '%s.%s',
            $this->getSQLTableAlias($class->name, $tableAlias),
            $this->quoteStrategy->getColumnName($field, $class, $this->platform)
        );

        $this->currentPersisterContext->rsm->addFieldResult($alias, $columnAlias, $field, $class->name);

        if (isset($fieldMapping['requireSQLConversion'])) {
            $type = Type::getType($fieldMapping['type']);
            $sql  = $type->convertToPHPValueSQL($sql, $this->platform);
        }

        return $sql . ' AS ' . $columnAlias;
    }

    /**
     * @param string $tableAlias
     * @param string $joinColumnName
     * @param string $quotedColumnName
     * @param string $type
     *
     * @return string
     */
    protected function getSelectJoinColumnSQL($tableAlias, $joinColumnName, $quotedColumnName, $type)
    {
        $columnAlias = $this->getSQLColumnAlias($joinColumnName);

        $this->currentPersisterContext->rsm->addMetaResult('r', $columnAlias, $joinColumnName, false, $type);

        return $tableAlias . '.' . $quotedColumnName . ' AS ' . $columnAlias;
    }
}
