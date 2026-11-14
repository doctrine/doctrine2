<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache\Persister\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\Cache\Persister\Entity\AbstractEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\CachedEntityPersister;
use Doctrine\ORM\Cache\Region;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

#[Group('DDC-2183')]
abstract class EntityPersisterTestCase extends OrmTestCase
{
    protected Region&MockObject $region;
    protected EntityPersister&MockObject $entityPersister;
    protected EntityManagerMock $em;

    abstract protected function createPersister(EntityManagerInterface $em, EntityPersister $persister, Region $region, ClassMetadata $metadata): AbstractEntityPersister;

    protected function setUp(): void
    {
        $this->getSharedSecondLevelCache()->clear();
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->em              = $this->getTestEntityManager();
        $this->region          = $this->createRegion();
        $this->entityPersister = $this->createMock(EntityPersister::class);
    }

    protected function createRegion(): Region&MockObject
    {
        return $this->createMock(Region::class);
    }

    protected function createPersisterDefault(): AbstractEntityPersister
    {
        return $this->createPersister($this->em, $this->entityPersister, $this->region, $this->em->getClassMetadata(Country::class));
    }

    public function testImplementsEntityPersister(): void
    {
        $persister = $this->createPersisterDefault();

        self::assertInstanceOf(EntityPersister::class, $persister);
        self::assertInstanceOf(CachedPersister::class, $persister);
        self::assertInstanceOf(CachedEntityPersister::class, $persister);
    }

    public function testInvokeAddInsert(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $this->entityPersister->expects(self::once())
            ->method('addInsert')
            ->with(self::identicalTo($entity));

        $persister->addInsert($entity);
    }

    public function testInvokeGetInserts(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $this->entityPersister->expects(self::once())
            ->method('getInserts')
            ->willReturn([$entity]);

        self::assertSame([$entity], $persister->getInserts());
    }

    public function testInvokeGetSelectSQL(): void
    {
        $persister = $this->createPersisterDefault();

        $associationMapping = new OneToOneInverseSideMapping('foo', 'bar', 'baz');

        $this->entityPersister->expects(self::once())
            ->method('getSelectSQL')
            ->with(
                self::identicalTo(['name' => 'Foo']),
                self::identicalTo($associationMapping),
                self::identicalTo(LockMode::OPTIMISTIC),
                self::identicalTo(2),
                self::identicalTo(3),
                self::identicalTo([4]),
            )
            ->willReturn('SELECT * FROM foo WERE name = ?');

        self::assertSame('SELECT * FROM foo WERE name = ?', $persister->getSelectSQL(
            ['name' => 'Foo'],
            $associationMapping,
            LockMode::OPTIMISTIC,
            2,
            3,
            [4],
        ));
    }

    public function testInvokeGetInsertSQL(): void
    {
        $persister = $this->createPersisterDefault();

        $this->entityPersister->expects(self::once())
            ->method('getInsertSQL')
            ->willReturn('INSERT INTO foo (?)');

        self::assertSame('INSERT INTO foo (?)', $persister->getInsertSQL());
    }

    public function testInvokeExpandParameters(): void
    {
        $persister = $this->createPersisterDefault();

        $this->entityPersister->expects(self::once())
            ->method('expandParameters')
            ->with(self::identicalTo(['name' => 'Foo']))
            ->willReturn(['name' => 'Foo']);

        self::assertSame(['name' => 'Foo'], $persister->expandParameters(['name' => 'Foo']));
    }

    public function testInvokeExpandCriteriaParameters(): void
    {
        $persister = $this->createPersisterDefault();
        $criteria  = Criteria::create();

        $this->entityPersister->expects(self::once())
            ->method('expandCriteriaParameters')
            ->with(self::identicalTo($criteria))
            ->willReturn(['name' => 'Foo']);

        self::assertSame(['name' => 'Foo'], $persister->expandCriteriaParameters($criteria));
    }

