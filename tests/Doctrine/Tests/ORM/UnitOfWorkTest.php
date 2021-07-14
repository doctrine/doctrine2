<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\NotifyPropertyChanged;
use Doctrine\Persistence\PropertyChangedListener;
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
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;

use function assert;
use function count;
use function gc_collect_cycles;
use function get_class;
use function is_numeric;
use function random_int;
use function uniqid;

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

    /** @var EventManager|MockObject */
    private $eventManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->_connectionMock = new ConnectionMock([], new DriverMock());
        $this->eventManager    = $this->getMockBuilder(EventManager::class)->getMock();
        $this->_emMock         = EntityManagerMock::create($this->_connectionMock, null, $this->eventManager);
        // SUT
        $this->_unitOfWork = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_unitOfWork);
    }

    public function testRegisterRemovedOnNewEntityIsIgnored(): void
    {
        $user           = new ForumUser();
        $user->username = 'romanb';
        $this->assertFalse($this->_unitOfWork->isScheduledForDelete($user));
        $this->_unitOfWork->scheduleForDelete($user);
        $this->assertFalse($this->_unitOfWork->isScheduledForDelete($user));
    }

    /* Operational tests */

    public function testSavingSingleEntityWithIdentityColumnForcesInsert(): void
    {
        // Setup fake persister and id generator for identity generation
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);

        // Test
        $user           = new ForumUser();
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
    public function testCascadedIdentityColumnInsert(): void
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
        $user           = new ForumUser();
        $user->username = 'romanb';
        $avatar         = new ForumAvatar();
        $user->avatar   = $avatar;
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

    public function testChangeTrackingNotify(): void
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(NotifyChangedEntity::class));
        $this->_unitOfWork->setEntityPersister(NotifyChangedEntity::class, $persister);
        $itemPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(NotifyChangedRelatedItem::class));
        $this->_unitOfWork->setEntityPersister(NotifyChangedRelatedItem::class, $itemPersister);

        $entity = new NotifyChangedEntity();
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

    public function testChangeTrackingNotifyIndividualCommit(): void
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata('Doctrine\Tests\ORM\NotifyChangedEntity'));
        $this->_unitOfWork->setEntityPersister('Doctrine\Tests\ORM\NotifyChangedEntity', $persister);
        $itemPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata('Doctrine\Tests\ORM\NotifyChangedRelatedItem'));
        $this->_unitOfWork->setEntityPersister('Doctrine\Tests\ORM\NotifyChangedRelatedItem', $itemPersister);

        $entity = new NotifyChangedEntity();
        $entity->setData('thedata');

        $entity2 = new NotifyChangedEntity();
        $entity2->setData('thedata');

        $this->_unitOfWork->persist($entity);
        $this->_unitOfWork->persist($entity2);
        $this->_unitOfWork->commit($entity);
        $this->_unitOfWork->commit();

        $this->assertEquals(2, count($persister->getInserts()));

        $persister->reset();

        $this->assertTrue($this->_unitOfWork->isInIdentityMap($entity2));

        $entity->setData('newdata');
        $entity2->setData('newdata');

        $this->_unitOfWork->commit($entity);

        $this->assertTrue($this->_unitOfWork->isScheduledForDirtyCheck($entity2));
        $this->assertEquals(['data' => ['thedata', 'newdata']], $this->_unitOfWork->getEntityChangeSet($entity2));
        $this->assertFalse($this->_unitOfWork->isScheduledForDirtyCheck($entity));
        $this->assertEquals([], $this->_unitOfWork->getEntityChangeSet($entity));
    }

    public function testGetEntityStateOnVersionedEntityWithAssignedIdentifier(): void
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(VersionedAssignedIdentifierEntity::class));
        $this->_unitOfWork->setEntityPersister(VersionedAssignedIdentifierEntity::class, $persister);

        $e     = new VersionedAssignedIdentifierEntity();
        $e->id = 42;
        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_unitOfWork->getEntityState($e));
        $this->assertFalse($persister->isExistsCalled());
    }

    public function testGetEntityStateWithAssignedIdentity(): void
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(CmsPhonenumber::class));
        $this->_unitOfWork->setEntityPersister(CmsPhonenumber::class, $persister);

        $ph              = new CmsPhonenumber();
        $ph->phonenumber = '12345';

        $this->assertEquals(UnitOfWork::STATE_NEW, $this->_unitOfWork->getEntityState($ph));
        $this->assertTrue($persister->isExistsCalled());

        $persister->reset();

        // if the entity is already managed the exists() check should be skipped
        $this->_unitOfWork->registerManaged($ph, ['phonenumber' => '12345'], []);
        $this->assertEquals(UnitOfWork::STATE_MANAGED, $this->_unitOfWork->getEntityState($ph));
        $this->assertFalse($persister->isExistsCalled());
        $ph2              = new CmsPhonenumber();
        $ph2->phonenumber = '12345';
        $this->assertEquals(UnitOfWork::STATE_DETACHED, $this->_unitOfWork->getEntityState($ph2));
        $this->assertFalse($persister->isExistsCalled());
    }

    /**
     * DDC-2086 [GH-484] Prevented 'Undefined index' notice when updating.
     */
    public function testNoUndefinedIndexNoticeOnScheduleForUpdateWithoutChanges(): void
    {
        // Setup fake persister and id generator
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);

        // Create a test user
        $user           = new ForumUser();
        $user->username = 'Jasper';
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
     * @param mixed $invalidValue
     *
     * @group DDC-3490
     * @dataProvider invalidAssociationValuesDataProvider
     */
    public function testRejectsPersistenceOfObjectsWithInvalidAssociationValue($invalidValue): void
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

        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->persist($user);
    }

    /**
     * @param mixed $invalidValue
     *
     * @group DDC-3490
     * @dataProvider invalidAssociationValuesDataProvider
     */
    public function testRejectsChangeSetComputationForObjectsWithInvalidAssociationValue($invalidValue): void
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

        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->computeChangeSet($metadata, $user);
    }

    /**
     * @group DDC-3619
     * @group 1338
     */
    public function testRemovedAndRePersistedEntitiesAreInTheIdentityMapAndAreNotGarbageCollected(): void
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
    public function testPersistedEntityAndClearManager(): void
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
    public function testEntityChangeSetIsNotClearedAfterFlushOnSingleEntity(): void
    {
        $entity1 = new NotifyChangedEntity();
        $entity2 = new NotifyChangedEntity();

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
    public function testEntityChangeSetIsNotClearedAfterFlushOnArrayOfEntities(): void
    {
        $entity1 = new NotifyChangedEntity();
        $entity2 = new NotifyChangedEntity();
        $entity3 = new NotifyChangedEntity();

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
    public function invalidAssociationValuesDataProvider(): array
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
     * @param object $entity
     *
     * @dataProvider entitiesWithValidIdentifiersProvider
     */
    public function testAddToIdentityMapValidIdentifiers($entity, string $idHash): void
    {
        $this->_unitOfWork->persist($entity);
        $this->_unitOfWork->addToIdentityMap($entity);

        self::assertSame($entity, $this->_unitOfWork->getByIdHash($idHash, get_class($entity)));
    }

    /**
     * @psalm-return array<string, array{object, string}>
     */
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

    public function testRegisteringAManagedInstanceRequiresANonEmptyIdentifier(): void
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->registerManaged(new EntityWithBooleanIdentifier(), [], []);
    }

    /**
     * @param object               $entity
     * @param array<string, mixed> $identifier
     *
     * @dataProvider entitiesWithInvalidIdentifiersProvider
     */
    public function testAddToIdentityMapInvalidIdentifiers($entity, array $identifier): void
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->registerManaged($entity, $identifier, []);
    }

    /**
     * @psalm-return array<string, array{object, array<string, mixed>}>
     */
    public function entitiesWithInvalidIdentifiersProvider(): array
    {
        $firstNullString = new EntityWithCompositeStringIdentifier();

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
    public function testObjectHashesOfMergedEntitiesAreNotUsedInOriginalEntityDataMap(): void
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
    public function testMergeWithNewEntityWillPersistItAndTriggerPrePersistListenersWithMergedEntityData(): void
    {
        $entity = new EntityWithRandomlyGeneratedField();

        $generatedFieldValue = $entity->generatedField;

        $this
            ->eventManager
            ->expects(self::any())
            ->method('hasListeners')
            ->willReturnCallback(static function ($eventName) {
                return $eventName === Events::prePersist;
            });
        $this
            ->eventManager
            ->expects(self::once())
            ->method('dispatchEvent')
            ->with(
                self::anything(),
                self::callback(static function (LifecycleEventArgs $args) use ($entity, $generatedFieldValue) {
                    $object = $args->getObject();
                    assert($object instanceof EntityWithRandomlyGeneratedField);

                    self::assertInstanceOf(EntityWithRandomlyGeneratedField::class, $object);
                    self::assertNotSame($entity, $object);
                    self::assertSame($generatedFieldValue, $object->generatedField);

                    return true;
                })
            );

        $object = $this->_unitOfWork->merge($entity);
        assert($object instanceof EntityWithRandomlyGeneratedField);

        self::assertNotSame($object, $entity);
        self::assertInstanceOf(EntityWithRandomlyGeneratedField::class, $object);
        self::assertSame($object->generatedField, $entity->generatedField);
    }

    /**
     * @group DDC-1955
     * @group 5570
     * @group 6174
     */
    public function testMergeWithExistingEntityWillNotPersistItNorTriggerPrePersistListeners(): void
    {
        $persistedEntity = new EntityWithRandomlyGeneratedField();
        $mergedEntity    = new EntityWithRandomlyGeneratedField();

        $mergedEntity->id             = $persistedEntity->id;
        $mergedEntity->generatedField = random_int(
            $persistedEntity->generatedField + 1,
            $persistedEntity->generatedField + 1000
        );

        $this
            ->eventManager
            ->expects(self::any())
            ->method('hasListeners')
            ->willReturnCallback(static function ($eventName) {
                return $eventName === Events::prePersist;
            });
        $this->eventManager->expects(self::never())->method('dispatchEvent');

        $this->_unitOfWork->registerManaged(
            $persistedEntity,
            ['id' => $persistedEntity->id],
            ['generatedField' => $persistedEntity->generatedField]
        );

        $merged = $this->_unitOfWork->merge($mergedEntity);
        assert($merged instanceof EntityWithRandomlyGeneratedField);

        self::assertSame($merged, $persistedEntity);
        self::assertSame($persistedEntity->generatedField, $mergedEntity->generatedField);
    }

    /**
     * Unlike next test, this one demonstrates that the problem does
     * not necessarily reproduce if all the pieces are being flushed together.
     *
     * @group DDC-2922
     * @group #1521
     */
    public function testNewAssociatedEntityPersistenceOfNewEntitiesThroughCascadedAssociationsFirst(): void
    {
        $persister1 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(CascadePersistedEntity::class));
        $persister2 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(EntityWithCascadingAssociation::class));
        $persister3 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(EntityWithNonCascadingAssociation::class));
        $this->_unitOfWork->setEntityPersister(CascadePersistedEntity::class, $persister1);
        $this->_unitOfWork->setEntityPersister(EntityWithCascadingAssociation::class, $persister2);
        $this->_unitOfWork->setEntityPersister(EntityWithNonCascadingAssociation::class, $persister3);

        $cascadePersisted = new CascadePersistedEntity();
        $cascading        = new EntityWithCascadingAssociation();
        $nonCascading     = new EntityWithNonCascadingAssociation();

        // First we persist and flush a EntityWithCascadingAssociation with
        // the cascading association not set. Having the "cascading path" involve
        // a non-new object is important to show that the ORM should be considering
        // cascades across entity changesets in subsequent flushes.
        $cascading->cascaded    = $cascadePersisted;
        $nonCascading->cascaded = $cascadePersisted;

        $this->_unitOfWork->persist($cascading);
        $this->_unitOfWork->persist($nonCascading);

        $this->_unitOfWork->commit();

        $this->assertCount(1, $persister1->getInserts());
        $this->assertCount(1, $persister2->getInserts());
        $this->assertCount(1, $persister3->getInserts());
    }

    /**
     * This test exhibits the bug describe in the ticket, where an object that
     * ought to be reachable causes errors.
     *
     * @group DDC-2922
     * @group #1521
     */
    public function testNewAssociatedEntityPersistenceOfNewEntitiesThroughNonCascadedAssociationsFirst(): void
    {
        $persister1 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(CascadePersistedEntity::class));
        $persister2 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(EntityWithCascadingAssociation::class));
        $persister3 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(EntityWithNonCascadingAssociation::class));
        $this->_unitOfWork->setEntityPersister(CascadePersistedEntity::class, $persister1);
        $this->_unitOfWork->setEntityPersister(EntityWithCascadingAssociation::class, $persister2);
        $this->_unitOfWork->setEntityPersister(EntityWithNonCascadingAssociation::class, $persister3);

        $cascadePersisted = new CascadePersistedEntity();
        $cascading        = new EntityWithCascadingAssociation();
        $nonCascading     = new EntityWithNonCascadingAssociation();

        // First we persist and flush a EntityWithCascadingAssociation with
        // the cascading association not set. Having the "cascading path" involve
        // a non-new object is important to show that the ORM should be considering
        // cascades across entity changesets in subsequent flushes.
        $cascading->cascaded = null;

        $this->_unitOfWork->persist($cascading);
        $this->_unitOfWork->commit();

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

        $this->_unitOfWork->persist($nonCascading);
        $this->_unitOfWork->commit();

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
    public function testPreviousDetectedIllegalNewNonCascadedEntitiesAreCleanedUpOnSubsequentCommits(): void
    {
        $persister1 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(CascadePersistedEntity::class));
        $persister2 = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(EntityWithNonCascadingAssociation::class));
        $this->_unitOfWork->setEntityPersister(CascadePersistedEntity::class, $persister1);
        $this->_unitOfWork->setEntityPersister(EntityWithNonCascadingAssociation::class, $persister2);

        $cascadePersisted = new CascadePersistedEntity();
        $nonCascading     = new EntityWithNonCascadingAssociation();

        // We explicitly cause the ORM to detect a non-persisted new entity in the association graph:
        $nonCascading->nonCascaded = $cascadePersisted;

        $this->_unitOfWork->persist($nonCascading);

        try {
            $this->_unitOfWork->commit();

            self::fail('An exception was supposed to be raised');
        } catch (ORMInvalidArgumentException $ignored) {
            self::assertEmpty($persister1->getInserts());
            self::assertEmpty($persister2->getInserts());
        }

        $this->_unitOfWork->clear();
        $this->_unitOfWork->persist(new CascadePersistedEntity());
        $this->_unitOfWork->commit();

        // Persistence operations should just recover normally:
        self::assertCount(1, $persister1->getInserts());
        self::assertCount(0, $persister2->getInserts());
    }

    /**
     * @group #7946 Throw OptimisticLockException when connection::commit() returns false.
     */
    public function testCommitThrowOptimisticLockExceptionWhenConnectionCommitReturnFalse(): void
    {
        // Set another connection mock that fail on commit
        $this->_connectionMock = $this->getMockBuilder(ConnectionMock::class)
            ->setConstructorArgs([[], new DriverMock()])
            ->setMethods(['commit'])
            ->getMock();
        $this->_emMock         = EntityManagerMock::create($this->_connectionMock, null, $this->eventManager);
        $this->_unitOfWork     = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_unitOfWork);

        $this->_connectionMock->method('commit')->willReturn(false);

        // Setup fake persister and id generator
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);

        // Create a test user
        $user           = new ForumUser();
        $user->username = 'Jasper';
        $this->_unitOfWork->persist($user);

        $this->expectException(OptimisticLockException::class);
        $this->_unitOfWork->commit();
    }

    public function testItThrowsWhenLookingUpIdentifierForUnknownEntity(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->_unitOfWork->getEntityIdentifier(new stdClass());
    }
}

