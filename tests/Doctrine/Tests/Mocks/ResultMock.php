<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Result;

class ResultMock extends Result implements \Doctrine\DBAL\Driver\Result
{
    /**
     * @var array
     */
    private $_resultSet;

    /**
     * Creates a new mock result that will serve the provided fake result set to clients.
     *
     * @param array $resultSet The faked SQL result set.
     */
    public function __construct(array $resultSet = [])
    {
        $this->_resultSet = $resultSet;
    }

    public function fetchNumeric()
    {
        // TODO: Implement fetchNumeric() method.
    }

    public function fetchAssociative()
    {
        $current = current($this->_resultSet);
        next($this->_resultSet);
        return $current;
    }

    public function fetchOne()
    {
        $current = current($this->_resultSet);
        if ($current) {
            next($this->_resultSet);
            return reset($current);
        }

        return false;
    }

    public function fetchAllNumeric(): array
    {
        // TODO: Implement fetchAllNumeric() method.
    }

    public function fetchAllAssociative(): array
    {
        return $this->_resultSet;
    }

    public function fetchFirstColumn(): array
    {
        // TODO: Implement fetchFirstColumn() method.
    }

    public function rowCount(): int
    {
        // TODO: Implement rowCount() method.
    }

    public function columnCount(): int
    {
        // TODO: Implement columnCount() method.
    }

    public function free(): void
    {
        // TODO: Implement free() method.
    }
}
