<?php

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\EventManager;
use Doctrine\Common\NotifyPropertyChanged;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Doctrine\Common\PropertyChangedListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\EntityPersisterMock;
use Doctrine\Tests\Mocks\UnitOfWorkMock;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\GeoNames\City;
use Doctrine\Tests\Models\GeoNames\Country;
use Doctrine\Tests\OrmTestCase;
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
    private $_unitOfWork;

    /**
     * Provides a sequence mock to the UnitOfWork
     *
     * @var ConnectionMock
     */
    private $_connectionMock;

    /**
     * The EntityManager mock that provides the mock persisters
     *
     * @var EntityManagerMock
     */
    private $_emMock;

    /**
     * @var EventManager|\PHPUnit_Framework_MockObject_MockObject
     */
    private $eventManager;

    protected function setUp()
    {
        parent::setUp();
        $this->_connectionMock = new ConnectionMock([], new DriverMock());
        $this->eventManager = $this->getMockBuilder(EventManager::class)->getMock();
        $this->_emMock = EntityManagerMock::create($this->_connectionMock, null, $this->eventManager);
        // SUT
        $this->_unitOfWork = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_unitOfWork);
    }

    public function testRegisterRemovedOnNewEntityIsIgnored()
    {
        $user = new ForumUser();
        $user->username = 'romanb';
        $this->assertFalse($this->_unitOfWork->isScheduledForDelete($user));
        $this->_unitOfWork->scheduleForDelete($user);
        $this->assertFalse($this->_unitOfWork->isScheduledForDelete($user));
    }


    /* Operational tests */

    public function testSavingSingleEntityWithIdentityColumnForcesInsert()
    {
        // Setup fake persister and id generator for identity generation
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);

        // Test
        $user = new ForumUser();
        $user->username = 'romanb';
        $this->_unitOfWork->persist($user);

        // Check
        $this->assertEquals(0, count($userPersister->getInserts()));
        $this->assertEquals(0, count($userPersister->getUpdates()));
        $this->assertEquals(0, count($userPersister->getDeletes()));
        $this->assertFalse($this->_unitOfWork->isInIdentityMap($user));
        // should no longer be scheduled for insert
        $this->assertTrue($this->_unitOfWork->isScheduledForInsert($user));

        // Now lets check whether a subsequent commit() does anything
        $userPersister->reset();

        // Test
        $this->_unitOfWork->commit();

        // Check.
        $this->assertEquals(1, count($userPersister->getInserts()));
        $this->assertEquals(0, count($userPersister->getUpdates()));
        $this->assertEquals(0, count($userPersister->getDeletes()));

        // should have an id
        $this->assertTrue(is_numeric($user->id));
    }

    /**
     * Tests a scenario where a save() operation is cascaded from a ForumUser
     * to its associated ForumAvatar, both entities using IDENTITY id generation.
     */
    public function testCascadedIdentityColumnInsert()
    {
        // Setup fake persister and id generator for identity generation
        //ForumUser
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        // ForumAvatar
        $avatarPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumAvatar::class));
        $this->_unitOfWork->setEntityPersister(ForumAvatar::class, $avatarPersister);
        $avatarPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);

        // Test
        $user = new ForumUser();
        $user->username = 'romanb';
        $avatar = new ForumAvatar();
        $user->avatar = $avatar;
        $this->_unitOfWork->persist($user); // save cascaded to avatar

        $this->_unitOfWork->commit();

        $this->assertTrue(is_numeric($user->id));
        $this->assertTrue(is_numeric($avatar->id));

        $this->assertEquals(1, count($userPersister->getInserts()));
        $this->assertEquals(0, count($userPersister->getUpdates()));
        $this->assertEquals(0, count($userPersister->getDeletes()));

        $this->assertEquals(1, count($avatarPersister->getInserts()));
        $this->assertEquals(0, count($avatarPersister->getUpdates()));
        $this->assertEquals(0, count($avatarPersister->getDeletes()));
    }

    public function testChangeTrackingNotify()
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(NotifyChangedEntity::class));
        $this->_unitOfWork->setEntityPersister(NotifyChangedEntity::class, $persister);
        $itemPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(NotifyChangedRelatedItem::class));
        $this->_unitOfWork->setEntityPersister(NotifyChangedRelatedItem::class, $itemPersister);

        $entity = new NotifyChangedEntity;
        $entity->setData('thedata');
        $this->_unitOfWork->persist($entity);

        $this->_unitOfWork->commit();
        $this->assertCount(1, $persister->getInserts());

        $persister->reset();

        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity));

        $entity->setData('newdata');
        $entity->setTransient('newtransientvalue');

        $this->assertTrue($this->_unitOfWork->isScheduledForDirtyCheck($entity));

        $this->assertEquals(['data' => ['thedata', 'newdata']], $this->_unitOfWork->getEntityChangeSet($entity));

        $item = new NotifyChangedRelatedItem();
        $entity->getItems()->add($item);
        $item->setOwner($entity);
        $this->_unitOfWork->persist($item);

        $this->_unitOfWork->commit();
        $this->assertEquals(1, count($itemPersister->getInserts()));
        $persister->reset();
        $itemPersister->reset();


        $entity->getItems()->removeElement($item);
        $item->setOwner(null);
        $this->assertTrue($entity->getItems()->isDirty());
        $this->_unitOfWork->commit();
        $updates = $itemPersister->getUpdates();
        $this->assertEquals(1, count($updates));
        $this->assertTrue($updates[0] === $item);
    }

    public function testGetEntityStateOnVersionedEntityWithAssignedIdentifier()
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(VersionedAssignedIdentifierEntity::class));
        $this->_unitOfWork->setEntityPersister(VersionedAssignedIdentifierEntity::class, $persister);

        $e = new VersionedAssignedIdentifierEntity();
        $e->id = 42;
        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_unitOfWork->getEntityState($e));
        $this->assertFalse($persister->isExistsCalled());
    }

    public function testGetEntityStateWithAssignedIdentity()
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(CmsPhonenumber::class));
        $this->_unitOfWork->setEntityPersister(CmsPhonenumber::class, $persister);

        $ph = new CmsPhonenumber();
        $ph->phonenumber = '12345';

        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_unitOfWork->getEntityState($ph));
        $this->assertTrue($persister->isExistsCalled());

        $persister->reset();

        // if the entity is already managed the exists() check should be skipped
        $this->_unitOfWork->registerManaged($ph, ['phonenumber' => '12345'], []);
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $this->_unitOfWork->getEntityState($ph));
        $this->assertFalse($persister->isExistsCalled());
        $ph2 = new CmsPhonenumber();
        $ph2->phonenumber = '12345';
        $this->assertEquals(UnitOfWork::STATE_DETACHED, $this->_unitOfWork->getEntityState($ph2));
        $this->assertFalse($persister->isExistsCalled());
    }

    /**
     * DDC-2086 [GH-484] Prevented 'Undefined index' notice when updating.
     */
    public function testNoUndefinedIndexNoticeOnScheduleForUpdateWithoutChanges()
    {
        // Setup fake persister and id generator
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);

        // Create a test user
        $user = new ForumUser();
        $user->name = 'Jasper';
        $this->_unitOfWork->persist($user);
        $this->_unitOfWork->commit();

        // Schedule user for update without changes
        $this->_unitOfWork->scheduleForUpdate($user);

        self::assertNotEmpty($this->_unitOfWork->getScheduledEntityUpdates());

        // This commit should not raise an E_NOTICE
        $this->_unitOfWork->commit();

        self::assertEmpty($this->_unitOfWork->getScheduledEntityUpdates());
    }

    /**
     * @group DDC-1984
     */
    public function testLockWithoutEntityThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->_unitOfWork->lock(null, null, null);
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
        $this->_unitOfWork->setEntityPersister(
            ForumUser::class,
            new EntityPersisterMock(
                $this->_emMock,
                $this->_emMock->getClassMetadata(ForumUser::class)
            )
        );

        $user           = new ForumUser();
        $user->username = 'John';
        $user->avatar   = $invalidValue;

        $this->expectException(\Doctrine\ORM\ORMInvalidArgumentException::class);

        $this->_unitOfWork->persist($user);
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
        $metadata = $this->_emMock->getClassMetadata(ForumUser::class);

        $this->_unitOfWork->setEntityPersister(
            ForumUser::class,
            new EntityPersisterMock($this->_emMock, $metadata)
        );

        $user = new ForumUser();

        $this->_unitOfWork->persist($user);

        $user->username = 'John';
        $user->avatar   = $invalidValue;

        $this->expectException(\Doctrine\ORM\ORMInvalidArgumentException::class);

        $this->_unitOfWork->computeChangeSet($metadata, $user);
    }

    /**
     * @group DDC-3619
     * @group 1338
     */
    public function testRemovedAndRePersistedEntitiesAreInTheIdentityMapAndAreNotGarbageCollected()
    {
        $entity     = new ForumUser();
        $entity->id = 123;

        $this->_unitOfWork->registerManaged($entity, ['id' => 123], []);
        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity));

        $this->_unitOfWork->remove($entity);
        $this->assertFalse($this->_unitOfWork->isInIdentityMap($entity));

        $this->_unitOfWork->persist($entity);
        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity));
    }

    /**
     * @group 5849
     * @group 5850
     */
    public function testPersistedEntityAndClearManager()
    {
        $entity1 = new City(123, 'London');
        $entity2 = new Country(456, 'United Kingdom');

        $this->_unitOfWork->persist($entity1);
        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity1));

        $this->_unitOfWork->persist($entity2);
        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity2));

        $this->_unitOfWork->clear(Country::class);
        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity1));
        $this->assertFalse($this->_unitOfWork->isInIdentityMap($entity2));
        $this->assertTrue($this->_unitOfWork->isScheduledForInsert($entity1));
        $this->assertFalse($this->_unitOfWork->isScheduledForInsert($entity2));
    }

    /**
     * @group #5579
     */
    public function testEntityChangeSetIsNotClearedAfterFlushOnSingleEntity() : void
    {
        $entity1 = new NotifyChangedEntity;
        $entity2 = new NotifyChangedEntity;

        $entity1->setData('thedata');
        $entity2->setData('thedata');

        $this->_unitOfWork->persist($entity1);
        $this->_unitOfWork->persist($entity2);

        $this->_unitOfWork->commit($entity1);
        self::assertEmpty($this->_unitOfWork->getEntityChangeSet($entity1));
        self::assertCount(1, $this->_unitOfWork->getEntityChangeSet($entity2));
    }

    /**
     * @group #5579
     */
    public function testEntityChangeSetIsNotClearedAfterFlushOnArrayOfEntities() : void
    {
        $entity1 = new NotifyChangedEntity;
        $entity2 = new NotifyChangedEntity;
        $entity3 = new NotifyChangedEntity;

        $entity1->setData('thedata');
        $entity2->setData('thedata');
        $entity3->setData('thedata');

        $this->_unitOfWork->persist($entity1);
        $this->_unitOfWork->persist($entity2);
        $this->_unitOfWork->persist($entity3);

        $this->_unitOfWork->commit([$entity1, $entity3]);

        self::assertEmpty($this->_unitOfWork->getEntityChangeSet($entity1));
        self::assertEmpty($this->_unitOfWork->getEntityChangeSet($entity3));
        self::assertCount(1, $this->_unitOfWork->getEntityChangeSet($entity2));
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
        $this->_unitOfWork->persist($entity);
        $this->_unitOfWork->addToIdentityMap($entity);

        self::assertSame($entity, $this->_unitOfWork->getByIdHash($idHash, get_class($entity)));
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
            'empty string, single field'     => [$emptyString, serialize([''])],
            'non-empty string, single field' => [$nonEmptyString, serialize([$nonEmptyString->id])],
            'empty strings, two fields'      => [$emptyStrings, serialize(['', ''])],
            'non-empty strings, two fields'  => [
                $nonEmptyStrings,
                serialize([$nonEmptyStrings->id1, $nonEmptyStrings->id2])
            ],
            'boolean true'                   => [$booleanTrue, serialize(['1'])],
            'boolean false'                  => [$booleanFalse, serialize([''])],
        ];
    }

    public function testRegisteringAManagedInstanceRequiresANonEmptyIdentifier()
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->registerManaged(new EntityWithBooleanIdentifier(), [], []);
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

        $this->_unitOfWork->registerManaged($entity, $identifier, []);
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
     * @group 5689
     * @group 1465
     */
    public function testObjectHashesOfMergedEntitiesAreNotUsedInOriginalEntityDataMap()
    {
        $user       = new CmsUser();
        $user->name = 'ocramius';
        $mergedUser = $this->_unitOfWork->merge($user);

        self::assertSame([], $this->_unitOfWork->getOriginalEntityData($user), 'No original data was stored');
        self::assertSame([], $this->_unitOfWork->getOriginalEntityData($mergedUser), 'No original data was stored');


        $user       = null;
        $mergedUser = null;

        // force garbage collection of $user (frees the used object hashes, which may be recycled)
        gc_collect_cycles();

        $newUser       = new CmsUser();
        $newUser->name = 'ocramius';

        $this->_unitOfWork->persist($newUser);

        self::assertSame([], $this->_unitOfWork->getOriginalEntityData($newUser), 'No original data was stored');
    }

    /**
     * @group DDC-1955
     * @group 5570
     * @group 6174
     */
    public function testMergeWithNewEntityWillPersistItAndTriggerPrePersistListenersWithMergedEntityData()
    {
        $entity = new EntityWithRandomlyGeneratedField();

        $generatedFieldValue = $entity->generatedField;

        $this
            ->eventManager
            ->expects(self::any())
            ->method('hasListeners')
            ->willReturnCallback(function ($eventName) {
                return $eventName === Events::prePersist;
            });
        $this
            ->eventManager
            ->expects(self::once())
            ->method('dispatchEvent')
            ->with(
                self::anything(),
                self::callback(function (LifecycleEventArgs $args) use ($entity, $generatedFieldValue) {
                    /* @var $object EntityWithRandomlyGeneratedField */
                    $object = $args->getObject();

                    self::assertInstanceOf(EntityWithRandomlyGeneratedField::class, $object);
                    self::assertNotSame($entity, $object);
                    self::assertSame($generatedFieldValue, $object->generatedField);

                    return true;
                })
            );

        /* @var $object EntityWithRandomlyGeneratedField */
        $object = $this->_unitOfWork->merge($entity);

        self::assertNotSame($object, $entity);
        self::assertInstanceOf(EntityWithRandomlyGeneratedField::class, $object);
        self::assertSame($object->generatedField, $entity->generatedField);
    }

    /**
     * @group DDC-1955
     * @group 5570
     * @group 6174
     */
    public function testMergeWithExistingEntityWillNotPersistItNorTriggerPrePersistListeners()
    {
        $persistedEntity = new EntityWithRandomlyGeneratedField();
        $mergedEntity    = new EntityWithRandomlyGeneratedField();

        $mergedEntity->id = $persistedEntity->id;
        $mergedEntity->generatedField = mt_rand(
            $persistedEntity->generatedField + 1,
            $persistedEntity->generatedField + 1000
        );

        $this
            ->eventManager
            ->expects(self::any())
            ->method('hasListeners')
            ->willReturnCallback(function ($eventName) {
                return $eventName === Events::prePersist;
            });
        $this->eventManager->expects(self::never())->method('dispatchEvent');

        $this->_unitOfWork->registerManaged(
            $persistedEntity,
            ['id' => $persistedEntity->id],
            ['generatedField' => $persistedEntity->generatedField]
        );

        /* @var $merged EntityWithRandomlyGeneratedField */
        $merged = $this->_unitOfWork->merge($mergedEntity);

        self::assertSame($merged, $persistedEntity);
        self::assertSame($persistedEntity->generatedField, $mergedEntity->generatedField);
    }

    /**
     * @group 5923
     */
    public function testUnitOfWorkDoesNotConcatenateIdentifierWithAmbiguousPatterns()
    {
        $entity1 = new EntityWithCompositeStringIdentifier();
        $entity2 = new EntityWithCompositeStringIdentifier();

        $entity1->id1 = '';
        $entity1->id2 = ' ';

        $entity2->id1 = ' ';
        $entity2->id2 = '';

        $this->_unitOfWork->persist($entity1);
        $this->_unitOfWork->persist($entity2);
        $this->_unitOfWork->addToIdentityMap($entity1);
        $this->_unitOfWork->addToIdentityMap($entity2);

        self::assertSame(
            $entity1,
            $this->_unitOfWork->tryGetById(['id1' => '', 'id2' => ' '],  EntityWithCompositeStringIdentifier::class)
        );
        self::assertSame(
            $entity2,
            $this->_unitOfWork->tryGetById(['id1' => ' ', 'id2' => ''],  EntityWithCompositeStringIdentifier::class)
        );
    }

    /**
     * @group 5923
     */
    public function testUnitOfWorkStoresAlsoNonUtf8Identifiers()
    {
        $entity1 = new EntityWithStringIdentifier();
        $entity2 = new EntityWithStringIdentifier();

        $entity1->id = chr(65533);
        $entity2->id = chr(65534);

        $this->_unitOfWork->persist($entity1);
        $this->_unitOfWork->persist($entity2);

        self::assertSame(
            $entity1,
            $this->_unitOfWork->tryGetById(['id' => $entity1->id], EntityWithStringIdentifier::class)
        );
        self::assertSame(
            $entity2,
            $this->_unitOfWork->tryGetById(['id' => $entity2->id], EntityWithStringIdentifier::class)
        );
    }

    /**
     * @group 5923
     *
     * Note: this test is introduced for BC compliance only. If we expect consumers to always
     *       pass in the keys of an identifier, then we should change this test so it fails,
     *       but we'd have to document the BC break.
     */
    public function testUnitOfWorkCanFetchByIdentifierWithFlatListOfIdentifierValues()
    {
        $entity = new EntityWithStringIdentifier();

        $entity->id = uniqid('id', true);

        $this->_unitOfWork->persist($entity);

        self::assertSame($entity, $this->_unitOfWork->tryGetById([$entity->id], EntityWithStringIdentifier::class));
    }
}

