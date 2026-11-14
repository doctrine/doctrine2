<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\BinaryPrimaryKey;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use LogicException;

final class BinaryIdType extends Type
{
    public const string NAME = 'binary_id';

    public function convertToPHPValue(
        mixed $value,
        AbstractPlatform $platform,
    ): BinaryId|null {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BinaryId) {
            return $value;
        }

        return BinaryId::fromBytes($value);
    }

    public function convertToDatabaseValue(
        mixed $value,
        AbstractPlatform $platform,
    ): string|null {
        if ($value === null) {
            return null;
        } elseif ($value instanceof BinaryId) {
            return $value->getBytes();
        } else {
            throw new LogicException('Unexpected value: ' . $value);
        }
    }

    public function getSQLDeclaration(
        array $column,
        AbstractPlatform $platform,
    ): string {
        return $platform->getBinaryTypeDeclarationSQL([
            'length' => BinaryId::LENGTH,
            'fixed' => true,
        ]);
    }

    private function doGetBindingType(): ParameterType|int
    {
        return ParameterType::BINARY;
    }

    public function getName(): string
    {
        return self::NAME;
    }
}
