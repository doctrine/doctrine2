<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

/**
 * Mock class for ExceptionConverter
 */
class ExceptionConverterMock implements ExceptionConverter
{
    /**
     * {@inheritdoc}
     */
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        return new DriverException($exception, $query);
    }
}
