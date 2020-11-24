<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Result;

/**
 * This class is a mock of the Statement interface that can be passed in to the Hydrator
 * to test the hydration standalone with faked result sets.
 *
 * @author  Roman Borschel <roman@code-factory.org>
 */
if (class_exists(Result::class)) {
    class HydratorMockStatement implements \IteratorAggregate, Statement
    {
        /**
         * @var array
         */
        private $_resultSet;

        /**
         * Creates a new mock statement that will serve the provided fake result set to clients.
         *
         * @param array $resultSet The faked SQL result set.
         */
        public function __construct(array $resultSet)
        {
            $this->_resultSet = $resultSet;
        }

        /**
         * {@inheritdoc}
         */
        public function bindValue($param, $value, $type = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function bindParam($column, &$variable, $type = null, $length = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function execute($params = null): \Doctrine\DBAL\Driver\Result
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getIterator()
        {
            return new \ArrayIterator($this->_resultSet);
        }
    }
} else {
    class HydratorMockStatement implements \IteratorAggregate, Statement
    {
        /**
         * @var array
         */
        private $_resultSet;

        /**
         * Creates a new mock statement that will serve the provided fake result set to clients.
         *
         * @param array $resultSet The faked SQL result set.
         */
        public function __construct(array $resultSet)
        {
            $this->_resultSet = $resultSet;
        }

        /**
         * Fetches all rows from the result set.
         *
         * @param int|null   $fetchMode
         * @param int|null   $fetchArgument
         * @param array|null $ctorArgs
         * @return array
         */
        public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null)
        {
            return $this->_resultSet;
        }

        /**
         * Fetches all rows from the result set.
         *
         * @param int|null   $fetchArgument
         * @param array|null $ctorArgs
         * @return array
         */
        public function fetchAllAssociative($fetchArgument = null, $ctorArgs = null)
        {
            return $this->_resultSet;
        }

        /**
         * {@inheritdoc}
         */
        public function fetchColumn($columnNumber = 0)
        {
            $row = current($this->_resultSet);
            if ( ! is_array($row)) return false;
            $val = array_shift($row);
            return $val !== null ? $val : false;
        }

        /**
         * {@inheritdoc}
         */
        public function fetch($fetchStyle = null, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
        {
            $current = current($this->_resultSet);
            next($this->_resultSet);
            return $current;
        }

        /**
         * {@inheritdoc}
         */
        public function fetchAssociative($cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
        {
            $current = current($this->_resultSet);
            next($this->_resultSet);
            return $current;
        }

        /**
         * {@inheritdoc}
         */
        public function closeCursor()
        {
            return true;
        }

        /**
         * {@inheritdoc}
         */
        public function bindValue($param, $value, $type = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function bindParam($column, &$variable, $type = null, $length = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function columnCount()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function errorCode()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function errorInfo()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function execute($params = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function rowCount()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getIterator()
        {
            return new \ArrayIterator($this->_resultSet);
        }

        /**
         * {@inheritdoc}
         */
        public function setFetchMode($fetchStyle, $arg2 = null, $arg3 = null)
        {
        }
    }
}
