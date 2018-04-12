<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\Common\EventManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\MetadataCollection;
use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use Doctrine\ORM\UnitOfWork;

/**
 * Special EntityManager mock used for testing purposes.
 */
class EntityManagerMock extends EntityManagerDecorator
{
    /** @var UnitOfWork|null */
    private $uowMock;

    /** @var ProxyFactory|null */
    private $proxyFactoryMock;

    public function getWrappedEntityManager() : EntityManagerInterface
    {
        return $this->wrapped;
    }

    /**
     * {@inheritdoc}
     */
    public function getUnitOfWork()
    {
        return $this->uowMock ?? $this->wrapped->getUnitOfWork();
    }

    /**
     * Sets a (mock) UnitOfWork that will be returned when getUnitOfWork() is called.
     *
     * @param UnitOfWork $uow
     *
     * @return void
     */
    public function setUnitOfWork($uow)
    {
        $this->uowMock = $uow;
    }

    /**
     * @param ProxyFactory $proxyFactory
     *
     * @return void
     */
    public function setProxyFactory($proxyFactory)
    {
        $this->proxyFactoryMock = $proxyFactory;
    }

    /**
     * @return ProxyFactory
     */
    public function getProxyFactory()
    {
        return $this->proxyFactoryMock ?? $this->wrapped->getProxyFactory();
    }

    /**
     * Mock factory method to create an EntityManager.
     *
     * {@inheritdoc}
     */
    public static function create($conn, ?Configuration $config = null, ?EventManager $eventManager = null, ?MetadataCollection $metadataCollection = null)
    {
        if ($config === null) {
            $config = new Configuration();

            $config->setProxyDir(__DIR__ . '/../Proxies');
            $config->setProxyNamespace('Doctrine\Tests\Proxies');
            $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver());
        }

        if ($eventManager === null) {
            $eventManager = $conn->getEventManager();
        }

        if ($metadataCollection !== null) {
            $em = EntityManager::createWithClassMetadata($conn, $config, $eventManager, $metadataCollection);
        } else {
            $em = EntityManager::create($conn, $config, $eventManager);
        }

        return new EntityManagerMock($em);
    }
}
