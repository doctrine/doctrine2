<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use Doctrine\DBAL\Result;

use function array_keys;
use function array_map;
use function array_values;

final class ArrayResultFactory
{
    /** @param list<array<string, mixed>> $resultSet */
    public static function createDriverResultFromArray(array $resultSet): ArrayResult
    {
        return new ArrayResult(
            array_keys($resultSet[0] ?? []),
            array_map(array_values(...), $resultSet),
        );
    }

    /** @param list<array<string, mixed>> $resultSet */
    public static function createWrapperResultFromArray(array $resultSet, Connection|null $connection = null): Result
    {
        return new Result(
            self::createDriverResultFromArray($resultSet),
            $connection ?? new Connection([], new Driver()),
        );
    }
}