/**
 * @Entity
 */
class NotifyChangedEntity implements NotifyPropertyChanged
{
    private $_listeners = [];
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;
    /**
     * @Column(type="string")
     */
    private $data;

    private $transient; // not persisted

    /** @OneToMany(targetEntity="NotifyChangedRelatedItem", mappedBy="owner") */
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
            $this->_onPropertyChanged('transient', $this->transient, $value);
            $this->transient = $value;
        }
    }

    public function getData() {
        return $this->data;
    }

    public function setData($data) {
        if ($data != $this->data) {
            $this->_onPropertyChanged('data', $this->data, $data);
            $this->data = $data;
        }
    }

    public function addPropertyChangedListener(PropertyChangedListener $listener)
    {
        $this->_listeners[] = $listener;
    }

    protected function _onPropertyChanged($propName, $oldValue, $newValue) {
        if ($this->_listeners) {
            foreach ($this->_listeners as $listener) {
                $listener->propertyChanged($this, $propName, $oldValue, $newValue);
            }
        }
    }
}

/** @Entity */
class NotifyChangedRelatedItem
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /** @ManyToOne(targetEntity="NotifyChangedEntity", inversedBy="items") */
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

/** @Entity */
class VersionedAssignedIdentifierEntity
{
    /**
     * @Id @Column(type="integer")
     */
    public $id;
    /**
     * @Version @Column(type="integer")
     */
    public $version;
}

/** @Entity */
class EntityWithStringIdentifier
{
    /**
     * @Id @Column(type="string")
     *
     * @var string|null
     */
    public $id;
}

/** @Entity */
class EntityWithBooleanIdentifier
{
    /**
     * @Id @Column(type="boolean")
     *
     * @var bool|null
     */
    public $id;
}

/** @Entity */
class EntityWithCompositeStringIdentifier
{
    /**
     * @Id @Column(type="string")
     *
     * @var string|null
     */
    public $id1;

    /**
     * @Id @Column(type="string")
     *
     * @var string|null
     */
    public $id2;
}

/** @Entity */
class EntityWithRandomlyGeneratedField
{
    /** @Id @Column(type="string") */
    public $id;

    /**
     * @Column(type="integer")
     */
    public $generatedField;

    public function __construct()
    {
        $this->id             = uniqid('id', true);
        $this->generatedField = mt_rand(0, 100000);
    }
}
