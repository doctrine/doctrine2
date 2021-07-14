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

class Ticket4646InstanceOfParametricTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(PersonTicket4646Parametric::class),
            $this->_em->getClassMetadata(EmployeeTicket4646Parametric::class),
        ]);
    }

    public function testInstanceOf(): void
    {
        $this->_em->persist(new PersonTicket4646Parametric());
        $this->_em->persist(new EmployeeTicket4646Parametric());
        $this->_em->flush();
        $dql   = 'SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646Parametric p
                WHERE p INSTANCE OF :parameter';
        $query = $this->_em->createQuery($dql);
        $query->setParameter(
            'parameter',
            $this->_em->getClassMetadata(PersonTicket4646Parametric::class)
        );
        $result = $query->getResult();
        self::assertCount(2, $result);
        self::assertContainsOnlyInstancesOf(PersonTicket4646Parametric::class, $result);
    }
}

/**
 * @Entity()
 * @Table(name="instance_of_parametric_person")
 * @InheritanceType(value="JOINED")
 * @DiscriminatorColumn(name="kind", type="string")
 * @DiscriminatorMap(value={
 *     "person": "Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646Parametric",
 *     "employee": "Doctrine\Tests\ORM\Functional\Ticket\EmployeeTicket4646Parametric"
 * })
 */
class PersonTicket4646Parametric
{
    /**
     * @var int
     * @Id()
     * @GeneratedValue()
     * @Column(type="integer")
     */
    private $id;

    public function getId(): ?int
    {
        return $this->id;
    }
}

/**
 * @Entity()
 * @Table(name="instance_of_parametric_employee")
 */
class EmployeeTicket4646Parametric extends PersonTicket4646Parametric
{
}
