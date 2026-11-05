<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\DbalTypes\CustomIdObjectType;
use Doctrine\Tests\OrmFunctionalTestCase;

class DatabaseValueComparisonTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        self::registerDbalType(CustomIdObjectType::NAME, CustomIdObjectType::class);

        parent::setUp();

        $this->createSchemaForModels(DatabaseValueComparisonEntity::class);
    }

    public function testSameDateDifferentTimeDoesNotProduceChangeSet(): void
    {
        $entity       = $this->createEntity();
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        // The date type stores only the day, so a different time of day on the
        // same day is not a change.
        $entity->date = new DateTime('2020-01-01 23:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayNotHasKey('date', $uow->getEntityChangeSet($entity));
    }

    public function testDifferentDateProducesChangeSet(): void
    {
        $entity       = $this->createEntity();
        $entity->date = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        $entity->date = new DateTime('2020-01-02 10:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayHasKey('date', $uow->getEntityChangeSet($entity));
    }

    public function testEqualDateTimeDoesNotProduceChangeSet(): void
    {
        $entity           = $this->createEntity();
        $entity->dateTime = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        // Replacing the date with an equal instance is not a change.
        $entity->dateTime = new DateTime('2020-01-01 10:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayNotHasKey('dateTime', $uow->getEntityChangeSet($entity));
    }

    public function testDifferentDateTimeProducesChangeSet(): void
    {
        $entity           = $this->createEntity();
        $entity->dateTime = new DateTime('2020-01-01 10:00:00');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        $entity->dateTime = new DateTime('2020-01-01 11:00:00');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayHasKey('dateTime', $uow->getEntityChangeSet($entity));
    }

    public function testEqualValueObjectDoesNotProduceChangeSet(): void
    {
        $entity        = $this->createEntity();
        $entity->value = new CustomIdObject('10');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        // Replacing the value object with an equal one is not a change.
        $entity->value = new CustomIdObject('10');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayNotHasKey('value', $uow->getEntityChangeSet($entity));
    }

    public function testDifferentValueObjectProducesChangeSet(): void
    {
        $entity        = $this->createEntity();
        $entity->value = new CustomIdObject('10');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        // Replacing the value object with a different one is a change.
        $entity->value = new CustomIdObject('12');

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertArrayHasKey('value', $uow->getEntityChangeSet($entity));
    }

    public function testRecomputeSingleEntityChangeSetComparesDatabaseValues(): void
    {
        $entity        = $this->createEntity();
        $entity->value = new CustomIdObject('10');

        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->id;
        $this->_em->clear();

        $entity = $this->_em->find(DatabaseValueComparisonEntity::class, $id);
        self::assertInstanceOf(DatabaseValueComparisonEntity::class, $entity);

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSet($this->_em->getClassMetadata($entity::class), $entity);

        $entity->value = new CustomIdObject('10');

        $uow->recomputeSingleEntityChangeSet($this->_em->getClassMetadata($entity::class), $entity);

        self::assertArrayNotHasKey('value', $uow->getEntityChangeSet($entity));
    }

    private function createEntity(): DatabaseValueComparisonEntity
    {
        $entity           = new DatabaseValueComparisonEntity();
        $entity->date     = new DateTime('2000-01-01 00:00:00');
        $entity->dateTime = new DateTime('2000-01-01 00:00:00');
        $entity->value    = new CustomIdObject('default');

        return $entity;
    }
}

#[ORM\Entity]
class DatabaseValueComparisonEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'date')]
    public DateTime $date;

    #[ORM\Column(type: 'datetime')]
    public DateTime $dateTime;

    #[ORM\Column(type: CustomIdObjectType::NAME, length: 255)]
    public CustomIdObject $value;
}
