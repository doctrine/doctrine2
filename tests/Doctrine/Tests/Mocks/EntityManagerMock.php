<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\Common\EventManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;

/**
 * Special EntityManager mock used for testing purposes.
 */
class EntityManagerMock extends EntityManager
{
    /**
     * @var \Doctrine\ORM\UnitOfWork|null
     */
    private $uowMock;

    /**
     * @var \Doctrine\ORM\Proxy\Factory\ProxyFactory|null
     */
    private $proxyFactoryMock;

    /**
     * {@inheritdoc}
     */
    public function getUnitOfWork()
    {
        return isset($this->uowMock) ? $this->uowMock : parent::getUnitOfWork();
    }

    /* Mock API */

    /**
     * Sets a (mock) UnitOfWork that will be returned when getUnitOfWork() is called.
     *
     * @param \Doctrine\ORM\UnitOfWork $uow
     *
     * @return void
     */
    public function setUnitOfWork($uow)
    {
        $this->uowMock = $uow;
    }

    /**
     * @param \Doctrine\ORM\Proxy\Factory\ProxyFactory $proxyFactory
     *
     * @return void
     */
    public function setProxyFactory($proxyFactory)
    {
        $this->proxyFactoryMock = $proxyFactory;
    }

    /**
     * @return \Doctrine\ORM\Proxy\Factory\ProxyFactory
     */
    public function getProxyFactory()
    {
        return isset($this->proxyFactoryMock) ? $this->proxyFactoryMock : parent::getProxyFactory();
    }

    /**
     * Mock factory method to create an EntityManager.
     *
     * {@inheritdoc}
     */
    public static function create($conn, Configuration $config = null, EventManager $eventManager = null)
    {
        if (null === $config) {
            $config = new Configuration();
            $config->setProxyDir(__DIR__ . '/../Proxies');
            $config->setProxyNamespace('Doctrine\Tests\Proxies');
            $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([]));
        }
        if (null === $eventManager) {
            $eventManager = new EventManager();
        }

        return new EntityManagerMock($conn, $config, $eventManager);
    }
}
