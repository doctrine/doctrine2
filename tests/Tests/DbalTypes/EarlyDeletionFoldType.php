<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function strtolower;

/**
 * Folds every value to lower case on the way to the database, so that
 * distinct PHP values map to one and the same database representation.
 */
class EarlyDeletionFoldType extends Type
{
    public const NAME = 'early_deletion_fold';

    /**
     * {@inheritDoc}
     *
     * @param string|null $value
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): string|null
    {
        if ($value === null) {
            return null;
        }

        return strtolower($value);
    }

    /**
     * {@inheritDoc}
     *
     * @param string|null $value
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): string|null
    {
        return $value;
    }

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
