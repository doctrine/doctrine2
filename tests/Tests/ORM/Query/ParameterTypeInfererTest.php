<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\Tests\Models\Enums\AccessLevel;
use Doctrine\Tests\Models\Enums\UserStatus;
use Doctrine\Tests\OrmTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

class ParameterTypeInfererTest extends OrmTestCase
{
    /** @phpstan-return Generator<string, array{mixed, (ParameterType|ArrayParameterType|string)}> */
    public static function providerParameterTypeInferer(): Generator
    {
        yield 'integer' => [1, Types::INTEGER];
        yield 'string' => ['bar', ParameterType::STRING];
        yield 'numeric_string' => ['1', ParameterType::STRING];
        yield 'datetime_object' => [new DateTime(), Types::DATETIME_MUTABLE];
        yield 'datetime_immutable_object' => [new DateTimeImmutable(), Types::DATETIME_IMMUTABLE];
        yield 'date_interval_object' => [new DateInterval('P1D'), Types::DATEINTERVAL];
        yield 'array_of_int' => [[2], ArrayParameterType::INTEGER];
        yield 'array_of_string' => [['foo'], ArrayParameterType::STRING];
        yield 'array_of_numeric_string' => [['1', '2'], ArrayParameterType::STRING];
        yield 'empty_array' => [[], ArrayParameterType::STRING];
        yield 'boolean' => [true, Types::BOOLEAN];
        yield 'int_backed_enum' => [AccessLevel::Admin, Types::INTEGER];
        yield 'string_backed_enum' => [UserStatus::Active, Types::STRING];
        yield 'array_of_int_backed_enum' => [[AccessLevel::Admin], ArrayParameterType::INTEGER];
        yield 'array_of_string_backed_enum' => [[UserStatus::Active], ArrayParameterType::STRING];
    }

    #[DataProvider('providerParameterTypeInferer')]
    public function testParameterTypeInferer(mixed $value, ParameterType|ArrayParameterType|string $expected): void
    {
        self::assertEquals($expected, ParameterTypeInferer::inferType($value));
    }
}