/**
 * @Entity
 */
class NotifyChangedEntity implements NotifyPropertyChanged
{
    /** @psalm-var list<PropertyChangedListener> */
    private $_listeners = [];

    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $data;

    /** @var mixed */
    private $transient; // not persisted

    /**
     * @psalm-var Collection<int, NotifyChangedRelatedItem>
     * @OneToMany(targetEntity="NotifyChangedRelatedItem", mappedBy="owner")
     */
    private $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getItems(): Collection
    {
        return $this->items;
    }

    public function setTransient($value): void
    {
        if ($value !== $this->transient) {
            $this->onPropertyChanged('transient', $this->transient, $value);
            $this->transient = $value;
        }
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param mixed $data
     */
    public function setData($data): void
    {
        if ($data !== $this->data) {
            $this->onPropertyChanged('data', $this->data, $data);
            $this->data = $data;
        }
    }

    public function addPropertyChangedListener(PropertyChangedListener $listener): void
    {
        $this->_listeners[] = $listener;
    }

    /**
     * @param mixed $propName
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    protected function onPropertyChanged($propName, $oldValue, $newValue): void
    {
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
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var NotifyChangedEntity|null
     * @ManyToOne(targetEntity="NotifyChangedEntity", inversedBy="items")
     */
    private $owner;

    public function getId(): int
    {
        return $this->id;
    }

    public function getOwner(): ?NotifyChangedEntity
    {
        return $this->owner;
    }

    public function setOwner(?NotifyChangedEntity $owner): void
    {
        $this->owner = $owner;
    }
}

/** @Entity */
class VersionedAssignedIdentifierEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    public $version;
}

