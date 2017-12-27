<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\EventManager;
use Doctrine\Common\NotifyPropertyChanged;
use Doctrine\Common\PropertyChangedListener;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataBuildingContext;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\GeneratorType;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Reflection\RuntimeReflectionService;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\EntityPersisterMock;
use Doctrine\Tests\Mocks\UnitOfWorkMock;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\GeoNames\City;
use Doctrine\Tests\Models\GeoNames\Country;
use Doctrine\Tests\Models\UniDirectionalManyToMany\Inverse;
use Doctrine\Tests\Models\UniDirectionalManyToMany\Owning;
use Doctrine\Tests\OrmTestCase;
use ProxyManager\Proxy\GhostObjectInterface;
use stdClass;

/**
 * UnitOfWork tests.
 */
class UnitOfWorkTest extends OrmTestCase
{
    /**
     * SUT
     *
     * @var UnitOfWorkMock
     */
    private $unitOfWork;

    /**
     * Provides a sequence mock to the UnitOfWork
     *
     * @var ConnectionMock
     */
    private $connectionMock;

    /**
     * The EntityManager mock that provides the mock persisters
     *
     * @var EntityManagerMock
     */
    private $emMock;

    /**
     * @var EventManager|\PHPUnit_Framework_MockObject_MockObject
     */
    private $eventManager;

    /**
     * @var ClassMetadataBuildingContext|\PHPUnit_Framework_MockObject_MockObject
     */
    private $metadataBuildingContext;

    protected function setUp()
    {
        parent::setUp();

        $this->metadataBuildingContext = new ClassMetadataBuildingContext(
            $this->createMock(ClassMetadataFactory::class),
            new RuntimeReflectionService()
        );

        $this->eventManager   = $this->getMockBuilder(EventManager::class)->getMock();
        $this->connectionMock = new ConnectionMock([], new DriverMock(), null, $this->eventManager);
        $this->emMock         = EntityManagerMock::create($this->connectionMock, null, $this->eventManager);
        $this->unitOfWork     = $this->emMock->getUnitOfWork();

        $this->emMock->setUnitOfWork($this->unitOfWork);
    }

    public function testRegisterRemovedOnNewEntityIsIgnored()
    {
        $user = new ForumUser();
        $user->username = 'romanb';
        self::assertFalse($this->unitOfWork->isScheduledForDelete($user));
        $this->unitOfWork->scheduleForDelete($user);
        self::assertFalse($this->unitOfWork->isScheduledForDelete($user));
    }


    /* Operational tests */

    public function testSavingSingleEntityWithIdentityColumnForcesInsert()
    {
        // Setup fake persister and id generator for identity generation
        $userPersister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(ForumUser::class));
        $this->unitOfWork->setEntityPersister(ForumUser::class, $userPersister);
        $userPersister->setMockIdGeneratorType(GeneratorType::IDENTITY);

        // Test
        $user = new ForumUser();
        $user->username = 'romanb';
        $this->unitOfWork->persist($user);

        // Check
        self::assertCount(0, $userPersister->getInserts());
        self::assertCount(0, $userPersister->getUpdates());
        self::assertCount(0, $userPersister->getDeletes());
        self::assertFalse($this->unitOfWork->isInIdentityMap($user));
        // should no longer be scheduled for insert
        self::assertTrue($this->unitOfWork->isScheduledForInsert($user));

        // Now lets check whether a subsequent commit() does anything
        $userPersister->reset();

        // Test
        $this->unitOfWork->commit();

        // Check.
        self::assertCount(1, $userPersister->getInserts());
        self::assertCount(0, $userPersister->getUpdates());
        self::assertCount(0, $userPersister->getDeletes());

