<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1514
 */
class DDC1514Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1514EntityA::class),
                    $this->_em->getClassMetadata(DDC1514EntityB::class),
                    $this->_em->getClassMetadata(DDC1514EntityC::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testIssue(): void
    {
        $a1        = new DDC1514EntityA();
        $a1->title = '1foo';

        $a2        = new DDC1514EntityA();
        $a2->title = '2bar';

        $b1              = new DDC1514EntityB();
        $b1->entityAFrom = $a1;
        $b1->entityATo   = $a2;

        $b2              = new DDC1514EntityB();
        $b2->entityAFrom = $a2;
        $b2->entityATo   = $a1;

        $c           = new DDC1514EntityC();
        $c->title    = 'baz';
        $a2->entityC = $c;

        $this->_em->persist($a1);
        $this->_em->persist($a2);
        $this->_em->persist($b1);
        $this->_em->persist($b2);
        $this->_em->persist($c);
        $this->_em->flush();
        $this->_em->clear();

        $dql     = 'SELECT a, b, ba, c FROM ' . __NAMESPACE__ . '\DDC1514EntityA AS a LEFT JOIN a.entitiesB AS b LEFT JOIN b.entityATo AS ba LEFT JOIN a.entityC AS c ORDER BY a.title';
        $results = $this->_em->createQuery($dql)->getResult();

        $this->assertEquals($a1->id, $results[0]->id);
        $this->assertNull($results[0]->entityC);

        $this->assertEquals($a2->id, $results[1]->id);
        $this->assertEquals($c->title, $results[1]->entityC->title);
    }
}

/**
 * @Entity
 */
class DDC1514EntityA
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column
     */
    public $title;

    /**
     * @psalm-var Collection<int, DDC1514EntityB>
     * @ManyToMany(targetEntity="DDC1514EntityB", mappedBy="entityAFrom")
     */
    public $entitiesB;

    /**
     * @var DDC1514EntityC
     * @ManyToOne(targetEntity="DDC1514EntityC")
     */
    public $entityC;

    public function __construct()
    {
        $this->entitiesB = new ArrayCollection();
    }
}

/**
 * @Entity
 */
class DDC1514EntityB
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC1514EntityA
     * @ManyToOne(targetEntity="DDC1514EntityA", inversedBy="entitiesB")
     */
    public $entityAFrom;
    /**
     * @var DDC1514EntityA
     * @ManyToOne(targetEntity="DDC1514EntityA")
     */
    public $entityATo;
}

/**
 * @Entity
 */
class DDC1514EntityC
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column
     */
    public $title;
}
