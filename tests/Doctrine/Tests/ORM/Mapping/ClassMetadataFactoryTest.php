<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnClassMetadataNotFoundEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Driver\MappingDriver;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Reflection\RuntimeReflectionService;
use Doctrine\ORM\Sequencing\Generator;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\MetadataDriverMock;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\DDC4006\DDC4006User;
use Doctrine\Tests\Models\JoinedInheritanceType\AnotherChildClass;
use Doctrine\Tests\Models\JoinedInheritanceType\ChildClass;
use Doctrine\Tests\Models\JoinedInheritanceType\RootClass;
use Doctrine\Tests\Models\Quote;
use Doctrine\Tests\OrmTestCase;
use DoctrineGlobalArticle;
use function array_search;
use function reset;
use function sprintf;

class ClassMetadataFactoryTest extends OrmTestCase
{
    public function testGetMetadataForSingleClass() : void
    {
        $mockDriver    = new MetadataDriverMock();
        $entityManager = $this->createEntityManager($mockDriver);

        $conn         = $entityManager->getConnection();
        $mockPlatform = $conn->getDatabasePlatform();
        $mockPlatform->setPrefersSequences(true);
        $mockPlatform->setPrefersIdentityColumns(false);

        $cm1 = $this->createValidClassMetadata();

        // SUT
        $cmf = new ClassMetadataFactory();
        $cmf->setEntityManager($entityManager);
        $cmf->setMetadataFor($cm1->getClassName(), $cm1);

        // Prechecks
        self::assertCount(0, $cm1->getAncestorsIterator());
        self::assertEquals(Mapping\InheritanceType::NONE, $cm1->inheritanceType);
        self::assertEquals(Mapping\GeneratorType::AUTO, $cm1->getProperty('id')->getValueGenerator()->getType());
        self::assertTrue($cm1->hasField('name'));
        self::assertCount(4, $cm1->getDeclaredPropertiesIterator()); // 2 fields + 2 associations
        self::assertEquals('group', $cm1->table->getName());

        // Go
        $cmMap1 = $cmf->getMetadataFor($cm1->getClassName());

        self::assertSame($cm1, $cmMap1);
        self::assertEquals('group', $cmMap1->table->getName());
        self::assertCount(0, $cmMap1->getAncestorsIterator());
        self::assertTrue($cmMap1->hasField('name'));
    }

    public function testGetMetadataForThrowsExceptionOnUnknownCustomGeneratorClass() : void
    {
        $cm1 = $this->createValidClassMetadata();

        $cm1->getProperty('id')->setValueGenerator(
            new Mapping\ValueGeneratorMetadata(
                Mapping\GeneratorType::CUSTOM,
                [
                    'class' => 'NotExistingGenerator',
                    'arguments' => [],
                ]
            )
        );

        $cmf = $this->createTestFactory();

        $cmf->setMetadataForClass($cm1->getClassName(), $cm1);

        $this->expectException(ORMException::class);

        $actual = $cmf->getMetadataFor($cm1->getClassName());
    }

    public function testGetMetadataForThrowsExceptionOnMissingCustomGeneratorDefinition() : void
    {
        $cm1 = $this->createValidClassMetadata();

        $cm1->getProperty('id')->setValueGenerator(
            new Mapping\ValueGeneratorMetadata(Mapping\GeneratorType::CUSTOM)
        );

        $cmf = $this->createTestFactory();

        $cmf->setMetadataForClass($cm1->getClassName(), $cm1);

        $this->expectException(ORMException::class);

        $actual = $cmf->getMetadataFor($cm1->getClassName());
    }

    public function testHasGetMetadataNamespaceSeparatorIsNotNormalized() : void
    {
        require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

        $metadataDriver = $this->createAnnotationDriver([__DIR__ . '/../../Models/Global/']);

        $entityManager = $this->createEntityManager($metadataDriver);

        $mf = $entityManager->getMetadataFactory();

        self::assertSame(
            $mf->getMetadataFor(DoctrineGlobalArticle::class),
            $mf->getMetadataFor('\\' . DoctrineGlobalArticle::class)
        );
        self::assertTrue($mf->hasMetadataFor(DoctrineGlobalArticle::class));
        self::assertTrue($mf->hasMetadataFor('\\' . DoctrineGlobalArticle::class));
    }