    public function testInvokeSelectConditionStatementSQL(): void
    {
        $persister = $this->createPersisterDefault();

        $associationMapping = new OneToOneInverseSideMapping('foo', 'bar', 'baz');

        $this->entityPersister->expects(self::once())
            ->method('getSelectConditionStatementSQL')
            ->with(
                self::identicalTo('id'),
                self::identicalTo(1),
                self::identicalTo($associationMapping),
                self::identicalTo('='),
            )->willReturn('name = 1');

        self::assertSame('name = 1', $persister->getSelectConditionStatementSQL('id', 1, $associationMapping, '='));
    }

    public function testInvokeExecuteInserts(): void
    {
        $persister = $this->createPersisterDefault();

        $this->entityPersister->expects(self::once())
            ->method('executeInserts');

        $persister->executeInserts();
    }

    public function testInvokeUpdate(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $this->entityPersister->expects(self::once())
            ->method('update')
            ->with(self::identicalTo($entity));

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $persister->update($entity);
    }

    public function testInvokeDelete(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $this->entityPersister->expects(self::once())
            ->method('delete')
            ->with(self::identicalTo($entity))
            ->willReturn(true);

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        self::assertTrue($persister->delete($entity));
    }

    public function testInvokeGetOwningTable(): void
    {
        $persister = $this->createPersisterDefault();

        $this->entityPersister->expects(self::once())
            ->method('getOwningTable')
            ->with(self::identicalTo('name'))
            ->willReturn('t');

        self::assertSame('t', $persister->getOwningTable('name'));
    }

    public function testInvokeLoad(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $associationMapping = new OneToOneInverseSideMapping('foo', 'bar', 'baz');

        $this->entityPersister->expects(self::once())
            ->method('load')
            ->with(
                self::identicalTo(['id' => 1]),
                self::identicalTo($entity),
                self::identicalTo($associationMapping),
                self::identicalTo([1]),
                self::identicalTo(LockMode::PESSIMISTIC_READ),
                self::identicalTo(3),
                self::identicalTo([4]),
            )
            ->willReturn($entity);

        self::assertSame($entity, $persister->load(
            ['id' => 1],
            $entity,
            $associationMapping,
            [1],
            LockMode::PESSIMISTIC_READ,
            3,
            [4],
        ));
    }

    public function testInvokeLoadAll(): void
    {
        $rsm       = new ResultSetMappingBuilder($this->em);
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $rsm->addEntityResult(Country::class, 'c');

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);

        $this->entityPersister->method('expandParameters')->willReturn([[], []]);
        $this->entityPersister->expects(self::once())
            ->method('loadAll')
            ->with(self::identicalTo(['id' => 1]), self::identicalTo([0]), self::identicalTo(1), self::identicalTo(2))
            ->willReturn([$entity]);

        $this->entityPersister->expects(self::once())
            ->method('getResultSetMapping')
            ->willReturn($rsm);

