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

class Ticket4646InstanceOfMultiLevelTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(PersonTicket4646MultiLevel::class),
            $this->_em->getClassMetadata(EmployeeTicket4646MultiLevel::class),
            $this->_em->getClassMetadata(EngineerTicket4646MultiLevel::class),
        ]);
    }

    public function testInstanceOf(): void
    {
        $this->_em->persist(new PersonTicket4646MultiLevel());
        $this->_em->persist(new EmployeeTicket4646MultiLevel());
        $this->_em->persist(new EngineerTicket4646MultiLevel());
        $this->_em->flush();

        $dql    = 'SELECT p FROM Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646MultiLevel p
                WHERE p INSTANCE OF Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646MultiLevel';
        $query  = $this->_em->createQuery($dql);
        $result = $query->getResult();

        self::assertCount(3, $result);
        self::assertContainsOnlyInstancesOf(PersonTicket4646MultiLevel::class, $result);
    }
}

/**
 * @Entity()
 * @Table(name="instance_of_multi_level_test_person")
 * @InheritanceType(value="JOINED")
 * @DiscriminatorColumn(name="kind", type="string")
 * @DiscriminatorMap(value={
 *     "person": "Doctrine\Tests\ORM\Functional\Ticket\PersonTicket4646MultiLevel",
 *     "employee": "Doctrine\Tests\ORM\Functional\Ticket\EmployeeTicket4646MultiLevel",
 *     "engineer": "Doctrine\Tests\ORM\Functional\Ticket\EngineerTicket4646MultiLevel",
 * })
 */
class PersonTicket4646MultiLevel
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
 * @Table(name="instance_of_multi_level_employee")
 */
class EmployeeTicket4646MultiLevel extends PersonTicket4646MultiLevel
{
}

/**
 * @Entity()
 * @Table(name="instance_of_multi_level_engineer")
 */
class EngineerTicket4646MultiLevel extends EmployeeTicket4646MultiLevel
{
}