    /**
     * @group DDC-1512
     */
    public function testIsTransient() : void
    {
        $cmf    = new ClassMetadataFactory();
        $driver = $this->createMock(MappingDriver::class);
        $driver->expects($this->at(0))
               ->method('isTransient')
               ->with($this->equalTo(CmsUser::class))
               ->will($this->returnValue(true));
        $driver->expects($this->at(1))
               ->method('isTransient')
               ->with($this->equalTo(CmsArticle::class))
               ->will($this->returnValue(false));

        $em = $this->createEntityManager($driver);

        self::assertTrue($em->getMetadataFactory()->isTransient(CmsUser::class));
        self::assertFalse($em->getMetadataFactory()->isTransient(CmsArticle::class));
    }

    public function testAddDefaultDiscriminatorMap() : void
    {
        $cmf    = new ClassMetadataFactory();
        $driver = $this->createAnnotationDriver([__DIR__ . '/../../Models/JoinedInheritanceType/']);
        $em     = $this->createEntityManager($driver);
        $cmf->setEntityManager($em);

        $rootMetadata                 = $cmf->getMetadataFor(RootClass::class);
        $childMetadata                = $cmf->getMetadataFor(ChildClass::class);
        $anotherChildMetadata         = $cmf->getMetadataFor(AnotherChildClass::class);
        $rootDiscriminatorMap         = $rootMetadata->discriminatorMap;
        $childDiscriminatorMap        = $childMetadata->discriminatorMap;
        $anotherChildDiscriminatorMap = $anotherChildMetadata->discriminatorMap;

        $rootClass         = RootClass::class;
        $childClass        = ChildClass::class;
        $anotherChildClass = AnotherChildClass::class;

        $rootClassKey         = array_search($rootClass, $rootDiscriminatorMap, true);
        $childClassKey        = array_search($childClass, $rootDiscriminatorMap, true);
        $anotherChildClassKey = array_search($anotherChildClass, $rootDiscriminatorMap, true);

        self::assertEquals('rootclass', $rootClassKey);
        self::assertEquals('childclass', $childClassKey);
        self::assertEquals('anotherchildclass', $anotherChildClassKey);

        self::assertEquals($childDiscriminatorMap, $rootDiscriminatorMap);
        self::assertEquals($anotherChildDiscriminatorMap, $rootDiscriminatorMap);

        // ClassMetadataFactory::addDefaultDiscriminatorMap shouldn't be called again, because the
        // discriminator map is already cached
        $cmf = $this->getMockBuilder(ClassMetadataFactory::class)->setMethods(['addDefaultDiscriminatorMap'])->getMock();
        $cmf->setEntityManager($em);
        $cmf->expects($this->never())
            ->method('addDefaultDiscriminatorMap');

        $rootMetadata = $cmf->getMetadataFor(RootClass::class);
    }

    public function testGetAllMetadataWorksWithBadConnection() : void
    {
        // DDC-3551
        $conn       = $this->createMock(Connection::class);
        $mockDriver = new MetadataDriverMock();
        $conn->expects($this->any())
            ->method('getEventManager')
            ->willReturn(new EventManager());
        $em = $this->createEntityManager($mockDriver, $conn);

        $conn->expects($this->any())
            ->method('getDatabasePlatform')
            ->will($this->throwException(new \Exception('Exception thrown in test when calling getDatabasePlatform')));

        $cmf = new ClassMetadataFactory();
        $cmf->setEntityManager($em);

        // getting all the metadata should work, even if get DatabasePlatform blows up
        $metadata = $cmf->getAllMetadata();
        // this will just be an empty array - there was no error
        self::assertEquals([], $metadata);
    }

    protected function createEntityManager($metadataDriver, $conn = null)
    {
        $driverMock = new DriverMock();
        $config     = new Configuration();

        $config->setProxyDir(__DIR__ . '/../../Proxies');
        $config->setProxyNamespace('Doctrine\Tests\Proxies');

        if (! $conn) {
            $conn = new ConnectionMock([], $driverMock, $config, new EventManager());
        }
        $eventManager = $conn->getEventManager();

        $config->setMetadataDriverImpl($metadataDriver);

        return EntityManagerMock::create($conn, $config, $eventManager);
    }

    /**
     * @return ClassMetadataFactoryTestSubject
     */
    protected function createTestFactory()
    {
        $mockDriver    = new MetadataDriverMock();
        $entityManager = $this->createEntityManager($mockDriver);
        $cmf           = new ClassMetadataFactoryTestSubject();
        $cmf->setEntityManager($entityManager);
        return $cmf;
    }

