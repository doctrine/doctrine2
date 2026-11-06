<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Exception\EntityIdentityCollisionException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Version;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\EntityPersisterMock;
use Doctrine\Tests\Mocks\UnitOfWorkMock;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\MixedToOneIdentity\Country;
use Doctrine\Tests\OrmTestCase;
use Exception as BaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use stdClass;

use function enum_exists;
use function uniqid;

/**
 * UnitOfWork tests.
 */
class UnitOfWorkTest extends OrmTestCase
{
    /**
     * SUT
     */
    private UnitOfWorkMock $_unitOfWork;

    /**
     * Provides a sequence mock to the UnitOfWork
     */
    private Connection $connection;

    /**
     * The EntityManager mock that provides the mock persisters
     */
    private EntityManagerMock $_emMock;

    private EventManager&Stub $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $driverStatement = $this->createStub(Statement::class);

        $driverConnection = $this->createStub(Driver\Connection::class);
        $driverConnection->method('prepare')
            ->willReturn($driverStatement);
        $driverConnection->method('lastInsertId')
            ->willReturnOnConsecutiveCalls(1, 2, 3, 4, 5, 6);

        $driver = $this->createStub(Driver::class);
        $driver->method('getDatabasePlatform')
            ->willReturn($platform);
        $driver->method('connect')
            ->willReturn($driverConnection);

