<?php
namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\DBAL\Types\Types;

class DDC425Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp() : void
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
    public function testIssue()
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

        $num = $this->_em->createQuery('DELETE '.__NAMESPACE__.'\DDC425Entity e WHERE e.someDatetimeField > ?1')
                ->setParameter(1, new DateTime, Types::DATETIME_MUTABLE)
                ->getResult();
        $this->assertEquals(0, $num);
    }
}

/** @Entity */
class DDC425Entity {
    /**
     * @Id @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /** @Column(type="datetime") */
    public $someDatetimeField;
}
