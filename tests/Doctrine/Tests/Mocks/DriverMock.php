<?php

namespace Doctrine\Tests\Mocks;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * Mock class for Driver.
 */
if (class_exists(Result::class)) {
    class DriverMock implements Driver
    {
        /**
         * @var \Doctrine\DBAL\Platforms\AbstractPlatform|null
         */
        private $_platformMock;

        /**
         * @var \Doctrine\DBAL\Schema\AbstractSchemaManager|null
         */
        private $_schemaManagerMock;

        /**
         * @var \Doctrine\DBAL\Driver\API\ExceptionConverter|null
         */
        private $_exceptionConverterMock;

        /**
         * {@inheritdoc}
         */
        public function connect(array $params, $username = null, $password = null, array $driverOptions = [])
        {
            return new DriverConnectionMock();
        }

        /**
         * {@inheritdoc}
         */
        public function getDatabasePlatform()
        {
            if (!$this->_platformMock) {
                $this->_platformMock = new DatabasePlatformMock;
            }
            return $this->_platformMock;
        }

        /**
         * {@inheritdoc}
         */
        public function getSchemaManager(Connection $conn, AbstractPlatform $platform)
        {
            if ($this->_schemaManagerMock == null) {
                return new SchemaManagerMock($conn, $platform);
            }

            return $this->_schemaManagerMock;
        }

        public function getExceptionConverter(): ExceptionConverter
        {
            if ($this->_exceptionConverterMock == null) {
                return new ExceptionConverterMock();
            }

            return $this->_exceptionConverterMock;
        }

        /* MOCK API */

        /**
         * @param \Doctrine\DBAL\Platforms\AbstractPlatform $platform
         *
         * @return void
         */
        public function setDatabasePlatform(AbstractPlatform $platform)
        {
            $this->_platformMock = $platform;
        }

        /**
         * @param \Doctrine\DBAL\Schema\AbstractSchemaManager $sm
         *
         * @return void
         */
        public function setSchemaManager(AbstractSchemaManager $sm)
        {
            $this->_schemaManagerMock = $sm;
        }

        /**
         * @param \Doctrine\DBAL\Driver\API\ExceptionConverter $exceptionConverter
         *
         * @return void
         */
        public function setExceptionConverter(ExceptionConverter $exceptionConverter)
        {
            $this->_exceptionConverterMock = $exceptionConverter;
        }

        /**
         * {@inheritdoc}
         */
        public function getDatabase(Connection $conn)
        {
            return;
        }

        public function convertExceptionCode(\Exception $exception)
        {
            return 0;
        }
    }
} else {
    class DriverMock implements Driver
    {
        /**
         * @var \Doctrine\DBAL\Platforms\AbstractPlatform|null
         */
        private $_platformMock;

        /**
         * @var \Doctrine\DBAL\Schema\AbstractSchemaManager|null
         */
        private $_schemaManagerMock;

        /**
         * {@inheritdoc}
         */
        public function connect(array $params, $username = null, $password = null, array $driverOptions = [])
        {
            return new DriverConnectionMock();
        }

        /**
         * {@inheritdoc}
         */
        public function getDatabasePlatform()
        {
            if (!$this->_platformMock) {
                $this->_platformMock = new DatabasePlatformMock;
            }
            return $this->_platformMock;
        }

        /**
         * {@inheritdoc}
         */
        public function getSchemaManager(Connection $conn)
        {
            if ($this->_schemaManagerMock == null) {
                return new SchemaManagerMock($conn);
            }

            return $this->_schemaManagerMock;
        }

        /* MOCK API */

        /**
         * @param \Doctrine\DBAL\Platforms\AbstractPlatform $platform
         *
         * @return void
         */
        public function setDatabasePlatform(AbstractPlatform $platform)
        {
            $this->_platformMock = $platform;
        }

        /**
         * @param \Doctrine\DBAL\Schema\AbstractSchemaManager $sm
         *
         * @return void
         */
        public function setSchemaManager(AbstractSchemaManager $sm)
        {
            $this->_schemaManagerMock = $sm;
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
        public function getDatabase(Connection $conn)
        {
            return;
        }

        public function convertExceptionCode(\Exception $exception)
        {
            return 0;
        }
    }
}
