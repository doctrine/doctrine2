<?php

namespace Doctrine\Tests\ORM\Query;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\DBAL\Connection;
use Doctrine\Tests\OrmTestCase;
use PDO;

class ParameterTypeInfererTest extends OrmTestCase
{

    public function providerParameterTypeInferer()
    {
        $data = [
            [1,                 Types::INTEGER],
            ["bar",             PDO::PARAM_STR],
            ["1",               PDO::PARAM_STR],
            [new \DateTime,     Types::DATETIME_MUTABLE],
            [new \DateInterval('P1D'), Types::DATEINTERVAL],
            [[2],          Connection::PARAM_INT_ARRAY],
            [["foo"],      Connection::PARAM_STR_ARRAY],
            [["1","2"],    Connection::PARAM_STR_ARRAY],
            [[],           Connection::PARAM_STR_ARRAY],
            [true,              Types::BOOLEAN],
        ];

        if (PHP_VERSION_ID >= 50500) {
            $data[] = [new \DateTimeImmutable(), Types::DATETIME_MUTABLE];
        }

        return $data;
    }

    /**
     * @dataProvider providerParameterTypeInferer
     */

    public function testParameterTypeInferer($value, $expected)
    {
        $this->assertEquals($expected, ParameterTypeInferer::inferType($value));
    }
}