        self::assertSame([$entity], $persister->loadAll(['id' => 1], [0], 1, 2));
    }

    public function testInvokeLoadById(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $this->entityPersister->expects(self::once())
            ->method('loadById')
            ->with(self::identicalTo(['id' => 1]), self::identicalTo($entity))
            ->willReturn($entity);

        self::assertSame($entity, $persister->loadById(['id' => 1], $entity));
    }

    public function testInvokeLoadOneToOneEntity(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');
        $owner     = (object) [];

        $associationMapping = new OneToOneInverseSideMapping('foo', 'bar', 'baz');

        $this->entityPersister->expects(self::once())
            ->method('loadOneToOneEntity')
            ->with(self::identicalTo($associationMapping), self::identicalTo($owner), self::identicalTo(['id' => 11]))
            ->willReturn($entity);

        self::assertSame($entity, $persister->loadOneToOneEntity($associationMapping, $owner, ['id' => 11]));
    }

    public function testInvokeRefresh(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $this->entityPersister->expects(self::once())
            ->method('refresh')
            ->with(self::identicalTo(['id' => 1]), self::identicalTo($entity), self::identicalTo(null));

        $persister->refresh(['id' => 1], $entity);
    }

    public function testInvokeLoadCriteria(): void
    {
        $rsm       = new ResultSetMappingBuilder($this->em);
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');
        $criteria  = Criteria::create();

        $this->em->getUnitOfWork()->registerManaged($entity, ['id' => 1], ['id' => 1, 'name' => 'Foo']);
        $rsm->addEntityResult(Country::class, 'c');

        $this->entityPersister->method('expandCriteriaParameters')->willReturn([[], []]);
        $this->entityPersister->expects(self::once())
            ->method('getResultSetMapping')
            ->willReturn($rsm);

        $this->entityPersister->expects(self::once())
            ->method('loadCriteria')
            ->with(self::identicalTo($criteria))
            ->willReturn([$entity]);

        self::assertSame([$entity], $persister->loadCriteria($criteria));
    }

    public function testInvokeGetManyToManyCollection(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');
        $owner     = (object) [];

        $associationMapping = new OneToOneInverseSideMapping('foo', 'bar', 'baz');

        $this->entityPersister->expects(self::once())
            ->method('getManyToManyCollection')
            ->with(self::identicalTo($associationMapping), self::identicalTo($owner), self::identicalTo(1), self::identicalTo(2))
            ->willReturn([$entity]);

        self::assertSame([$entity], $persister->getManyToManyCollection($associationMapping, $owner, 1, 2));
    }

    public function testInvokeGetOneToManyCollection(): void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');
        $owner     = (object) [];

        $associationMapping = new OneToOneInverseSideMapping('foo', 'bar', 'baz');

        $this->entityPersister->expects(self::once())
            ->method('getOneToManyCollection')
            ->with(self::identicalTo($associationMapping), self::identicalTo($owner), self::identicalTo(1), self::identicalTo(2))
            ->willReturn([$entity]);

        self::assertSame([$entity], $persister->getOneToManyCollection($associationMapping, $owner, 1, 2));
    }

    public function testInvokeLoadManyToManyCollection(): void
    {
        $mapping   = $this->em->getClassMetadata(Country::class);
        $assoc     = new OneToOneInverseSideMapping('foo', 'bar', 'baz');
        $coll      = new PersistentCollection($this->em, $mapping, new ArrayCollection());
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');
        $owner     = (object) [];

        $this->entityPersister->expects(self::once())
            ->method('loadManyToManyCollection')
            ->with(self::identicalTo($assoc), self::identicalTo($owner), self::identicalTo($coll))
            ->willReturn([$entity]);

        self::assertSame([$entity], $persister->loadManyToManyCollection($assoc, $owner, $coll));
    }

    public function testInvokeLoadOneToManyCollection(): void
    {
        $mapping   = $this->em->getClassMetadata(Country::class);
        $assoc     = new OneToOneInverseSideMapping('foo', 'bar', 'baz');
        $coll      = new PersistentCollection($this->em, $mapping, new ArrayCollection());
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');
        $owner     = (object) [];

        $this->entityPersister->expects(self::once())
            ->method('loadOneToManyCollection')
            ->with(self::identicalTo($assoc), self::identicalTo($owner), self::identicalTo($coll))
            ->willReturn([$entity]);

        self::assertSame([$entity], $persister->loadOneToManyCollection($assoc, $owner, $coll));
    }

    public function testInvokeLock(): void
    {
        $identifier = ['id' => 1];
        $persister  = $this->createPersisterDefault();

        $this->entityPersister->expects(self::once())
            ->method('lock')
            ->with(self::identicalTo($identifier), self::identicalTo(LockMode::OPTIMISTIC));

        $persister->lock($identifier, LockMode::OPTIMISTIC);
    }

    public function testInvokeExists(): void
    {
        $entity    = new Country('Foo');
        $persister = $this->createPersisterDefault();

        $this->entityPersister->expects(self::once())
            ->method('exists')
            ->with(self::identicalTo($entity), self::identicalTo(null))
            ->willReturn(true);

        self::assertTrue($persister->exists($entity));
    }
}