        // should have an id
        self::assertInternalType('numeric', $user->id);
}

    /**
     * Tests a scenario where a save() operation is cascaded from a ForumUser
     * to its associated ForumAvatar, both entities using IDENTITY id generation.
     */
    public function testCascadedIdentityColumnInsert()
    {
        // Setup fake persister and id generator for identity generation
        //ForumUser
        $userPersister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(ForumUser::class));
        $this->unitOfWork->setEntityPersister(ForumUser::class, $userPersister);
        $userPersister->setMockIdGeneratorType(GeneratorType::IDENTITY);
        // ForumAvatar
        $avatarPersister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(ForumAvatar::class));
        $this->unitOfWork->setEntityPersister(ForumAvatar::class, $avatarPersister);
        $avatarPersister->setMockIdGeneratorType(GeneratorType::IDENTITY);

        // Test
        $user = new ForumUser();
        $user->username = 'romanb';
        $avatar = new ForumAvatar();
        $user->avatar = $avatar;
        $this->unitOfWork->persist($user); // save cascaded to avatar

        $this->unitOfWork->commit();

        self::assertInternalType('numeric', $user->id);
        self::assertInternalType('numeric', $avatar->id);

        self::assertCount(1, $userPersister->getInserts());
        self::assertCount(0, $userPersister->getUpdates());
        self::assertCount(0, $userPersister->getDeletes());

        self::assertCount(1, $avatarPersister->getInserts());
        self::assertCount(0, $avatarPersister->getUpdates());
        self::assertCount(0, $avatarPersister->getDeletes());
    }

    public function testChangeTrackingNotify()
    {
        $persister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(NotifyChangedEntity::class));
        $this->unitOfWork->setEntityPersister(NotifyChangedEntity::class, $persister);
        $itemPersister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(NotifyChangedRelatedItem::class));
        $this->unitOfWork->setEntityPersister(NotifyChangedRelatedItem::class, $itemPersister);

        $entity = new NotifyChangedEntity;
        $entity->setData('thedata');
        $this->unitOfWork->persist($entity);

        $this->unitOfWork->commit();
        self::assertCount(1, $persister->getInserts());
        $persister->reset();

        self::assertTrue($this->unitOfWork->isInIdentityMap($entity));

        $entity->setData('newdata');
        $entity->setTransient('newtransientvalue');

        self::assertTrue($this->unitOfWork->isScheduledForDirtyCheck($entity));

        self::assertEquals(
            [
                'data' => ['thedata', 'newdata'],
                'transient' => [null, 'newtransientvalue'],
            ],
            $this->unitOfWork->getEntityChangeSet($entity)
        );

        $item = new NotifyChangedRelatedItem();
        $entity->getItems()->add($item);
        $item->setOwner($entity);
        $this->unitOfWork->persist($item);

        $this->unitOfWork->commit();
        self::assertCount(1, $itemPersister->getInserts());
        $persister->reset();
        $itemPersister->reset();


        $entity->getItems()->removeElement($item);
        $item->setOwner(null);
        self::assertTrue($entity->getItems()->isDirty());
        $this->unitOfWork->commit();
        $updates = $itemPersister->getUpdates();
        self::assertCount(1, $updates);
        self::assertSame($updates[0], $item);
    }

    public function testGetEntityStateOnVersionedEntityWithAssignedIdentifier()
    {
        $persister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(VersionedAssignedIdentifierEntity::class));
        $this->unitOfWork->setEntityPersister(VersionedAssignedIdentifierEntity::class, $persister);

        $e = new VersionedAssignedIdentifierEntity();
        $e->id = 42;
        self::assertEquals(UnitOfWork::STATE_NEW, $this->unitOfWork->getEntityState($e));
        self::assertFalse($persister->isExistsCalled());
    }

    public function testGetEntityStateWithAssignedIdentity()
    {
        $persister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(CmsPhonenumber::class));
        $this->unitOfWork->setEntityPersister(CmsPhonenumber::class, $persister);

        $ph = new CmsPhonenumber();
        $ph->phonenumber = '12345';

        self::assertEquals(UnitOfWork::STATE_NEW, $this->unitOfWork->getEntityState($ph));
        self::assertTrue($persister->isExistsCalled());

        $persister->reset();

        // if the entity is already managed the exists() check should be skipped
        $this->unitOfWork->registerManaged($ph, ['phonenumber' => '12345'], []);
        self::assertEquals(UnitOfWork::STATE_MANAGED, $this->unitOfWork->getEntityState($ph));
        self::assertFalse($persister->isExistsCalled());
        $ph2 = new CmsPhonenumber();
        $ph2->phonenumber = '12345';
        self::assertEquals(UnitOfWork::STATE_DETACHED, $this->unitOfWork->getEntityState($ph2));
        self::assertFalse($persister->isExistsCalled());
    }

    /**
     * DDC-2086 [GH-484] Prevented 'Undefined index' notice when updating.
     */
    public function testNoUndefinedIndexNoticeOnScheduleForUpdateWithoutChanges()
    {
        // Setup fake persister and id generator
        $userPersister = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(ForumUser::class));
        $userPersister->setMockIdGeneratorType(GeneratorType::IDENTITY);
        $this->unitOfWork->setEntityPersister(ForumUser::class, $userPersister);

        // Create a test user
        $user = new ForumUser();
        $user->name = 'Jasper';
        $this->unitOfWork->persist($user);
        $this->unitOfWork->commit();

        // Schedule user for update without changes
        $this->unitOfWork->scheduleForUpdate($user);

        self::assertNotEmpty($this->unitOfWork->getScheduledEntityUpdates());

        // This commit should not raise an E_NOTICE
        $this->unitOfWork->commit();

        self::assertEmpty($this->unitOfWork->getScheduledEntityUpdates());
    }

    /**
     * @group DDC-1984
     */
    public function testLockWithoutEntityThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->unitOfWork->lock(null, null, null);
    }

    /**
     * @group DDC-3490
     *
     * @dataProvider invalidAssociationValuesDataProvider
     *
     * @param mixed $invalidValue
     */
    public function testRejectsPersistenceOfObjectsWithInvalidAssociationValue($invalidValue)
    {
        $this->unitOfWork->setEntityPersister(
            ForumUser::class,
            new EntityPersisterMock(
                $this->emMock,
                $this->emMock->getClassMetadata(ForumUser::class)
            )
        );

        $user           = new ForumUser();
        $user->username = 'John';
        $user->avatar   = $invalidValue;

        $this->expectException(\Doctrine\ORM\ORMInvalidArgumentException::class);

        $this->unitOfWork->persist($user);
    }

    /**
     * @group DDC-3490
     *
     * @dataProvider invalidAssociationValuesDataProvider
     *
     * @param mixed $invalidValue
     */
    public function testRejectsChangeSetComputationForObjectsWithInvalidAssociationValue($invalidValue)
    {
        $metadata = $this->emMock->getClassMetadata(ForumUser::class);

        $this->unitOfWork->setEntityPersister(
            ForumUser::class,
            new EntityPersisterMock($this->emMock, $metadata)
        );

        $user = new ForumUser();

        $this->unitOfWork->persist($user);

        $user->username = 'John';
        $user->avatar   = $invalidValue;

        $this->expectException(\Doctrine\ORM\ORMInvalidArgumentException::class);

        $this->unitOfWork->computeChangeSet($metadata, $user);
    }

    /**
     * @group DDC-3619
     * @group 1338
     */
    public function testRemovedAndRePersistedEntitiesAreInTheIdentityMapAndAreNotGarbageCollected()
    {
        $entity     = new ForumUser();
        $entity->id = 123;

        $this->unitOfWork->registerManaged($entity, ['id' => 123], []);
        self::assertTrue($this->unitOfWork->isInIdentityMap($entity));

        $this->unitOfWork->remove($entity);
        self::assertFalse($this->unitOfWork->isInIdentityMap($entity));

        $this->unitOfWork->persist($entity);
        self::assertTrue($this->unitOfWork->isInIdentityMap($entity));
    }

    /**
     * @group 5849
     * @group 5850
     */
    public function testPersistedEntityAndClearManager()
    {
        $entity1 = new City(123, 'London');
        $entity2 = new Country(456, 'United Kingdom');

        $this->unitOfWork->persist($entity1);
        self::assertTrue($this->unitOfWork->isInIdentityMap($entity1));

        $this->unitOfWork->persist($entity2);
        self::assertTrue($this->unitOfWork->isInIdentityMap($entity2));

        $this->unitOfWork->clear();

        self::assertFalse($this->unitOfWork->isInIdentityMap($entity1));
        self::assertFalse($this->unitOfWork->isInIdentityMap($entity2));

        self::assertFalse($this->unitOfWork->isScheduledForInsert($entity1));
        self::assertFalse($this->unitOfWork->isScheduledForInsert($entity2));
    }

    /**
     * @group #5579
     */
    public function testEntityChangeSetIsClearedAfterFlush() : void
    {
        $entity1 = new NotifyChangedEntity;
        $entity2 = new NotifyChangedEntity;

        $entity1->setData('thedata');
        $entity2->setData('thedata');

        $this->unitOfWork->persist($entity1);
        $this->unitOfWork->persist($entity2);
        $this->unitOfWork->commit();

        self::assertEmpty($this->unitOfWork->getEntityChangeSet($entity1));
        self::assertEmpty($this->unitOfWork->getEntityChangeSet($entity2));
    }

    /**
     * Data Provider
     *
     * @return mixed[][]
     */
    public function invalidAssociationValuesDataProvider()
    {
        return [
            ['foo'],
            [['foo']],
            [''],
            [[]],
            [new stdClass()],
            [new ArrayCollection()],
        ];
    }

    /**
     * @dataProvider entitiesWithValidIdentifiersProvider
     *
     * @param object $entity
     * @param string $idHash
     *
     * @return void
     */
    public function testAddToIdentityMapValidIdentifiers($entity, $idHash)
    {
        $this->unitOfWork->persist($entity);
        $this->unitOfWork->addToIdentityMap($entity);

        self::assertSame($entity, $this->unitOfWork->getByIdHash($idHash, get_class($entity)));
    }

    public function entitiesWithValidIdentifiersProvider()
    {
        $emptyString = new EntityWithStringIdentifier();

        $emptyString->id = '';

        $nonEmptyString = new EntityWithStringIdentifier();

        $nonEmptyString->id = uniqid('id', true);

        $emptyStrings = new EntityWithCompositeStringIdentifier();

        $emptyStrings->id1 = '';
        $emptyStrings->id2 = '';

        $nonEmptyStrings = new EntityWithCompositeStringIdentifier();

        $nonEmptyStrings->id1 = uniqid('id1', true);
        $nonEmptyStrings->id2 = uniqid('id2', true);

        $booleanTrue = new EntityWithBooleanIdentifier();

        $booleanTrue->id = true;

        $booleanFalse = new EntityWithBooleanIdentifier();

        $booleanFalse->id = false;

        return [
            'empty string, single field'     => [$emptyString, ''],
            'non-empty string, single field' => [$nonEmptyString, $nonEmptyString->id],
            'empty strings, two fields'      => [$emptyStrings, ' '],
            'non-empty strings, two fields'  => [$nonEmptyStrings, $nonEmptyStrings->id1 . ' ' . $nonEmptyStrings->id2],
            'boolean true'                   => [$booleanTrue, '1'],
            'boolean false'                  => [$booleanFalse, ''],
        ];
    }

    public function testRegisteringAManagedInstanceRequiresANonEmptyIdentifier()
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->unitOfWork->registerManaged(new EntityWithBooleanIdentifier(), [], []);
    }

    /**
     * @dataProvider entitiesWithInvalidIdentifiersProvider
     *
     * @param object $entity
     * @param array  $identifier
     *
     * @return void
     */
    public function testAddToIdentityMapInvalidIdentifiers($entity, array $identifier)
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->unitOfWork->registerManaged($entity, $identifier, []);
    }


    public function entitiesWithInvalidIdentifiersProvider()
    {
        $firstNullString  = new EntityWithCompositeStringIdentifier();

        $firstNullString->id2 = uniqid('id2', true);

        $secondNullString = new EntityWithCompositeStringIdentifier();

        $secondNullString->id1 = uniqid('id1', true);

        return [
            'null string, single field'      => [new EntityWithStringIdentifier(), ['id' => null]],
            'null strings, two fields'       => [new EntityWithCompositeStringIdentifier(), ['id1' => null, 'id2' => null]],
            'first null string, two fields'  => [$firstNullString, ['id1' => null, 'id2' => $firstNullString->id2]],
            'second null string, two fields' => [$secondNullString, ['id1' => $secondNullString->id1, 'id2' => null]],
        ];
    }

    /**
     * Unlike next test, this one demonstrates that the problem does
     * not necessarily reproduce if all the pieces are being flushed together.
     *
     * @group DDC-2922
     * @group #1521
     */
    public function testNewAssociatedEntityPersistenceOfNewEntitiesThroughCascadedAssociationsFirst()
    {
        $persister1 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(CascadePersistedEntity::class));
        $persister2 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(EntityWithCascadingAssociation::class));
        $persister3 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(EntityWithNonCascadingAssociation::class));

        $this->unitOfWork->setEntityPersister(CascadePersistedEntity::class, $persister1);
        $this->unitOfWork->setEntityPersister(EntityWithCascadingAssociation::class, $persister2);
        $this->unitOfWork->setEntityPersister(EntityWithNonCascadingAssociation::class, $persister3);

        $cascadePersisted = new CascadePersistedEntity();
        $cascading        = new EntityWithCascadingAssociation();
        $nonCascading     = new EntityWithNonCascadingAssociation();

        // First we persist and flush a EntityWithCascadingAssociation with
        // the cascading association not set. Having the "cascading path" involve
        // a non-new object is important to show that the ORM should be considering
        // cascades across entity changesets in subsequent flushes.
        $cascading->cascaded = $cascadePersisted;
        $nonCascading->cascaded = $cascadePersisted;

        $this->unitOfWork->persist($cascading);
        $this->unitOfWork->persist($nonCascading);
        $this->unitOfWork->commit();

        self::assertCount(1, $persister1->getInserts());
        self::assertCount(1, $persister2->getInserts());
        self::assertCount(1, $persister3->getInserts());
    }

    /**
     * This test exhibits the bug describe in the ticket, where an object that
     * ought to be reachable causes errors.
     *
     * @group DDC-2922
     * @group #1521
     */
    public function testNewAssociatedEntityPersistenceOfNewEntitiesThroughNonCascadedAssociationsFirst()
    {
        $persister1 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(CascadePersistedEntity::class));
        $persister2 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(EntityWithCascadingAssociation::class));
        $persister3 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(EntityWithNonCascadingAssociation::class));

        $this->unitOfWork->setEntityPersister(CascadePersistedEntity::class, $persister1);
        $this->unitOfWork->setEntityPersister(EntityWithCascadingAssociation::class, $persister2);
        $this->unitOfWork->setEntityPersister(EntityWithNonCascadingAssociation::class, $persister3);

        $cascadePersisted = new CascadePersistedEntity();
        $cascading        = new EntityWithCascadingAssociation();
        $nonCascading     = new EntityWithNonCascadingAssociation();

        // First we persist and flush a EntityWithCascadingAssociation with
        // the cascading association not set. Having the "cascading path" involve
        // a non-new object is important to show that the ORM should be considering
        // cascades across entity changesets in subsequent flushes.
        $cascading->cascaded = null;

        $this->unitOfWork->persist($cascading);
        $this->unitOfWork->commit();

        self::assertCount(0, $persister1->getInserts());
        self::assertCount(1, $persister2->getInserts());
        self::assertCount(0, $persister3->getInserts());

        // Note that we have NOT directly persisted the CascadePersistedEntity,
        // and EntityWithNonCascadingAssociation does NOT have a configured
        // cascade-persist.
        $nonCascading->nonCascaded = $cascadePersisted;

        // However, EntityWithCascadingAssociation *does* have a cascade-persist
        // association, which ought to allow us to save the CascadePersistedEntity
        // anyway through that connection.
        $cascading->cascaded = $cascadePersisted;

        $this->unitOfWork->persist($nonCascading);
        $this->unitOfWork->commit();

        self::assertCount(1, $persister1->getInserts());
        self::assertCount(1, $persister2->getInserts());
        self::assertCount(1, $persister3->getInserts());
    }

    /**
     * This test exhibits the bug describe in the ticket, where an object that
     * ought to be reachable causes errors.
     *
     * @group DDC-2922
     * @group #1521
     */
    public function testPreviousDetectedIllegalNewNonCascadedEntitiesAreCleanedUpOnSubsequentCommits()
    {
        $persister1 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(CascadePersistedEntity::class));
        $persister2 = new EntityPersisterMock($this->emMock, $this->emMock->getClassMetadata(EntityWithNonCascadingAssociation::class));

        $this->unitOfWork->setEntityPersister(CascadePersistedEntity::class, $persister1);
        $this->unitOfWork->setEntityPersister(EntityWithNonCascadingAssociation::class, $persister2);

        $cascadePersisted = new CascadePersistedEntity();
        $nonCascading = new EntityWithNonCascadingAssociation();

        // We explicitly cause the ORM to detect a non-persisted new entity in the association graph:
        $nonCascading->nonCascaded = $cascadePersisted;

        $this->unitOfWork->persist($nonCascading);

        try {
            $this->unitOfWork->commit();

            self::fail('An exception was supposed to be raised');
        } catch (ORMInvalidArgumentException $ignored) {
            self::assertEmpty($persister1->getInserts());
            self::assertEmpty($persister2->getInserts());
        }

        $this->unitOfWork->clear();
        $this->unitOfWork->persist(new CascadePersistedEntity());
        $this->unitOfWork->commit();

        // Persistence operations should just recover normally:
        self::assertCount(1, $persister1->getInserts());
        self::assertCount(0, $persister2->getInserts());
    }

    /**
     * @group DDC-3120
     */
    public function testCanInstantiateInternalPhpClassSubclass()
    {
        $classMetadata = new ClassMetadata(MyArrayObjectEntity::class, $this->metadataBuildingContext);

        self::assertInstanceOf(MyArrayObjectEntity::class, $this->unitOfWork->newInstance($classMetadata));
    }

    /**
     * @group DDC-3120
     */
    public function testCanInstantiateInternalPhpClassSubclassFromUnserializedMetadata()
    {
        /* @var $classMetadata ClassMetadata */
        $classMetadata = unserialize(
            serialize(
                new ClassMetadata(MyArrayObjectEntity::class, $this->metadataBuildingContext)
            )
        );

        $classMetadata->wakeupReflection(new RuntimeReflectionService());

        self::assertInstanceOf(MyArrayObjectEntity::class, $this->unitOfWork->newInstance($classMetadata));
    }

    /**
     * @group #6722
     * @group #6724
     */
    public function testCollectionChangesOnNonInitializedProxiesAreDetected()
    {
        $proxyFactory    = $this->emMock->getProxyFactory();
        $owningMetadata  = $this->emMock->getClassMetadata(Owning::class);
        $inverseMetadata = $this->emMock->getClassMetadata(Inverse::class);
        $owning          = $this->emMock->getReference(Owning::class, ['id' => 'abc']);
        $inverse         = $this->emMock->getReference(Inverse::class, ['id' => 'def']);

        /** @var $owning Owning|GhostObjectInterface */
        self::assertInstanceOf(GhostObjectInterface::class, $owning);
        self::assertInstanceOf(GhostObjectInterface::class, $inverse);
        self::assertFalse($owning->isProxyInitialized());
        self::assertFalse($inverse->isProxyInitialized());

        /** @var $collection PersistentCollection */
        $collection = $owning->inverse;

        self::assertInstanceOf(PersistentCollection::class, $collection);
        self::assertFalse($collection->isInitialized());

        $collection->add($inverse);

        $this->unitOfWork->computeChangeSets();

        $collectionInserts = $this->unitOfWork->getScheduledCollectionUpdates();

        self::assertFalse($owning->isProxyInitialized());
        self::assertFalse($inverse->isProxyInitialized());

        self::assertCount(1, $collectionInserts);
        self::assertSame($owning->inverse, \reset($collectionInserts));
    }
}

