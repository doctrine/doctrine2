<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use ArrayIterator;
use PDO;

use function count;
use function current;
use function next;
use function reset;

/**
 * Simple statement mock that returns result based on array.
 * Doesn't support fetch modes
 */
class StatementArrayMock extends StatementMock
{
    /** @var mixed[] */
    private $_result;

    /**
     * @param mixed[] $result
     */
    public function __construct(array $result)
    {
        $this->_result = $result;
    }

    /**
     * @psalm-return ArrayIterator<int, mixed>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->_result);
    }

    public function columnCount(): int
    {
        $row = reset($this->_result);
        if ($row) {
            return count($row);
        }

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null): array
    {
        return $this->_result;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch($fetchMode = null, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        $current = current($this->_result);
        next($this->_result);

        return $current;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchColumn($columnIndex = 0)
    {
        $current = current($this->_result);
        if ($current) {
            next($this->_result);

            return reset($current);
        }

        return false;
    }

    public function rowCount(): int
    {
        return count($this->_result);
    }
}