        $this->eventManager = $this->getMockBuilder(EventManager::class)->getMock();
        $this->connection   = new Connection([], $driver, null, $this->eventManager);
        $this->_emMock      = new EntityManagerMock($this->connection);
        // SUT
        $this->_unitOfWork = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_unitOfWork);
    }

    public function testRegisterRemovedOnNewEntityIsIgnored(): void
    {
        $user           = new ForumUser();
        $user->username = 'romanb';
        self::assertFalse($this->_unitOfWork->isScheduledForDelete($user));
        $this->_unitOfWork->scheduleForDelete($user);
        self::assertFalse($this->_unitOfWork->isScheduledForDelete($user));
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
        self::assertCount(0, $userPersister->getInserts());
        self::assertCount(0, $userPersister->getUpdates());
        self::assertCount(0, $userPersister->getDeletes());
        self::assertFalse($this->_unitOfWork->isInIdentityMap($user));
        // should no longer be scheduled for insert
        self::assertTrue($this->_unitOfWork->isScheduledForInsert($user));

        // Now lets check whether a subsequent commit() does anything
        $userPersister->reset();

        // Test
        $this->_unitOfWork->commit();

        // Check.
        self::assertCount(1, $userPersister->getInserts());
        self::assertCount(0, $userPersister->getUpdates());
        self::assertCount(0, $userPersister->getDeletes());

        // should have an id
        self::assertIsNumeric($user->id);
    }

    #[Group('#11977')]
    public function testMultipleInsertsAreBatchedInThePersister(): void
    {
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(Country::class));
        $this->_unitOfWork->setEntityPersister(Country::class, $userPersister);

        $country1          = new Country();
        $country1->country = 'Italy';
        $country2          = new Country();
        $country2->country = 'Germany';

        $this->_unitOfWork->persist($country1);
        $this->_unitOfWork->persist($country2);
        $this->_unitOfWork->commit();

        self::assertCount(2, $userPersister->getInserts());
        self::assertSame(1, $userPersister->countOfExecuteInsertCalls());
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

        self::assertIsNumeric($user->id);
        self::assertIsNumeric($avatar->id);

        self::assertCount(1, $userPersister->getInserts());
        self::assertCount(0, $userPersister->getUpdates());
        self::assertCount(0, $userPersister->getDeletes());

        self::assertCount(1, $avatarPersister->getInserts());
        self::assertCount(0, $avatarPersister->getUpdates());
        self::assertCount(0, $avatarPersister->getDeletes());
    }

    public function testGetEntityStateOnVersionedEntityWithAssignedIdentifier(): void
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(VersionedAssignedIdentifierEntity::class));
        $this->_unitOfWork->setEntityPersister(VersionedAssignedIdentifierEntity::class, $persister);

        $e     = new VersionedAssignedIdentifierEntity();
        $e->id = 42;
        self::assertEquals(UnitOfWork::STATE_NEW, $this->_unitOfWork->getEntityState($e));
        self::assertFalse($persister->isExistsCalled());
    }

    public function testGetEntityStateWithAssignedIdentity(): void
    {
        $persister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(CmsPhonenumber::class));
        $this->_unitOfWork->setEntityPersister(CmsPhonenumber::class, $persister);

        $ph              = new CmsPhonenumber();
        $ph->phonenumber = '12345';

        self::assertEquals(UnitOfWork::STATE_NEW, $this->_unitOfWork->getEntityState($ph));
        self::assertTrue($persister->isExistsCalled());

        $persister->reset();

        // if the entity is already managed the exists() check should be skipped
        $this->_unitOfWork->registerManaged($ph, ['phonenumber' => '12345'], []);
        self::assertEquals(UnitOfWork::STATE_MANAGED, $this->_unitOfWork->getEntityState($ph));
        self::assertFalse($persister->isExistsCalled());
        $ph2              = new CmsPhonenumber();
        $ph2->phonenumber = '12345';
        self::assertEquals(UnitOfWork::STATE_DETACHED, $this->_unitOfWork->getEntityState($ph2));
        self::assertFalse($persister->isExistsCalled());
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

    #[DataProvider('invalidAssociationValuesDataProvider')]
    #[Group('DDC-3490')]
    public function testRejectsPersistenceOfObjectsWithInvalidAssociationValue(mixed $invalidValue): void
    {
        $this->_unitOfWork->setEntityPersister(
            ForumUser::class,
            new EntityPersisterMock(
                $this->_emMock,
                $this->_emMock->getClassMetadata(ForumUser::class),
            ),
        );

        $user           = new ForumUser();
        $user->username = 'John';
        $user->avatar   = $invalidValue;

        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->persist($user);
    }

    #[DataProvider('invalidAssociationValuesDataProvider')]
    #[Group('DDC-3490')]
    public function testRejectsChangeSetComputationForObjectsWithInvalidAssociationValue(mixed $invalidValue): void
    {
        $metadata = $this->_emMock->getClassMetadata(ForumUser::class);

        $this->_unitOfWork->setEntityPersister(
            ForumUser::class,
            new EntityPersisterMock($this->_emMock, $metadata),
        );

        $user = new ForumUser();

        $this->_unitOfWork->persist($user);

        $user->username = 'John';
        $user->avatar   = $invalidValue;

        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->computeChangeSet($metadata, $user);
    }

    #[Group('DDC-3619')]
    #[Group('1338')]
    public function testRemovedAndRePersistedEntitiesAreInTheIdentityMapAndAreNotGarbageCollected(): void
    {
        $entity     = new ForumUser();
        $entity->id = 123;

        $this->_unitOfWork->registerManaged($entity, ['id' => 123], []);
        self::assertSame(UnitOfWork::STATE_MANAGED, $this->_unitOfWork->getEntityState($entity));
        self::assertFalse($this->_unitOfWork->isScheduledForDelete($entity));
        self::assertTrue($this->_unitOfWork->isInIdentityMap($entity));

        $this->_unitOfWork->remove($entity);
        self::assertSame(UnitOfWork::STATE_REMOVED, $this->_unitOfWork->getEntityState($entity));
        self::assertTrue($this->_unitOfWork->isScheduledForDelete($entity));
        self::assertTrue($this->_unitOfWork->isInIdentityMap($entity));

        $this->_unitOfWork->persist($entity);
        self::assertSame(UnitOfWork::STATE_MANAGED, $this->_unitOfWork->getEntityState($entity));
        self::assertFalse($this->_unitOfWork->isScheduledForDelete($entity));
        self::assertTrue($this->_unitOfWork->isInIdentityMap($entity));
    }

    /**
     * Data Provider
     *
     * @return mixed[][]
     */
    public static function invalidAssociationValuesDataProvider(): array
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

    #[DataProvider('entitiesWithValidIdentifiersProvider')]
    public function testAddToIdentityMapValidIdentifiers(object $entity, string $idHash): void
    {
        $this->_unitOfWork->persist($entity);
        $this->_unitOfWork->addToIdentityMap($entity);

        self::assertSame($entity, $this->_unitOfWork->getByIdHash($idHash, $entity::class));
    }

    /** @phpstan-return array<string, array{object, string}> */
    public static function entitiesWithValidIdentifiersProvider(): array
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

    /** @param array<string, mixed> $identifier */
    #[DataProvider('entitiesWithInvalidIdentifiersProvider')]
    public function testAddToIdentityMapInvalidIdentifiers(object $entity, array $identifier): void
    {
        $this->expectException(ORMInvalidArgumentException::class);

        $this->_unitOfWork->registerManaged($entity, $identifier, []);
    }

    /** @phpstan-return array<string, array{object, array<string, mixed>}> */
    public static function entitiesWithInvalidIdentifiersProvider(): array
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
     * Unlike next test, this one demonstrates that the problem does
     * not necessarily reproduce if all the pieces are being flushed together.
     */
    #[Group('DDC-2922')]
    #[Group('#1521')]
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
        $cascading->cascaded       = $cascadePersisted;
        $nonCascading->nonCascaded = $cascadePersisted;

        $this->_unitOfWork->persist($cascading);
        $this->_unitOfWork->persist($nonCascading);

        $this->_unitOfWork->commit();

        self::assertCount(1, $persister1->getInserts());
        self::assertCount(1, $persister2->getInserts());
        self::assertCount(1, $persister3->getInserts());
    }

    /**
     * This test exhibits the bug describe in the ticket, where an object that
     * ought to be reachable causes errors.
     */
    #[Group('DDC-2922')]
    #[Group('#1521')]
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
     */
    #[Group('DDC-2922')]
    #[Group('#1521')]
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
        } catch (ORMInvalidArgumentException) {
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

    #[Group('#7946')] // Throw OptimisticLockException when connection::commit() returns false.
    public function testCommitThrowOptimisticLockExceptionWhenConnectionCommitFails(): void
    {
        $platform = $this->getMockBuilder(AbstractPlatform::class)
            ->setConstructorArgs(enum_exists(UnquotedIdentifierFolding::class) ? [UnquotedIdentifierFolding::UPPER] : [])
            ->getMock();
        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $driver = $this->createStub(Driver::class);
        $driver->method('connect')
            ->willReturn($this->createStub(Driver\Connection::class));
        $driver->method('getDatabasePlatform')
            ->willReturn($platform);

        // Set another connection mock that fail on commit
        $this->connection  = $this->getMockBuilder(Connection::class)
            ->onlyMethods(['commit'])
            ->setConstructorArgs([[], $driver])
            ->getMock();
        $this->_emMock     = new EntityManagerMock($this->connection);
        $this->_unitOfWork = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_unitOfWork);

        $this->connection->method('commit')
            ->willThrowException($this->createStub(Exception::class));

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

    public function testRemovedEntityIsRemovedFromManyToManyCollection(): void
    {
        $group       = new CmsGroup();
        $group->name = 'test';
        $this->_unitOfWork->persist($group);

        $user       = new CmsUser();
        $user->name = 'test';
        $user->groups->add($group);
        $this->_unitOfWork->persist($user);

        $this->_unitOfWork->commit();

        self::assertFalse($user->groups->isDirty());

        $this->_unitOfWork->remove($group);
        $this->_unitOfWork->commit();

        // Test that the removed entity has been removed from the many to many collection
        self::assertEmpty(
            $user->groups,
            'the removed entity should have been removed from the many to many collection',
        );

        // Collection is clean, snapshot has been updated
        self::assertFalse($user->groups->isDirty());
        self::assertEmpty($user->groups->getSnapshot());
    }

    public function testRemovedEntityIsRemovedFromOneToManyCollection(): void
    {
        $user       = new CmsUser();
        $user->name = 'test';

        $phonenumber              = new CmsPhonenumber();
        $phonenumber->phonenumber = '0800-123456';

        $user->addPhonenumber($phonenumber);

        $this->_unitOfWork->persist($user);
        $this->_unitOfWork->persist($phonenumber);
        $this->_unitOfWork->commit();

        self::assertFalse($user->phonenumbers->isDirty());

        $this->_unitOfWork->remove($phonenumber);
        $this->_unitOfWork->commit();

        // Test that the removed entity has been removed from the one to many collection
        self::assertEmpty($user->phonenumbers);

        // Collection is clean, snapshot has been updated
        self::assertFalse($user->phonenumbers->isDirty());
        self::assertEmpty($user->phonenumbers->getSnapshot());
    }

    public function testItThrowsWhenApplicationProvidedIdsCollide(): void
    {
        // We're using application-provided IDs and assign the same ID twice
        // Note this is about colliding IDs in the identity map in memory.
        // Duplicate database-level IDs would be spotted when the EM is flushed.

        $phone1              = new CmsPhonenumber();
        $phone1->phonenumber = '1234';
        $this->_unitOfWork->persist($phone1);

        $phone2              = new CmsPhonenumber();
        $phone2->phonenumber = '1234';

        $this->expectException(EntityIdentityCollisionException::class);
        $this->expectExceptionMessageMatches('/another object .* was already present for the same ID/');

        $this->_unitOfWork->persist($phone2);
    }

    public function testItPreservesTheOriginalExceptionOnRollbackFailure(): void
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')
            ->willReturn($this->createStub(Driver\Connection::class));

        $connection        = new class (['platform' => $this->createStub(AbstractPlatform::class)], $driver) extends Connection {
            public function commit(): void
            {
                throw new BaseException('Commit failed');
            }

            public function rollBack(): void
            {
                throw new BaseException('Rollback exception');
            }
        };
        $this->_emMock     = new EntityManagerMock($connection);
        $this->_unitOfWork = new UnitOfWorkMock($this->_emMock);
        $this->_emMock->setUnitOfWork($this->_unitOfWork);

        // Setup fake persister and id generator
        $userPersister = new EntityPersisterMock($this->_emMock, $this->_emMock->getClassMetadata(ForumUser::class));
        $userPersister->setMockIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $this->_unitOfWork->setEntityPersister(ForumUser::class, $userPersister);

        // Create a test user
        $user           = new ForumUser();
        $user->username = 'Jasper';
        $this->_unitOfWork->persist($user);

        try {
            $this->_unitOfWork->commit();
            self::fail('Exception expected');
        } catch (BaseException $e) {
            self::assertSame('Rollback exception', $e->getMessage());
            self::assertNotNull($e->getPrevious());
            self::assertSame('Commit failed', $e->getPrevious()->getMessage());
        }
    }
}


