<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;

/**
 * Mock class for DatabasePlatform.
 */
if (class_exists(Result::class)) {
    class DatabasePlatformMock extends AbstractPlatform
    {
        /**
         * @var string
         */
        private $_sequenceNextValSql = "";

        /**
         * @var bool
         */
        private $_prefersIdentityColumns = true;

        /**
         * @var bool
         */
        private $_prefersSequences = false;

        /**
         * {@inheritdoc}
         */
        public function prefersIdentityColumns()
        {
            return $this->_prefersIdentityColumns;
        }

        /**
         * {@inheritdoc}
         */
        public function supportsIdentityColumns()
        {
            return $this->_prefersIdentityColumns;
        }

        /**
         * {@inheritdoc}
         */
        public function supportsSequences()
        {
            return $this->_prefersSequences;
        }

        /**
         * {@inheritdoc}
         */
        public function getSequenceNextValSQL($sequenceName)
        {
            return $this->_sequenceNextValSql;
        }

        /**
         * {@inheritdoc}
         */
        public function getBooleanTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getIntegerTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getBigIntTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getSmallIntTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        protected function _getCommonIntegerTypeDeclarationSQL(array $columnDef)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getVarcharTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getClobTypeDeclarationSQL(array $field)
        {
        }

        public function getCurrentDatabaseExpression(): string
        {
            return "''";
        }

        /* MOCK API */

        /**
         * @param bool $bool
         *
         * @return void
         */
        public function setPrefersIdentityColumns($bool)
        {
            $this->_prefersIdentityColumns = $bool;
        }

        /**
         * @param bool $bool
         *
         * @return void
         */
        public function setPrefersSequences($bool)
        {
            $this->_prefersSequences = $bool;
        }

        /**
         * @param string $sql
         *
         * @return void
         */
        public function setSequenceNextValSql($sql)
        {
            $this->_sequenceNextValSql = $sql;
        }

        /**
         * {@inheritdoc}
         */
        public function getName()
        {
            return 'mock';
        }

        /**
         * {@inheritdoc}
         */
        protected function initializeDoctrineTypeMappings()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getBlobTypeDeclarationSQL(array $field)
        {
            throw DBALException::notSupported(__METHOD__);
        }
    }
} else {
    class DatabasePlatformMock extends AbstractPlatform
    {
        /**
         * @var string
         */
        private $_sequenceNextValSql = "";

        /**
         * @var bool
         */
        private $_prefersIdentityColumns = true;

        /**
         * @var bool
         */
        private $_prefersSequences = false;

        /**
         * {@inheritdoc}
         */
        public function prefersIdentityColumns()
        {
            return $this->_prefersIdentityColumns;
        }

        /**
         * {@inheritdoc}
         */
        public function prefersSequences()
        {
            return $this->_prefersSequences;
        }

        /**
         * {@inheritdoc}
         */
        public function supportsIdentityColumns()
        {
            return $this->_prefersIdentityColumns;
        }

        /**
         * {@inheritdoc}
         */
        public function supportsSequences()
        {
            return $this->_prefersSequences;
        }

        /**
         * {@inheritdoc}
         */
        public function getSequenceNextValSQL($sequenceName)
        {
            return $this->_sequenceNextValSql;
        }

        /**
         * {@inheritdoc}
         */
        public function getBooleanTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getIntegerTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getBigIntTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getSmallIntTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        protected function _getCommonIntegerTypeDeclarationSQL(array $columnDef)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getVarcharTypeDeclarationSQL(array $field)
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getClobTypeDeclarationSQL(array $field)
        {
        }

        /* MOCK API */

        /**
         * @param bool $bool
         *
         * @return void
         */
        public function setPrefersIdentityColumns($bool)
        {
            $this->_prefersIdentityColumns = $bool;
        }

        /**
         * @param bool $bool
         *
         * @return void
         */
        public function setPrefersSequences($bool)
        {
            $this->_prefersSequences = $bool;
        }

        /**
         * @param string $sql
         *
         * @return void
         */
        public function setSequenceNextValSql($sql)
        {
            $this->_sequenceNextValSql = $sql;
        }

        /**
         * {@inheritdoc}
         */
        public function getName()
        {
            return 'mock';
        }

        /**
         * {@inheritdoc}
         */
        protected function initializeDoctrineTypeMappings()
        {
        }

        /**
         * {@inheritdoc}
         */
        public function getBlobTypeDeclarationSQL(array $field)
        {
            throw DBALException::notSupported(__METHOD__);
        }
    }
}
