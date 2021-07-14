<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function strtolower;

class DDC448Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC448MainTable::class),
                $this->_em->getClassMetadata(DDC448ConnectedClass::class),
                $this->_em->getClassMetadata(DDC448SubTable::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $q = $this->_em->createQuery('select b from ' . __NAMESPACE__ . '\\DDC448SubTable b where b.connectedClassId = ?1');
        $this->assertEquals(
            strtolower('SELECT d0_.id AS id_0, d0_.discr AS discr_1, d0_.connectedClassId AS connectedClassId_2 FROM SubTable s1_ INNER JOIN DDC448MainTable d0_ ON s1_.id = d0_.id WHERE d0_.connectedClassId = ?'),
            strtolower($q->getSQL())
        );
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="smallint")
 * @DiscriminatorMap({
 *     "0" = "DDC448MainTable",
 *     "1" = "DDC448SubTable"
 * })
 */
class DDC448MainTable
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var DDC448ConnectedClass
     * @ManyToOne(targetEntity="DDC448ConnectedClass",  cascade={"all"}, fetch="EAGER")
     * @JoinColumn(name="connectedClassId", referencedColumnName="id", onDelete="CASCADE", nullable=true)
     */
    private $connectedClassId;
}

/**
 * @Entity
 * @Table(name="connectedClass")
 * @HasLifecycleCallbacks
 */
class DDC448ConnectedClass
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $id; // connected with DDC448MainTable
}

/**
 * @Entity
 * @Table(name="SubTable")
 */
class DDC448SubTable extends DDC448MainTable
{
}
