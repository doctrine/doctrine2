<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Proxy;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\UnitOfWorkMock;
use Doctrine\Tests\Models\ECommerce\ECommerceFeature;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;

/**
 * Test the proxy generator. Its work is generating on-the-fly subclasses of a given model, which implement the Proxy pattern.
 */
class ProxyFactoryTest extends OrmTestCase
{
    private UnitOfWorkMock $uowMock;
    private EntityManagerMock $emMock;
    private ProxyFactory $proxyFactory;

    protected function setUp(): void
    {
        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $connection = $this->createConnectionStub($platform);

        $this->emMock  = new EntityManagerMock($connection);
        $this->uowMock = new UnitOfWorkMock($this->emMock);
        $this->emMock->setUnitOfWork($this->uowMock);
        $this->proxyFactory = new ProxyFactory($this->emMock);
    }

    public function testReferenceProxyDelegatesLoadingToThePersister(): void
    {
        $identifier = ['id' => 42];
        $persister  = $this->getMockBuilder(BasicEntityPersister::class)
            ->onlyMethods(['loadById'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->uowMock->setEntityPersister(ECommerceFeature::class, $persister);

        $proxy = $this->proxyFactory->getProxy(ECommerceFeature::class, $identifier);

        $persister
            ->expects(self::atLeastOnce())
            ->method('loadById')
            ->with(self::equalTo($identifier))
            ->willReturn($proxy);

        $proxy->getDescription();
    }

    #[Group('DDC-2432')]
    public function testFailedProxyLoadingDoesNotMarkTheProxyAsInitialized(): void
    {
        $persister = $this->getMockBuilder(BasicEntityPersister::class)
            ->onlyMethods(['load'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->uowMock->setEntityPersister(ECommerceFeature::class, $persister);

        $proxy = $this->proxyFactory->getProxy(ECommerceFeature::class, ['id' => 42]);

        $persister
            ->expects(self::atLeastOnce())
            ->method('load')
            ->willReturn(null);

        try {
            $proxy->getDescription();
            self::fail('An exception was expected to be raised');
        } catch (EntityNotFoundException) {
        }

        self::assertUninitializedLazyObject($proxy);
    }

    private static function assertUninitializedLazyObject(object $proxy): void
    {
        $reflectionClass = new ReflectionClass($proxy);
        self::assertTrue($reflectionClass->isUninitializedLazyObject($proxy));
    }

    #[Group('DDC-2432')]
    public function testFailedProxyCloningDoesNotMarkTheProxyAsInitialized(): void
    {
        $persister = $this->getMockBuilder(BasicEntityPersister::class)
            ->onlyMethods(['load', 'getClassMetadata'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->uowMock->setEntityPersister(ECommerceFeature::class, $persister);

        $proxy = $this->proxyFactory->getProxy(ECommerceFeature::class, ['id' => 42]);

        $persister
            ->expects(self::atLeastOnce())
            ->method('load')
            ->willReturn(null);

        try {
            $cloned = clone $proxy;
            $cloned->__load();
            self::fail('An exception was expected to be raised');
        } catch (EntityNotFoundException) {
        }

        self::assertUninitializedLazyObject($proxy);
    }

    public function testProxyFactoryAcceptsNullProxyArgs(): void
    {
        $this->proxyFactory = new ProxyFactory(
            $this->emMock,
            null,
            null,
        );
        $proxy              = $this->proxyFactory->getProxy(
            ECommerceFeature::class,
            ['id' => 42],
        );
        $reflection         = new ReflectionClass($proxy);

        self::assertTrue($reflection->isUninitializedLazyObject($proxy));
    }
}

abstract class AbstractClass
{
}
