<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Tests\OrmFunctionalTestCase;

final class GH7068Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                SomeEntity::class,
            ]
        );
    }

    public function testLockModeIsRespected(): void
    {
        $entity = new SomeEntity();
        $this->_em->persist($entity);
        $this->_em->flush();
        $this->_em->clear();

        $this->_em->find(SomeEntity::class, 1);

        $this->expectException(TransactionRequiredException::class);
        $this->_em->find(SomeEntity::class, 1, LockMode::PESSIMISTIC_WRITE);
    }
}

/** @Entity */
final class SomeEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}
