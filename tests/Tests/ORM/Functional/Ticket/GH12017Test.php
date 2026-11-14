<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

class GH12017Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(GH12017Entity::class, GH12017EntityWithStringField::class);
    }

    public function testGeneratedFieldShouldNotBeDetectedAsChangeAfterFlush(): void
    {
        $entity = new GH12017Entity();

        $this->_em->persist($entity);
        $this->_em->flush();

        $uow = $this->_em->getUnitOfWork();
        $uow->computeChangeSets();

        self::assertFalse(
            $uow->isScheduledForUpdate($entity),
            'Entity should not be scheduled for update after a generated field was refreshed from the DB',
        );
    }

    public function testGeneratedStringFieldShouldNotBeDetectedAsChangeAfterFlush(): void
    {
        $entity = new GH12017EntityWithStringField();

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
        $entity = new GH12017Entity();

        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $entity        = $this->_em->find(GH12017Entity::class, $entity->id);
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

#[ORM\Entity]
#[ORM\Table(name: 'gh12017')]
class GH12017Entity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: false,
        insertable: false,
        updatable: false,
        options: ['default' => 'CURRENT_TIMESTAMP'],
        generated: 'ALWAYS',
    )]
    public DateTimeImmutable|null $tested = null;

    #[ORM\Column(
        type: Types::INTEGER,
        nullable: false,
        options: ['default' => 0],
    )]
    public int $other = 0;
}

#[ORM\Entity]
#[ORM\Table(name: 'gh12017_string')]
class GH12017EntityWithStringField
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    #[ORM\Column(
        type: Types::STRING,
        insertable: false,
        updatable: false,
        options: ['default' => 'generated'],
        generated: 'ALWAYS',
    )]
    public string|null $tested = null;
}
