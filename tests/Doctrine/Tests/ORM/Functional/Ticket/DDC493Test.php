<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Annotation as ORM;

class DDC493Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->schemaTool->createSchema(
            [
            $this->em->getClassMetadata(DDC493Customer::class),
            $this->em->getClassMetadata(DDC493Distributor::class),
            $this->em->getClassMetadata(DDC493Contact::class)
            ]
        );
    }

    public function testIssue()
    {
        $q = $this->em->createQuery("select u, c.data from ".__NAMESPACE__."\\DDC493Distributor u JOIN u.contact c");

        self::assertSQLEquals(
            'SELECT t0."id" AS c0, t1."data" AS c1, t0."discr" AS c2, t0."contact" AS c3 FROM "DDC493Distributor" t2 INNER JOIN "DDC493Customer" t0 ON t2."id" = t0."id" INNER JOIN "DDC493Contact" t1 ON t0."contact" = t1."id"',
            $q->getSQL()
        );
    }
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorColumn(name="discr", type="string")
 * @ORM\DiscriminatorMap({"distributor" = "DDC493Distributor", "customer" = "DDC493Customer"})
 */
class DDC493Customer {
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    public $id;
    /**
     * @ORM\OneToOne(targetEntity="DDC493Contact", cascade={"remove","persist"})
     * @ORM\JoinColumn(name="contact", referencedColumnName="id")
     */
    public $contact;

}

/**
 * @ORM\Entity
 */
class DDC493Distributor extends DDC493Customer {
}

/**
 * @ORM\Entity
  */
class DDC493Contact
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    public $id;
    /** @ORM\Column(type="string") */
    public $data;
}
