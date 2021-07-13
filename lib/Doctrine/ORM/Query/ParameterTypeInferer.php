<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PDO;

use function current;
use function is_array;
use function is_bool;
use function is_int;

/**
 * Provides an enclosed support for parameter inferring.
 *
 * @link    www.doctrine-project.org
 */
class ParameterTypeInferer
{
    /**
     * Infers type of a given value, returning a compatible constant:
     * - Type (\Doctrine\DBAL\Types\Type::*)
     * - Connection (\Doctrine\DBAL\Connection::PARAM_*)
     *
     * @param mixed $value Parameter value.
     *
     * @return int|string Parameter type constant.
     */
    public static function inferType($value)
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

        if (is_array($value)) {
            return is_int(current($value))
                ? Connection::PARAM_INT_ARRAY
                : Connection::PARAM_STR_ARRAY;
        }

        return PDO::PARAM_STR;
    }
}
