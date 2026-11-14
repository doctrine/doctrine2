<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH12017SubClassTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH12017ParentEntity::class, GH12017ChildEntity::class);
    }

    public function testGeneratedFieldFromChildShouldNotBeDetectedAsChangeAfterInsert(): void
    {
        $entity = new GH12017ChildEntity();

        $this->_em->persist($entity);
        $this->_em->flush();

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertFalse(
            $uow->isScheduledForUpdate($entity),
            'Entity should not be scheduled for update after a generated field was refreshed from the DB',
        );
    }

    public function testGeneratedStringFieldShouldNotBeDetectedAsChangeAfterInsert(): void
    {
        $entity = new GH12017ChildEntity();
        $this->_em->persist($entity);
        $this->_em->flush();

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertFalse(
            $uow->isScheduledForUpdate($entity),
            'Entity should not be scheduled for update after a generated string field was refreshed from the DB',
        );
    }

    public function testGeneratedFieldShouldNotBeDetectedAsChangeAfterUpdate(): void
    {
        $entity = new GH12017ChildEntity();
        $this->_em->persist($entity);
        $this->_em->flush();

        $entity->other = 1;
        $this->_em->flush();

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();
        self::assertFalse(
            $uow->isScheduledForUpdate($entity),
            'Entity should not be scheduled for update after a generated field was refreshed from the DB',
        );
    }
}

#[ORM\MappedSuperclass]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'string')]
#[DiscriminatorMap(['child' => GH12017ChildEntity::class])]
#[ORM\Entity]
#[ORM\Table(name: 'gh12017_parent')]
class GH12017ParentEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    #[ORM\Column(
        type: Types::INTEGER,
        nullable: false,
        options: ['default' => 0],
    )]
    public int $other = 0;
}

#[ORM\Entity]
#[ORM\Table(name: 'gh12017_child')]
class GH12017ChildEntity extends GH12017ParentEntity
{
    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        insertable: false,
        updatable: false,
        options: ['default' => 'CURRENT_TIMESTAMP'],
        generated: 'ALWAYS',
    )]
    public DateTimeImmutable|null $tested = null;
}
