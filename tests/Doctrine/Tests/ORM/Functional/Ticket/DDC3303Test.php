<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Annotation as ORM;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC3303Test extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        $this->schemaTool->createSchema([$this->em->getClassMetadata(DDC3303Employee::class)]);
    }

    /**
     * @group 4097
     * @group 4277
     * @group 5867
     * @group embedded
     *
     * When using an embedded field in an inheritance, private properties should also be inherited.
     */
    public function testEmbeddedObjectsAreAlsoInherited() : void
    {
        $employee = new DDC3303Employee(
            'John Doe',
            new DDC3303Address('Somewhere', 123, 'Over the rainbow'),
            'Doctrine Inc'
        );

        $this->em->persist($employee);
        $this->em->flush();
        $this->em->clear();

        self::assertEquals($employee, $this->em->find(DDC3303Employee::class, 'John Doe'));
    }
}

/** @ORM\MappedSuperclass */
abstract class DDC3303Person
{
    /** @ORM\Id @ORM\GeneratedValue(strategy="NONE") @ORM\Column(type="string") @var string */
    private $name;

    /** @ORM\Embedded(class=DDC3303Address::class) @var DDC3303Address */
    private $address;

    public function __construct($name, DDC3303Address $address)
    {
        $this->name    = $name;
        $this->address = $address;
    }
}

/**
 * @ORM\Embeddable
 */
class DDC3303Address
{
    /** @ORM\Column(type="string") @var string */
    private $street;

    /** @ORM\Column(type="integer") @var int */
    private $number;

    /** @ORM\Column(type="string") @var string */
    private $city;

    public function __construct($street, $number, $city)
    {
        $this->street = $street;
        $this->number = $number;
        $this->city   = $city;
    }
}

/**
 * @ORM\Entity
 * @ORM\Table(name="ddc3303_employee")
 */
class DDC3303Employee extends DDC3303Person
{
    /** @ORM\Column(type="string") @var string */
    private $company;

    public function __construct($name, DDC3303Address $address, $company)
    {
        parent::__construct($name, $address);

        $this->company = $company;
    }
}