/**
 * @ORM\Entity
 */
class NotifyChangedEntity implements NotifyPropertyChanged
{
    private $listeners = [];
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    private $id;
    /**
     * @ORM\Column(type="string")
     */
    private $data;

    private $transient; // not persisted

    /** @ORM\OneToMany(targetEntity="NotifyChangedRelatedItem", mappedBy="owner") */
    private $items;

    public function  __construct() {
        $this->items = new ArrayCollection;
    }

    public function getId() {
        return $this->id;
    }

    public function getItems() {
        return $this->items;
    }

    public function setTransient($value) {
        if ($value != $this->transient) {
            $this->onPropertyChanged('transient', $this->transient, $value);
            $this->transient = $value;
        }
    }

    public function getData() {
        return $this->data;
    }

    public function setData($data) {
        if ($data != $this->data) {
            $this->onPropertyChanged('data', $this->data, $data);
            $this->data = $data;
        }
    }

    public function addPropertyChangedListener(PropertyChangedListener $listener)
    {
        $this->listeners[] = $listener;
    }

    protected function onPropertyChanged($propName, $oldValue, $newValue) {
        if ($this->listeners) {
            foreach ($this->listeners as $listener) {
                $listener->propertyChanged($this, $propName, $oldValue, $newValue);
            }
        }
    }
}

