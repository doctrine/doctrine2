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

use function strtolower;

class DDC513Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC513OfferItem::class),
                $this->_em->getClassMetadata(DDC513Item::class),
                $this->_em->getClassMetadata(DDC513Price::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $q = $this->_em->createQuery('select u from ' . __NAMESPACE__ . '\\DDC513OfferItem u left join u.price p');
        $this->assertEquals(
            strtolower('SELECT d0_.id AS id_0, d0_.discr AS discr_1, d0_.price AS price_2 FROM DDC513OfferItem d1_ INNER JOIN DDC513Item d0_ ON d1_.id = d0_.id LEFT JOIN DDC513Price d2_ ON d0_.price = d2_.id'),
            strtolower($q->getSQL())
        );
    }
}

/**
 * @Entity
 */
class DDC513OfferItem extends DDC513Item
{
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"item" = "DDC513Item", "offerItem" = "DDC513OfferItem"})
 */
class DDC513Item
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var DDC513Price
     * @OneToOne(targetEntity="DDC513Price", cascade={"remove","persist"})
     * @JoinColumn(name="price", referencedColumnName="id")
     */
    public $price;
}

/**
 * @Entity
 */
class DDC513Price
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $data;
}
