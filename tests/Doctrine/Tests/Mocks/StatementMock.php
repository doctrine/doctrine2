<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Result;

/**
 * This class is a mock of the Statement interface.
 *
 * @author Alexander <iam.asm89@gmail.com>
 */
if (class_exists(Result::class)) {
    class StatementMock implements \IteratorAggregate, Statement
    {
        /**
         * @var ResultMock
         */
        private $_resultMock;

        public function __construct(array $resultSet = [])
        {
            $this->_resultMock = new ResultMock($resultSet);
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
            return $this->_resultMock;
        }

        /**
         * {@inheritdoc}
         */
        public function getIterator()
        {
        }
    }
} else {
    class StatementMock implements \IteratorAggregate, Statement
    {
        /**
         * @var array
         */
        private $_resultSet;

        public function __construct(array $resultSet = [])
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
        public function errorCode()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function errorInfo(){}

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
            return count($this->_resultSet);
        }

        /**
         * {@inheritdoc}
         */
        public function closeCursor()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function columnCount()
        {
            $row = reset($this->_resultSet);
            if ($row) {
                return count($row);
            } else {
                return 0;
            }
        }

        /**
         * {@inheritdoc}
         */
        public function setFetchMode($fetchStyle, $arg2 = null, $arg3 = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null)
        {
            return $this->fetchAllAssociative($fetchArgument, $ctorArgs);
        }

        /**
         * {@inheritdoc}
         */
        public function fetchAllAssociative($fetchArgument = null, $ctorArgs = null)
        {
            return $this->_resultSet;
        }

        /**
         * {@inheritdoc}
         */
        public function fetch($fetchMode = null, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
        {
            $current = current($this->_resultSet);
            next($this->_resultSet);

            return $current;
        }

        /**
         * {@inheritdoc}
         */
        public function fetchAssociative($fetchArgument = null, $ctorArgs = null)
        {
            $current = current($this->_resultSet);
            next($this->_resultSet);

            return $current;
        }

        /**
         * {@inheritdoc}
         */
        public function fetchColumn($columnIndex = 0)
        {
            return $this->fetchOne();
        }

        /**
         * {@inheritdoc}
         */
        public function fetchOne()
        {
            $current = current($this->_resultSet);
            if ($current) {
                next($this->_resultSet);
                return reset($current);
            }

            return false;
        }

        /**
         * {@inheritdoc}
         */
        public function getIterator()
        {
            return new \ArrayIterator($this->_resultSet);
        }
    }
}