/** @Entity */
class EntityWithStringIdentifier
{
    /**
     * @Id @Column(type="string")
     * @var string|null
     */
    public $id;
}

/** @Entity */
class EntityWithBooleanIdentifier
{
    /**
     * @Id @Column(type="boolean")
     * @var bool|null
     */
    public $id;
}

/** @Entity */
class EntityWithCompositeStringIdentifier
{
    /**
     * @Id @Column(type="string")
     * @var string|null
     */
    public $id1;

    /**
     * @Id @Column(type="string")
     * @var string|null
     */
    public $id2;
}

/** @Entity */
class EntityWithRandomlyGeneratedField
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     */
    public $id;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $generatedField;

    public function __construct()
    {
        $this->id             = uniqid('id', true);
        $this->generatedField = random_int(0, 100000);
    }
}

/** @Entity */
class CascadePersistedEntity
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="NONE")
     */
    private $id;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

/** @Entity */
class EntityWithCascadingAssociation
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="NONE")
     */
    private $id;

    /**
     * @var CascadePersistedEntity
     * @ManyToOne(targetEntity=CascadePersistedEntity::class, cascade={"persist"})
     */
    public $cascaded;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

/** @Entity */
class EntityWithNonCascadingAssociation
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="NONE")
     */
    private $id;

    /**
     * @var CascadePersistedEntity
     * @ManyToOne(targetEntity=CascadePersistedEntity::class)
     */
    public $nonCascaded;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}