#[Entity]
class VersionedAssignedIdentifierEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public int $id;

    #[Version]
    #[Column(type: 'integer')]
    public int $version;
}

#[Entity]
class EntityWithStringIdentifier
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    public string|null $id = null;
}

#[Entity]
class EntityWithBooleanIdentifier
{
    #[Id]
    #[Column(type: 'boolean')]
    public bool|null $id = null;
}

#[Entity]
class EntityWithCompositeStringIdentifier
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    public string|null $id1 = null;

    #[Id]
    #[Column(type: 'string', length: 255)]
    public string|null $id2 = null;
}

#[Entity]
class CascadePersistedEntity
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    #[GeneratedValue(strategy: 'NONE')]
    private string $id;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

#[Entity]
class EntityWithCascadingAssociation
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    #[GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[ManyToOne(targetEntity: CascadePersistedEntity::class, cascade: ['persist'])]
    public CascadePersistedEntity|null $cascaded = null;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}

#[Entity]
class EntityWithNonCascadingAssociation
{
    #[Id]
    #[Column(type: 'string', length: 255)]
    #[GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[ManyToOne(targetEntity: CascadePersistedEntity::class)]
    public CascadePersistedEntity|null $nonCascaded = null;

    public function __construct()
    {
        $this->id = uniqid(self::class, true);
    }
}
