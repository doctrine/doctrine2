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
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

class Ticket4646MultipleInstanceOfWithMultipleParametersTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(PersonTicket4646Multiple::class),
            $this->_em->getClassMetadata(EmployeeTicket4646Multiple::class),
            $this->_em->getClassMetadata(ManagerTicket4646Multiple::class),
            $this->_em->getClassMetadata(InternTicket4646Multiple::class),
        ]);
    }

    public function testInstanceOf(): void
    {
        $this->_em->persist(new PersonTicket4646Multiple());
        $this->_em->persist(new EmployeeTicket4646Multiple());
        $this->_em->persist(new ManagerTicket4646Multiple());
        $this->_em->persist(new InternTicket4646Multiple());
        $this->_em->flush();

        $dql    = 'SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646Multiple p
                WHERE p INSTANCE OF (Doctrine\Tests\ORM\Functional\Ticket\EmployeeTicket4646Multiple, Doctrine\Tests\ORM\Functional\Ticket\InternTicket4646Multiple)';
        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(2, $result);
        self::assertContainsOnlyInstancesOf(PersonTicket4646Multiple::class, $result);
    }
}

/**
 * @Entity()
 * @Table(name="instance_of_test_multiple_person")
 * @InheritanceType(value="JOINED")
 * @DiscriminatorColumn(name="kind", type="string")
 * @DiscriminatorMap(value={
 *     "person": "Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646Multiple",
 *     "employee": "Doctrine\Tests\ORM\Functional\Ticket\EmployeeTicket4646Multiple",
 *     "manager": "Doctrine\Tests\ORM\Functional\Ticket\ManagerTicket4646Multiple",
 *     "intern": "Doctrine\Tests\ORM\Functional\Ticket\InternTicket4646Multiple"
 * })
 */
class PersonTicket4646Multiple
{
    /**
     * @var int
     * @Id()
     * @GeneratedValue()
     * @Column(type="integer")
     */
    private $id;

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity()
 * @Table(name="instance_of_test_multiple_employee")
 */
class EmployeeTicket4646Multiple extends PersonTicket4646Multiple
{
}

/**
 * @Entity()
 * @Table(name="instance_of_test_multiple_manager")
 */
class ManagerTicket4646Multiple extends PersonTicket4646Multiple
{
}

/**
 * @Entity()
 * @Table(name="instance_of_test_multiple_intern")
 */
class InternTicket4646Multiple extends PersonTicket4646Multiple
{
}