/** @ORM\Entity */
class NotifyChangedRelatedItem
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    private $id;

    /** @ORM\ManyToOne(targetEntity="NotifyChangedEntity", inversedBy="items") */
    private $owner;

    public function getId() {
        return $this->id;
    }

    public function getOwner() {
        return $this->owner;
    }

    public function setOwner($owner) {
        $this->owner = $owner;
    }
}

/** @ORM\Entity */
class VersionedAssignedIdentifierEntity
{
    /**
     * @ORM\Id @ORM\Column(type="integer")
     */
    public $id;
    /**
     * @ORM\Version @ORM\Column(type="integer")
     */
    public $version;
}

/** @ORM\Entity */
class EntityWithStringIdentifier
{
    /**
     * @ORM\Id @ORM\Column(type="string")
     *
     * @var string|null
     */
    public $id;
}

/** @ORM\Entity */
class EntityWithBooleanIdentifier
{
    /**
     * @ORM\Id @ORM\Column(type="boolean")
     *
     * @var bool|null
     */
    public $id;
}

/** @ORM\Entity */
class EntityWithCompositeStringIdentifier
{
    /**
     * @ORM\Id @ORM\Column(type="string")
     *
     * @var string|null
     */
    public $id1;

    /**
     * @ORM\Id @ORM\Column(type="string")
     *
     * @var string|null
     */
    public $id2;
}

