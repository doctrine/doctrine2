<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class NegativeToPositiveType extends Type
{
    final public const string NAME = 'negative_to_positive';

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        return 'ABS(' . $sqlExpr . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValueSQL($sqlExpr, $platform): string
    {
        return '-(' . $sqlExpr . ')';
    }
}
