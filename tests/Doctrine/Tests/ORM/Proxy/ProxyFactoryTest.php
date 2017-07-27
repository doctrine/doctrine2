<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Proxy;

use Doctrine\ORM\Proxy\Factory\DefaultProxyResolver;
use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use Doctrine\ORM\Configuration\ProxyConfiguration;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\Proxy\Factory\StaticProxyFactory;
use Doctrine\ORM\Reflection\RuntimeReflectionService;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\UnitOfWorkMock;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\ECommerce\ECommerceFeature;
use Doctrine\Tests\OrmTestCase;

/**
 * Test the proxy generator. Its work is generating on-the-fly subclasses of a given model, which implement the Proxy pattern.
 * @author Giorgio Sironi <piccoloprincipeazzurro@gmail.com>
 */
class ProxyFactoryTest extends OrmTestCase
{
    /**
     * @var ConnectionMock
     */
    private $connectionMock;

    /**
     * @var UnitOfWorkMock
     */
    private $uowMock;

    /**
     * @var EntityManagerMock
     */
    private $emMock;

    /**
     * @var \Doctrine\ORM\Proxy\ProxyFactory
     */
    private $proxyFactory;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        parent::setUp();

        $this->connectionMock = new ConnectionMock([], new DriverMock());
        $this->emMock         = EntityManagerMock::create($this->connectionMock);
        $this->uowMock        = new UnitOfWorkMock($this->emMock);

        $this->emMock->setUnitOfWork($this->uowMock);

        $proxyConfiguration = new ProxyConfiguration();

        $proxyConfiguration->setDirectory(sys_get_temp_dir());
        $proxyConfiguration->setNamespace('Proxies');
        $proxyConfiguration->setAutoGenerate(ProxyFactory::AUTOGENERATE_ALWAYS);
        $proxyConfiguration->setResolver(new DefaultProxyResolver(
            $proxyConfiguration->getNamespace(),
            $proxyConfiguration->getDirectory()
        ));

        $this->proxyFactory = new StaticProxyFactory($this->emMock, $proxyConfiguration);
    }

    public function testReferenceProxyDelegatesLoadingToThePersister()
    {
        $identifier = ['id' => 42];
        $proxyClass = 'Proxies\__CG__\Doctrine\Tests\Models\ECommerce\ECommerceFeature';

        $classMetaData = $this->emMock->getClassMetadata(ECommerceFeature::class);

        $persister = $this
            ->getMockBuilder(BasicEntityPersister::class)
            ->setConstructorArgs([$this->emMock, $classMetaData])
            ->setMethods(['load'])
            ->getMock();

        $persister
            ->expects($this->atLeastOnce())
            ->method('load')
            ->with($this->equalTo($identifier), $this->isInstanceOf($proxyClass))
            ->will($this->returnValue(new \stdClass()));

        $this->uowMock->setEntityPersister(ECommerceFeature::class, $persister);

        $proxy = $this->proxyFactory->getProxy(ECommerceFeature::class, $identifier);

        $proxy->getDescription();
    }

    /**
     * @group DDC-1771
     */
    public function testSkipAbstractClassesOnGeneration()
    {
        $cm = new ClassMetadata(AbstractClass::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        self::assertNotNull($cm->getReflectionClass());

        $num = $this->proxyFactory->generateProxyClasses([$cm]);

        self::assertEquals(0, $num, "No proxies generated.");
    }

    /**
     * @group DDC-2432
     */
    public function testFailedProxyLoadingDoesNotMarkTheProxyAsInitialized()
    {
        $classMetaData = $this->emMock->getClassMetadata(ECommerceFeature::class);

        $persister = $this
            ->getMockBuilder(BasicEntityPersister::class)
            ->setConstructorArgs([$this->emMock, $classMetaData])
            ->setMethods(['load'])
            ->getMock();

        $persister
            ->expects($this->atLeastOnce())
            ->method('load')
            ->will($this->returnValue(null));

        $this->uowMock->setEntityPersister(ECommerceFeature::class, $persister);

        /* @var $proxy \Doctrine\Common\Proxy\Proxy */
        $proxy = $this->proxyFactory->getProxy(ECommerceFeature::class, ['id' => 42]);

        try {
            $proxy->getDescription();
            $this->fail('An exception was expected to be raised');
        } catch (EntityNotFoundException $exception) {
        }

        self::assertFalse($proxy->__isInitialized());
    }

    /**
     * @group DDC-2432
     */
    public function testFailedProxyCloningDoesNotMarkTheProxyAsInitialized()
    {
        $classMetaData = $this->emMock->getClassMetadata(ECommerceFeature::class);

        $persister = $this
            ->getMockBuilder(BasicEntityPersister::class)
            ->setConstructorArgs([$this->emMock, $classMetaData])
            ->setMethods(['load'])
            ->getMock();

        $persister
            ->expects($this->atLeastOnce())
            ->method('load')
            ->will($this->returnValue(null));

        $this->uowMock->setEntityPersister(ECommerceFeature::class, $persister);

        /* @var $proxy \Doctrine\Common\Proxy\Proxy */
        $proxy = $this->proxyFactory->getProxy(ECommerceFeature::class, ['id' => 42]);

        try {
            $cloned = clone $proxy;
            $this->fail('An exception was expected to be raised');
        } catch (EntityNotFoundException $exception) {
        }

        self::assertFalse($proxy->__isInitialized());
    }

    public function testProxyClonesParentFields()
    {
        $companyEmployee = new CompanyEmployee();
        $companyEmployee->setSalary(1000); // A property on the CompanyEmployee
        $companyEmployee->setName('Bob'); // A property on the parent class, CompanyPerson

        // Set the id of the CompanyEmployee (which is in the parent CompanyPerson)
        $property = new \ReflectionProperty(CompanyPerson::class, 'id');

        $property->setAccessible(true);
        $property->setValue($companyEmployee, 42);

        $classMetaData = $this->emMock->getClassMetadata(CompanyEmployee::class);

        $persister = $this
            ->getMockBuilder(BasicEntityPersister::class)
            ->setConstructorArgs([$this->emMock, $classMetaData])
            ->setMethods(['load'])
            ->getMock();

        $persister
            ->expects(self::atLeastOnce())
            ->method('load')
            ->willReturn($companyEmployee);

        $this->uowMock->setEntityPersister(CompanyEmployee::class, $persister);

        /* @var $proxy \Doctrine\Common\Proxy\Proxy */
        $proxy = $this->proxyFactory->getProxy(CompanyEmployee::class, ['id' => 42]);

        /* @var $cloned CompanyEmployee */
        $cloned = clone $proxy;

        self::assertSame(42, $cloned->getId(), 'Expected the Id to be cloned');
        self::assertSame(1000, $cloned->getSalary(), 'Expect properties on the CompanyEmployee class to be cloned');
        self::assertSame('Bob', $cloned->getName(), 'Expect properties on the CompanyPerson class to be cloned');
    }
}

abstract class AbstractClass
{

}
