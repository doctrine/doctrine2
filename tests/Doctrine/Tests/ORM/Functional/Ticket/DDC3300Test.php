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
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-3300
 */
class DDC3300Test extends OrmFunctionalTestCase
{
    public function testResolveTargetEntitiesChangesDiscriminatorMapValues(): void
    {
        $resolveTargetEntity = new ResolveTargetEntityListener();

        $resolveTargetEntity->addResolveTargetEntity(
            DDC3300Boss::class,
            DDC3300HumanBoss::class,
            []
        );

        $resolveTargetEntity->addResolveTargetEntity(
            DDC3300Employee::class,
            DDC3300HumanEmployee::class,
            []
        );

        $this->_em->getEventManager()->addEventSubscriber($resolveTargetEntity);

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC3300Person::class),
            ]
        );

        $boss     = new DDC3300HumanBoss('boss');
        $employee = new DDC3300HumanEmployee('employee');

        $this->_em->persist($boss);
        $this->_em->persist($employee);

        $this->_em->flush();
        $this->_em->clear();

        $this->assertEquals($boss, $this->_em->find(DDC3300Boss::class, $boss->id));
        $this->assertEquals($employee, $this->_em->find(DDC3300Employee::class, $employee->id));
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *      "boss"     = "Doctrine\Tests\ORM\Functional\Ticket\DDC3300Boss",
 *      "employee" = "Doctrine\Tests\ORM\Functional\Ticket\DDC3300Employee"
 * })
 */
abstract class DDC3300Person
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}

interface DDC3300Boss
{
}

/** @Entity */
class DDC3300HumanBoss extends DDC3300Person implements DDC3300Boss
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $bossCol;

    public function __construct($bossCol)
    {
        $this->bossCol = $bossCol;
    }
}

interface DDC3300Employee
{
}

/** @Entity */
class DDC3300HumanEmployee extends DDC3300Person implements DDC3300Employee
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $employeeCol;

    public function __construct($employeeCol)
    {
        $this->employeeCol = $employeeCol;
    }
}