    /**
     * @return ClassMetadata
     */
    protected function createValidClassMetadata()
    {
        // Self-made metadata
        $metadataBuildingContext = new Mapping\ClassMetadataBuildingContext(
            $this->createMock(ClassMetadataFactory::class),
            new RuntimeReflectionService()
        );

        $cm1 = new ClassMetadata(TestEntity1::class, $metadataBuildingContext);

        $tableMetadata = new Mapping\TableMetadata();
        $tableMetadata->setName('group');

        $cm1->setTable($tableMetadata);

        // Add a mapped field
        $fieldMetadata = new Mapping\FieldMetadata('id');

        $fieldMetadata->setType(Type::getType('integer'));
        $fieldMetadata->setPrimaryKey(true);
        $fieldMetadata->setValueGenerator(new Mapping\ValueGeneratorMetadata(Mapping\GeneratorType::AUTO));

        $cm1->addProperty($fieldMetadata);

        // Add a mapped field
        $fieldMetadata = new Mapping\FieldMetadata('name');

        $fieldMetadata->setType(Type::getType('string'));

        $cm1->addProperty($fieldMetadata);

        // and a mapped association
        $association = new Mapping\OneToOneAssociationMetadata('other');

        $association->setTargetEntity(TestEntity1::class);
        $association->setMappedBy('this');

        $cm1->addProperty($association);

        // and an association on the owning side
        $joinColumns = [];

        $joinColumn = new Mapping\JoinColumnMetadata();

        $joinColumn->setColumnName('other_id');
        $joinColumn->setReferencedColumnName('id');

        $joinColumns[] = $joinColumn;

        $association = new Mapping\OneToOneAssociationMetadata('association');

        $association->setJoinColumns($joinColumns);
        $association->setTargetEntity(TestEntity1::class);

        $cm1->addProperty($association);

        return $cm1;
    }

    /**
     * @group DDC-1845
     */
    public function testQuoteMetadata() : void
    {
        $cmf    = new ClassMetadataFactory();
        $driver = $this->createAnnotationDriver([__DIR__ . '/../../Models/Quote/']);
        $em     = $this->createEntityManager($driver);
        $cmf->setEntityManager($em);

        $userMetadata    = $cmf->getMetadataFor(Quote\User::class);
        $phoneMetadata   = $cmf->getMetadataFor(Quote\Phone::class);
        $groupMetadata   = $cmf->getMetadataFor(Quote\Group::class);
        $addressMetadata = $cmf->getMetadataFor(Quote\Address::class);

        // Phone Class Metadata
        self::assertNotNull($phoneMetadata->getProperty('number'));
        self::assertEquals('phone-number', $phoneMetadata->getProperty('number')->getColumnName());

        $user                = $phoneMetadata->getProperty('user');
        $userJoinColumns     = $user->getJoinColumns();
        $phoneUserJoinColumn = reset($userJoinColumns);

        self::assertEquals('user-id', $phoneUserJoinColumn->getColumnName());
        self::assertEquals('user-id', $phoneUserJoinColumn->getReferencedColumnName());

        // Address Class Metadata
        self::assertNotNull($addressMetadata->getProperty('id'));
        self::assertNotNull($addressMetadata->getProperty('zip'));
        self::assertEquals('address-id', $addressMetadata->getProperty('id')->getColumnName());
        self::assertEquals('address-zip', $addressMetadata->getProperty('zip')->getColumnName());

        // User Class Metadata
        self::assertNotNull($userMetadata->getProperty('id'));
        self::assertNotNull($userMetadata->getProperty('name'));
        self::assertEquals('user-id', $userMetadata->getProperty('id')->getColumnName());
        self::assertEquals('user-name', $userMetadata->getProperty('name')->getColumnName());

        $group               = $groupMetadata->getProperty('parent');
        $groupJoinColumns    = $group->getJoinColumns();
        $groupUserJoinColumn = reset($groupJoinColumns);

        self::assertEquals('parent-id', $groupUserJoinColumn->getColumnName());
        self::assertEquals('group-id', $groupUserJoinColumn->getReferencedColumnName());

        $user                  = $addressMetadata->getProperty('user');
        $userJoinColumns       = $user->getJoinColumns();
        $addressUserJoinColumn = reset($userJoinColumns);

        self::assertEquals('user-id', $addressUserJoinColumn->getColumnName());
        self::assertEquals('user-id', $addressUserJoinColumn->getReferencedColumnName());

        $groups                       = $userMetadata->getProperty('groups');
        $groupsJoinTable              = $groups->getJoinTable();
        $userGroupsJoinColumns        = $groupsJoinTable->getJoinColumns();
        $userGroupsJoinColumn         = reset($userGroupsJoinColumns);
        $userGroupsInverseJoinColumns = $groupsJoinTable->getInverseJoinColumns();
        $userGroupsInverseJoinColumn  = reset($userGroupsInverseJoinColumns);

        self::assertEquals('quote-users-groups', $groupsJoinTable->getName());
        self::assertEquals('user-id', $userGroupsJoinColumn->getColumnName());
        self::assertEquals('user-id', $userGroupsJoinColumn->getReferencedColumnName());
        self::assertEquals('group-id', $userGroupsInverseJoinColumn->getColumnName());
        self::assertEquals('group-id', $userGroupsInverseJoinColumn->getReferencedColumnName());
    }

