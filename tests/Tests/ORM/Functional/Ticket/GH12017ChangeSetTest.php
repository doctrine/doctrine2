<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH12017ChangeSetTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH12017ChangeSetNonUpdatableEntity::class, GH12017ChangeSetNonInsertableEntity::class);
    }

    public function testNonInsertableFieldShouldNotAppearInChangeSetOnInsert(): void
    {
        $entity = new GH12017ChangeSetNonInsertableEntity();

        $this->_em->persist($entity);
        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        $changeSet = $uow->getEntityChangeSet($entity);
        self::assertArrayHasKey('other', $changeSet);
        self::assertArrayNotHasKey('tested', $changeSet, 'non-insertable field should not appear in change set on insert');
    }

    public function testNonInsertableFieldShouldAppearInChangeSetOnUpdate(): void
    {
        $entity = new GH12017ChangeSetNonInsertableEntity();
        $this->_em->persist($entity);
        $this->_em->flush();

        $entity->tested = new DateTimeImmutable();
        $entity->other  = 1;

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        $changeSet = $uow->getEntityChangeSet($entity);
        self::assertArrayHasKey('other', $changeSet);
        self::assertArrayHasKey('tested', $changeSet, 'Non-insertable field should still appear in change set on update');
    }

    public function testNonUpdatableFieldShouldAppearInChangeSetOnInsert(): void
    {
        $entity = new GH12017ChangeSetNonUpdatableEntity();
        $this->_em->persist($entity);

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        $changeSet = $uow->getEntityChangeSet($entity);
        self::assertArrayHasKey('other', $changeSet);
        self::assertArrayHasKey('tested', $changeSet, 'Non-updatable field should still appear in change set on insert');
    }

    public function testNonUpdatableFieldShouldNotAppearInChangeSetOnUpdate(): void
    {
        $entity = new GH12017ChangeSetNonUpdatableEntity();
        $this->_em->persist($entity);
        $this->_em->flush();

        $entity->tested = new DateTimeImmutable();
        $entity->other  = 1;

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        $changeSet = $uow->getEntityChangeSet($entity);

        self::assertArrayHasKey('other', $changeSet);
        self::assertArrayNotHasKey('tested', $changeSet, 'Non-updatable field should not appear in change set on update');
    }
}

#[ORM\Entity]
#[ORM\Table(name: 'gh12017_changeset_non_insertable')]
class GH12017ChangeSetNonInsertableEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
        insertable: false,
        updatable: true,
        options: ['default' => 'CURRENT_TIMESTAMP'],
    )]
    public DateTimeImmutable|null $tested = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $other = 0;
}

#[ORM\Entity]
#[ORM\Table(name: 'gh12017_changeset_non_updatable')]
class GH12017ChangeSetNonUpdatableEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
        insertable: true,
        updatable: false,
        options: ['default' => 'CURRENT_TIMESTAMP'],
    )]
    public DateTimeImmutable|null $tested = null;

    #[ORM\Column(type: Types::INTEGER)]
    public int $other = 0;
}
