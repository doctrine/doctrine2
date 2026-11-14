<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Internal\SQLResultCasing;
use Override;

/**
 * ANSI compliant quote strategy, this strategy does not apply any quote.
 * To use this strategy all mapped tables and columns should be ANSI compliant.
 */
class AnsiQuoteStrategy implements QuoteStrategy
{
    use SQLResultCasing;

    #[Override]
    public function getColumnName(
        string $fieldName,
        ClassMetadata $class,
        AbstractPlatform $platform,
    ): string {
        return $class->fieldMappings[$fieldName]->columnName;
    }

    #[Override]
    public function getTableName(ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $class->table['name'];
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getSequenceName(array $definition, ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $definition['sequenceName'];
    }

    #[Override]
    public function getJoinColumnName(JoinColumnMapping $joinColumn, ClassMetadata $class, AbstractPlatform $platform): string
    {
        return $joinColumn->name;
    }

    #[Override]
    public function getReferencedJoinColumnName(
        JoinColumnMapping $joinColumn,
        ClassMetadata $class,
        AbstractPlatform $platform,
    ): string {
        return $joinColumn->referencedColumnName;
    }

    #[Override]
    public function getJoinTableName(
        ManyToManyOwningSideMapping $association,
        ClassMetadata $class,
        AbstractPlatform $platform,
    ): string {
        return $association->joinTable->name;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getIdentifierColumnNames(ClassMetadata $class, AbstractPlatform $platform): array
    {
        return $class->identifier;
    }

    #[Override]
    public function getColumnAlias(
        string $columnName,
        int $counter,
        AbstractPlatform $platform,
        ClassMetadata|null $class = null,
    ): string {
        return $this->getSQLResultCasing($platform, $columnName . '_' . $counter);
    }
}
