<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC3303Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema([$this->_em->getClassMetadata(DDC3303Employee::class)]);
    }

    /**
     * @group GH-4097
     * @group GH-4277
     * @group GH-5867
     *
     * When using an embedded field in an inheritance, private properties should also be inherited.
     */
    public function testEmbeddedObjectsAreAlsoInherited(): void
    {
        $employee = new DDC3303Employee(
            'John Doe',
            new DDC3303Address('Somewhere', 123, 'Over the rainbow'),
            'Doctrine Inc'
        );

        $this->_em->persist($employee);
        $this->_em->flush();
        $this->_em->clear();

        self::assertEquals($employee, $this->_em->find(DDC3303Employee::class, 'John Doe'));
    }
}

/** @MappedSuperclass */
abstract class DDC3303Person
{
    /**
     * @var string
     * @Id
     * @GeneratedValue(strategy="NONE")
     * @Column(type="string")
     */
    private $name;

    /**
     * @var DDC3303Address
     * @Embedded(class="DDC3303Address")
     */
    private $address;

    public function __construct(string $name, DDC3303Address $address)
    {
        $this->name    = $name;
        $this->address = $address;
    }
}

/**
 * @Embeddable
 */
class DDC3303Address
{
    /**
     * @var string
     * @Column(type="string")
     */
    private $street;

    /**
     * @var int
     * @Column(type="integer")
     */
    private $number;

    /**
     * @var string
     * @Column(type="string")
     */
    private $city;

    public function __construct(string $street, int $number, string $city)
    {
        $this->street = $street;
        $this->number = $number;
        $this->city   = $city;
    }
}

/**
 * @Entity
 * @Table(name="ddc3303_employee")
 */
class DDC3303Employee extends DDC3303Person
{
    /**
     * @var string
     * @Column(type="string")
     */
    private $company;

    public function __construct(string $name, DDC3303Address $address, $company)
    {
        parent::__construct($name, $address);

        $this->company = $company;
    }
}
