<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * A set of rules for determining the column, alias and table quotes.
 */
interface QuoteStrategy
{
    /**
     * Gets the (possibly quoted) column name for safe use in an SQL statement.
     *
     * @param string $fieldName
     *
     * @return string
     */
    public function getColumnName($fieldName, ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the (possibly quoted) primary table name for safe use in an SQL statement.
     *
     * @return string
     */
    public function getTableName(ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the (possibly quoted) sequence name for safe use in an SQL statement.
     *
     * @param mixed[] $definition
     *
     * @return string
     */
    public function getSequenceName(array $definition, ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the (possibly quoted) name of the join table.
     *
     * @param mixed[] $association
     *
     * @return string
     */
    public function getJoinTableName(array $association, ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the (possibly quoted) join column name.
     *
     * @param mixed[] $joinColumn
     *
     * @return string
     */
    public function getJoinColumnName(array $joinColumn, ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the (possibly quoted) join column name.
     *
     * @param mixed[] $joinColumn
     *
     * @return string
     */
    public function getReferencedJoinColumnName(array $joinColumn, ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the (possibly quoted) identifier column names for safe use in an SQL statement.
     *
     * @psalm-return list<string>
     */
    public function getIdentifierColumnNames(ClassMetadata $class, AbstractPlatform $platform);

    /**
     * Gets the column alias.
     *
     * @param string $columnName
     * @param int    $counter
     *
     * @return string
     */
    public function getColumnAlias($columnName, $counter, AbstractPlatform $platform, ?ClassMetadata $class = null);
}
