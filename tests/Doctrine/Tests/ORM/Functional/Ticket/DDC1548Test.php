<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1548
 */
class DDC1548Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1548E1::class),
                $this->_em->getClassMetadata(DDC1548E2::class),
                $this->_em->getClassMetadata(DDC1548Rel::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $rel = new DDC1548Rel();
        $this->_em->persist($rel);
        $this->_em->flush();

        $e1      = new DDC1548E1();
        $e1->rel = $rel;
        $this->_em->persist($e1);
        $this->_em->flush();
        $this->_em->clear();

        $obt = $this->_em->find(DDC1548Rel::class, $rel->id);

        $this->assertNull($obt->e2);
    }
}

/**
 * @Entity
 */
class DDC1548E1
{
    /**
     * @var DDC1548Rel
     * @Id
     * @OneToOne(targetEntity="DDC1548Rel", inversedBy="e1")
     */
    public $rel;
}

/**
 * @Entity
 */
class DDC1548E2
{
    /**
     * @var DDC1548Rel
     * @Id
     * @OneToOne(targetEntity="DDC1548Rel", inversedBy="e2")
     */
    public $rel;
}

/**
 * @Entity
 */
class DDC1548Rel
{
    /**
     * @var int
     * @Id @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC1548E1
     * @OneToOne(targetEntity="DDC1548E1", mappedBy="rel")
     */
    public $e1;
    /**
     * @var DDC1548E2
     * @OneToOne(targetEntity="DDC1548E2", mappedBy="rel")
     */
    public $e2;
}
