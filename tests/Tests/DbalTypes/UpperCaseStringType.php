<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

class UpperCaseStringType extends StringType
{
    final public const string NAME = 'upper_case_string';

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        return 'UPPER(' . $sqlExpr . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValueSQL($sqlExpr, $platform): string
    {
        return 'LOWER(' . $sqlExpr . ')';
    }
}
