<?php

declare(strict_types=1);

namespace Doctrine\Tests\DbalTypes;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use RuntimeException;

use function sprintf;

/**
 * A converter that fails for every value: exercises the floor path of the
 * early-deletion key computation (a converter failure never throws out of
 * the planner).
 */
class EarlyDeletionThrowingType extends Type
{
    public const NAME = 'early_deletion_throwing';

    /**
     * {@inheritDoc}
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): mixed
    {
        throw new EarlyDeletionConversionFailed(sprintf('Type "%s" rejects every value.', self::NAME));
    }

    /**
     * {@inheritDoc}
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): mixed
    {
        throw new EarlyDeletionConversionFailed(sprintf('Type "%s" rejects every value.', self::NAME));
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

final class EarlyDeletionConversionFailed extends RuntimeException
{
}
