<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\UnitOfWork;

/**
 * Special EntityManager mock used for testing purposes.
 */
class EntityManagerMock extends EntityManager
{
    private UnitOfWork|null $_uowMock            = null;
    private ProxyFactory|null $_proxyFactoryMock = null;

    public function __construct(Connection $conn, Configuration|null $config = null, EventManager|null $eventManager = null)
    {
        if ($config === null) {
            $config = new Configuration();
            $config->setMetadataDriverImpl(AttributeDriverFactory::createAttributeDriver());
        }

        parent::__construct($conn, $config, $eventManager);
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->_uowMock ?? parent::getUnitOfWork();
    }

    /* Mock API */

    /**
     * Sets a (mock) UnitOfWork that will be returned when getUnitOfWork() is called.
     */
    public function setUnitOfWork(UnitOfWork $uow): void
    {
        $this->_uowMock = $uow;
    }

    public function setProxyFactory(ProxyFactory $proxyFactory): void
    {
        $this->_proxyFactoryMock = $proxyFactory;
    }

    public function getProxyFactory(): ProxyFactory
    {
        return $this->_proxyFactoryMock ?? parent::getProxyFactory();
    }
}