/** @ORM\Entity */
class EntityWithRandomlyGeneratedField
{
    /** @ORM\Id @ORM\Column(type="string") */
    public $id;

    /**
     * @ORM\Column(type="integer")
     */
    public $generatedField;

    public function __construct()
    {
        $this->id             = uniqid('id', true);
        $this->generatedField = random_int(0, 100000);
    }
}

/** @ORM\Entity */
class CascadePersistedEntity
{
    /** @ORM\Id @ORM\Column(type="string") @ORM\GeneratedValue(strategy="NONE") */
    private $id;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

/** @ORM\Entity */
class EntityWithCascadingAssociation
{
    /** @ORM\Id @ORM\Column(type="string") @ORM\GeneratedValue(strategy="NONE") */
    private $id;

    /** @ORM\ManyToOne(targetEntity=CascadePersistedEntity::class, cascade={"persist"}) */
    public $cascaded;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

/** @ORM\Entity */
class EntityWithNonCascadingAssociation
{
    /** @ORM\Id @ORM\Column(type="string") @ORM\GeneratedValue(strategy="NONE") */
    private $id;

    /** @ORM\ManyToOne(targetEntity=CascadePersistedEntity::class) */
    public $nonCascaded;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

class MyArrayObjectEntity extends \ArrayObject
{
}
