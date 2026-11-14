<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH9505;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** Mirrors the shape of Symfony\Bridge\Doctrine\Types\UuidType: PHP value is an object, DB value is a string. */
final class GH9505ObjectIdType extends Type
{
    public const NAME = 'gh9505_object_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 36, 'fixed' => true]);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): GH9505ObjectId|null
    {
        return $value === null ? null : new GH9505ObjectId($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): string|null
    {
        return $value === null ? null : (string) $value;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
