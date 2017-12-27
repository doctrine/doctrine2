<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\Common\EventManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use Doctrine\ORM\Utility\IdentifierFlattener;

/**
 * Special EntityManager mock used for testing purposes.
 */
class EntityManagerMock extends EntityManagerDecorator
{
    /**
     * @var \Doctrine\ORM\UnitOfWork|null
     */
    private $uowMock;

    /**
     * @return EntityManagerInterface
     */
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
     * @param \Doctrine\ORM\UnitOfWork $uow
     *
     * @return void
     */
    public function setUnitOfWork($uow)
    {
        $this->uowMock = $uow;
    }

    /**
     * @return \Doctrine\ORM\Proxy\Factory\ProxyFactory
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
    public static function create($conn, Configuration $config = null, EventManager $eventManager = null)
    {
        if (null === $config) {
            $config = new Configuration();

            $config->setProxyNamespace('Doctrine\Tests\Proxies');
            $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
            $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver());
        }

        if (null === $eventManager) {
            $eventManager = $conn->getEventManager();
        }

        $em      = EntityManager::create($conn, $config, $eventManager);
        $emMock  = new EntityManagerMock($em);
        $uowMock = new UnitOfWorkMock($emMock);

        // Ugly hacks due to cyclic dependencies
        // in the tests, we cannot afford having two different UnitOfWork instances
        $uowReflection                 = new \ReflectionProperty($em, 'unitOfWork');
        $identifierFlattenerReflection = new \ReflectionProperty($em, 'identifierFlattener');

        $uowReflection->setAccessible(true);
        $identifierFlattenerReflection->setAccessible(true);

        $uowReflection->setValue($em, $uowMock);
        $identifierFlattenerReflection->setValue($em, new IdentifierFlattener($uowMock, $em->getMetadataFactory()));

        return $emMock;
    }
}
