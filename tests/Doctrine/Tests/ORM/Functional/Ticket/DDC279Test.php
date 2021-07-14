<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

class DDC279Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC279EntityXAbstract::class),
                $this->_em->getClassMetadata(DDC279EntityX::class),
                $this->_em->getClassMetadata(DDC279EntityY::class),
                $this->_em->getClassMetadata(DDC279EntityZ::class),
            ]
        );
    }

    /**
     * @group DDC-279
     */
    public function testDDC279(): void
    {
        $x = new DDC279EntityX();
        $y = new DDC279EntityY();
        $z = new DDC279EntityZ();

        $x->data = 'X';
        $y->data = 'Y';
        $z->data = 'Z';

        $x->y = $y;
        $y->z = $z;

        $this->_em->persist($x);
        $this->_em->persist($y);
        $this->_em->persist($z);

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery(
            'SELECT x, y, z FROM Doctrine\Tests\ORM\Functional\Ticket\DDC279EntityX x ' .
            'INNER JOIN x.y y INNER JOIN y.z z WHERE x.id = ?1'
        )->setParameter(1, $x->id);

        $result = $query->getResult();

        $expected1 = 'Y';
        $expected2 = 'Z';

        $this->assertEquals(1, count($result));

        $this->assertEquals($expected1, $result[0]->y->data);
        $this->assertEquals($expected2, $result[0]->y->z->data);
    }
}


/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"DDC279EntityX" = "DDC279EntityX"})
 */
abstract class DDC279EntityXAbstract
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(name="id", type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $data;
}

/**
 * @Entity
 */
class DDC279EntityX extends DDC279EntityXAbstract
{
    /**
     * @var DDC279EntityY
     * @OneToOne(targetEntity="DDC279EntityY")
     * @JoinColumn(name="y_id", referencedColumnName="id")
     */
    public $y;
}

/**
 * @Entity
 */
class DDC279EntityY
{
    /**
     * @var int
     * @Id @GeneratedValue
     * @Column(name="id", type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $data;

    /**
     * @var DDC279EntityZ
     * @OneToOne(targetEntity="DDC279EntityZ")
     * @JoinColumn(name="z_id", referencedColumnName="id")
     */
    public $z;
}

/**
 * @Entity
 */
class DDC279EntityZ
{
    /**
     * @var int
     * @Id @GeneratedValue
     * @Column(name="id", type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $data;
}
