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

class DDC493Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC493Customer::class),
                $this->_em->getClassMetadata(DDC493Distributor::class),
                $this->_em->getClassMetadata(DDC493Contact::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $q = $this->_em->createQuery('select u, c.data from ' . __NAMESPACE__ . '\\DDC493Distributor u JOIN u.contact c');
        $this->assertEquals(
            strtolower('SELECT d0_.id AS id_0, d1_.data AS data_1, d0_.discr AS discr_2, d0_.contact AS contact_3 FROM DDC493Distributor d2_ INNER JOIN DDC493Customer d0_ ON d2_.id = d0_.id INNER JOIN DDC493Contact d1_ ON d0_.contact = d1_.id'),
            strtolower($q->getSQL())
        );
    }
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"distributor" = "DDC493Distributor", "customer" = "DDC493Customer"})
 */
class DDC493Customer
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
    /**
     * @var DDC493Contact
     * @OneToOne(targetEntity="DDC493Contact", cascade={"remove","persist"})
     * @JoinColumn(name="contact", referencedColumnName="id")
     */
    public $contact;
}

/**
 * @Entity
 */
class DDC493Distributor extends DDC493Customer
{
}

/**
 * @Entity
 */
class DDC493Contact
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
