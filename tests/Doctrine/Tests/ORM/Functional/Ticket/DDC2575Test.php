<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-2575
 */
class DDC2575Test extends OrmFunctionalTestCase
{
    /** @psalm-var list<DDC2575Root> */
    private $rootsEntities = [];

    /** @psalm-var list<DDC2575A> */
    private $aEntities = [];

    /** @psalm-var list<DDC2575B> */
    private $bEntities = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC2575Root::class),
                $this->_em->getClassMetadata(DDC2575A::class),
                $this->_em->getClassMetadata(DDC2575B::class),
            ]
        );

        $entityRoot1 = new DDC2575Root(1);
        $entityB1    = new DDC2575B(2);
        $entityA1    = new DDC2575A($entityRoot1, $entityB1);

        $this->_em->persist($entityRoot1);
        $this->_em->persist($entityA1);
        $this->_em->persist($entityB1);

        $entityRoot2 = new DDC2575Root(3);
        $entityB2    = new DDC2575B(4);
        $entityA2    = new DDC2575A($entityRoot2, $entityB2);

        $this->_em->persist($entityRoot2);
        $this->_em->persist($entityA2);
        $this->_em->persist($entityB2);

        $this->_em->flush();

        $this->rootsEntities[] = $entityRoot1;
        $this->rootsEntities[] = $entityRoot2;

        $this->aEntities[] = $entityA1;
        $this->aEntities[] = $entityA2;

        $this->bEntities[] = $entityB1;
        $this->bEntities[] = $entityB2;

        $this->_em->clear();
    }

    public function testHydrationIssue(): void
    {
        $repository = $this->_em->getRepository(DDC2575Root::class);
        $qb         = $repository->createQueryBuilder('r')
            ->select('r, a, b')
            ->leftJoin('r.aRelation', 'a')
            ->leftJoin('a.bRelation', 'b');

        $query  = $qb->getQuery();
        $result = $query->getResult();

        $this->assertCount(2, $result);

        $row = $result[0];
        $this->assertNotNull($row->aRelation);
        $this->assertEquals(1, $row->id);
        $this->assertNotNull($row->aRelation->rootRelation);
        $this->assertSame($row, $row->aRelation->rootRelation);
        $this->assertNotNull($row->aRelation->bRelation);
        $this->assertEquals(2, $row->aRelation->bRelation->id);

        $row = $result[1];
        $this->assertNotNull($row->aRelation);
        $this->assertEquals(3, $row->id);
        $this->assertNotNull($row->aRelation->rootRelation);
        $this->assertSame($row, $row->aRelation->rootRelation);
        $this->assertNotNull($row->aRelation->bRelation);
        $this->assertEquals(4, $row->aRelation->bRelation->id);
    }
}

/**
 * @Entity
 */
class DDC2575Root
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $sampleField;

    /**
     * @var DDC2575A
     * @OneToOne(targetEntity="DDC2575A", mappedBy="rootRelation")
     */
    public $aRelation;

    public function __construct(int $id, int $value = 0)
    {
        $this->id          = $id;
        $this->sampleField = $value;
    }
}

/**
 * @Entity
 */
class DDC2575A
{
    /**
     * @var DDC2575Root
     * @Id
     * @OneToOne(targetEntity="DDC2575Root", inversedBy="aRelation")
     * @JoinColumn(name="root_id", referencedColumnName="id", nullable=FALSE, onDelete="CASCADE")
     */
    public $rootRelation;

    /**
     * @var DDC2575B
     * @ManyToOne(targetEntity="DDC2575B")
     * @JoinColumn(name="b_id", referencedColumnName="id", nullable=FALSE, onDelete="CASCADE")
     */
    public $bRelation;

    public function __construct(DDC2575Root $rootRelation, DDC2575B $bRelation)
    {
        $this->rootRelation = $rootRelation;
        $this->bRelation    = $bRelation;
    }
}

/**
 * @Entity
 */
class DDC2575B
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $sampleField;

    public function __construct(int $id, int $value = 0)
    {
        $this->id          = $id;
        $this->sampleField = $value;
    }
}
