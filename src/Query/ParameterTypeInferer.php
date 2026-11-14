<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use BackedEnum;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

use function current;
use function is_array;
use function is_bool;
use function is_int;

/**
 * Provides an enclosed support for parameter inferring.
 *
 * @link    www.doctrine-project.org
 */
final class ParameterTypeInferer
{
    /**
     * Infers type of a given value, returning a compatible constant or enum case:
     * - Type (\Doctrine\DBAL\Types\Type::*)
     * - ParameterType (\Doctrine\DBAL\ParameterType)
     * - ArrayParameterType (\Doctrine\DBAL\ArrayParameterType)
     */
    public static function inferType(mixed $value): ParameterType|ArrayParameterType|string
    {
        if (is_int($value)) {
            return Types::INTEGER;
        }

        if (is_bool($value)) {
            return Types::BOOLEAN;
        }

        if ($value instanceof DateTimeImmutable) {
            return Types::DATETIME_IMMUTABLE;
        }

        if ($value instanceof DateTimeInterface) {
            return Types::DATETIME_MUTABLE;
        }

        if ($value instanceof DateInterval) {
            return Types::DATEINTERVAL;
        }

        if ($value instanceof BackedEnum) {
            return is_int($value->value)
                ? Types::INTEGER
                : Types::STRING;
        }

        if (is_array($value)) {
            $firstValue = current($value);
            if ($firstValue instanceof BackedEnum) {
                $firstValue = $firstValue->value;
            }

            return is_int($firstValue)
                ? ArrayParameterType::INTEGER
                : ArrayParameterType::STRING;
        }

        return ParameterType::STRING;
    }

    private function __construct()
    {
    }
}
