<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function bin2hex;
use function hex2bin;
use function str_replace;

/**
 * Converts UUID strings to value objects, comparable in cost to the UUID types of symfony/uid or ramsey/uuid.
 */
class UuidObjectType extends Type
{
    public const string NAME = 'uuid_object';

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): UuidObject|null
    {
        return $value === null ? null : new UuidObject((string) hex2bin(str_replace('-', '', $value)));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string|null
    {
        return $value === null ? null : bin2hex($value->bytes);
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
