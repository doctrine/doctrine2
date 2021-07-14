<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC425Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC425Entity::class),
            ]
        );
    }

    /**
     * @group DDC-425
     */
    public function testIssue(): void
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

        $num = $this->_em->createQuery('DELETE ' . __NAMESPACE__ . '\DDC425Entity e WHERE e.someDatetimeField > ?1')
                ->setParameter(1, new DateTime(), Type::DATETIME)
                ->getResult();
        $this->assertEquals(0, $num);
    }
}

/** @Entity */
class DDC425Entity
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DateTime
     * @Column(type="datetime")
     */
    public $someDatetimeField;
}
