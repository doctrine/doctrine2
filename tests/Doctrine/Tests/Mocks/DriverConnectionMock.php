<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Mock class for DriverConnection.
 */
if (class_exists(Result::class)) {
    class DriverConnectionMock implements Connection
    {
        /**
         * @var \Doctrine\DBAL\Driver\Statement
         */
        private $statementMock;

        /**
         * @return \Doctrine\DBAL\Driver\Statement
         */
        public function getStatementMock()
        {
            return $this->statementMock;
        }

        /**
         * @param \Doctrine\DBAL\Driver\Statement $statementMock
         */
        public function setStatementMock($statementMock)
        {
            $this->statementMock = $statementMock;
        }

        /**
         * {@inheritdoc}
         */
        public function prepare($prepareString): Statement
        {
            return $this->statementMock ?: new StatementMock();
        }

        /**
         * {@inheritdoc}
         */
        public function query(string $sql): \Doctrine\DBAL\Driver\Result
        {
            return $this->statementMock ? $this->statementMock->execute() : new ResultMock();
        }

        /**
         * {@inheritdoc}
         */
        public function quote($input, $type=\PDO::PARAM_STR)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function exec($statement): int
        {
        }

        /**
         * {@inheritdoc}
         */
        public function lastInsertId($name = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function beginTransaction()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function commit()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function rollBack()
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
    }
} else {
    class DriverConnectionMock implements Connection
    {
        /**
         * @var \Doctrine\DBAL\Driver\Statement
         */
        private $statementMock;

        /**
         * @return \Doctrine\DBAL\Driver\Statement
         */
        public function getStatementMock()
        {
            return $this->statementMock;
        }

        /**
         * @param \Doctrine\DBAL\Driver\Statement $statementMock
         */
        public function setStatementMock($statementMock)
        {
            $this->statementMock = $statementMock;
        }

        /**
         * {@inheritdoc}
         */
        public function prepare($prepareString)
        {
            return $this->statementMock ?: new StatementMock();
        }

        /**
         * {@inheritdoc}
         */
        public function query()
        {
            return $this->statementMock ?: new StatementMock();
        }

        /**
         * {@inheritdoc}
         */
        public function quote($input, $type=\PDO::PARAM_STR)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function exec($statement)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function lastInsertId($name = null)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function beginTransaction()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function commit()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function rollBack()
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
    }
}

