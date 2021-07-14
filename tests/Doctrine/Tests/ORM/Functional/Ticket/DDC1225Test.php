<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;
use PDOException;

use function strtolower;

/**
 * @group DDC-1225
 */
class DDC1225Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1225TestEntity1::class),
                    $this->_em->getClassMetadata(DDC1225TestEntity2::class),
                ]
            );
        } catch (PDOException $e) {
        }
    }

    public function testIssue(): void
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->from(DDC1225TestEntity1::class, 'te1')
           ->select('te1')
           ->where('te1.testEntity2 = ?1')
           ->setParameter(1, 0);

        $this->assertEquals(
            strtolower('SELECT t0_.test_entity2_id AS test_entity2_id_0 FROM te1 t0_ WHERE t0_.test_entity2_id = ?'),
            strtolower($qb->getQuery()->getSQL())
        );
    }
}

/**
 * @Entity
 * @Table(name="te1")
 */
class DDC1225TestEntity1
{
    /**
     * @var DDC1225TestEntity2
     * @Id
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Functional\Ticket\DDC1225TestEntity2")
     * @JoinColumn(name="test_entity2_id", referencedColumnName="id", nullable=false)
     */
    private $testEntity2;

    public function setTestEntity2(DDC1225TestEntity2 $testEntity2): void
    {
        $this->testEntity2 = $testEntity2;
    }

    public function getTestEntity2(): DDC1225TestEntity2
    {
        return $this->testEntity2;
    }
}

/**
 * @Entity
 * @Table(name="te2")
 */
class DDC1225TestEntity2
{
    /**
     * @var int
     * @Id
     * @GeneratedValue(strategy="AUTO")
     * @Column(type="integer")
     */
    private $id;
}