    /**
     * @group DDC-3385
     * @group 1181
     * @group 385
     */
    public function testFallbackLoadingCausesEventTriggeringThatCanModifyFetchedMetadata() : void
    {
        $test = $this;

        /** @var ClassMetadata $metadata */
        $metadata     = $this->createMock(ClassMetadata::class);
        $cmf          = new ClassMetadataFactory();
        $mockDriver   = new MetadataDriverMock();
        $em           = $this->createEntityManager($mockDriver);
        $listener     = $this->getMockBuilder(\stdClass::class)->setMethods(['onClassMetadataNotFound'])->getMock();
        $eventManager = $em->getEventManager();

        $cmf->setEntityManager($em);

        $listener
            ->expects($this->any())
            ->method('onClassMetadataNotFound')
            ->will($this->returnCallback(function (OnClassMetadataNotFoundEventArgs $args) use ($metadata, $em, $test) {
                $test->assertNull($args->getFoundMetadata());
                $test->assertSame('Foo', $args->getClassName());
                $test->assertSame($em, $args->getObjectManager());

                $args->setFoundMetadata($metadata);
            }));

        $eventManager->addEventListener([Events::onClassMetadataNotFound], $listener);

        self::assertSame($metadata, $cmf->getMetadataFor('Foo'));
    }

    /**
     * @group DDC-3427
     */
    public function testAcceptsEntityManagerInterfaceInstances() : void
    {
        $classMetadataFactory = new ClassMetadataFactory();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $classMetadataFactory->setEntityManager($entityManager);

        // not really the cleanest way to check it, but we won't add a getter to the CMF just for the sake of testing.
        self::assertAttributeSame($entityManager, 'em', $classMetadataFactory);
    }

    /**
     * @group embedded
     * @group DDC-3305
     */
    public function testRejectsEmbeddableWithoutValidClassName() : void
    {
        $metadata = $this->createValidClassMetadata();

        $metadata->mapEmbedded(
            [
            'fieldName'    => 'embedded',
            'class'        => '',
            'columnPrefix' => false,
            ]
        );

        $cmf = $this->createTestFactory();

        $cmf->setMetadataForClass($metadata->getClassName(), $metadata);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('The embed mapping \'embedded\' misses the \'class\' attribute.');

        $cmf->getMetadataFor($metadata->getClassName());
    }

    /**
     * @group embedded
     * @group DDC-4006
     */
    public function testInheritsIdGeneratorMappingFromEmbeddable() : void
    {
        $cmf    = new ClassMetadataFactory();
        $driver = $this->createAnnotationDriver([__DIR__ . '/../../Models/DDC4006/']);
        $em     = $this->createEntityManager($driver);
        $cmf->setEntityManager($em);

        $userMetadata = $cmf->getMetadataFor(DDC4006User::class);

        self::assertTrue($userMetadata->isIdGeneratorIdentity());
    }
}

/* Test subject class with overridden factory method for mocking purposes */
class ClassMetadataFactoryTestSubject extends ClassMetadataFactory
{
    private $mockMetadata     = [];
    private $requestedClasses = [];

    protected function newClassMetadataInstance(
        string $className,
        ?Mapping\ClassMetadata $parent,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) : ClassMetadata {
        $this->requestedClasses[] = $className;

        if (! isset($this->mockMetadata[$className])) {
            throw new \InvalidArgumentException(sprintf('No mock metadata found for class %s.', $className));
        }

        return $this->mockMetadata[$className];
    }

    public function setMetadataForClass($className, $metadata)
    {
        $this->mockMetadata[$className] = $metadata;
    }

    public function getRequestedClasses()
    {
        return $this->requestedClasses;
    }
}

class TestEntity1
{
    private $id;
    private $name;
    private $other;
    private $association;
    private $embedded;
}

class CustomIdGenerator implements Generator
{
    /**
     * {@inheritdoc}
     */
    public function generate(EntityManagerInterface $em, ?object $entity)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function isPostInsertGenerator() : bool
    {
        return false;
    }
}
