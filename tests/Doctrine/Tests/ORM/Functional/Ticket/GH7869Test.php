<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\Mocks\ConnectionMock;
use Doctrine\Tests\Mocks\DriverMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\OrmTestCase;

/**
 * @group GH7869
 */
class GH7869Test extends OrmTestCase
{
    public function testDQLDeferredEagerLoad(): void
    {
        $decoratedEm = EntityManagerMock::create(new ConnectionMock([], new DriverMock()));

        $em = $this->getMockBuilder(EntityManagerDecorator::class)
            ->setConstructorArgs([$decoratedEm])
            ->setMethods(['getClassMetadata'])
            ->getMock();

        $em->expects($this->exactly(2))
            ->method('getClassMetadata')
            ->willReturnCallback([$decoratedEm, 'getClassMetadata']);

        $hints = [
            UnitOfWork::HINT_DEFEREAGERLOAD => true,
            'fetchMode' => [GH7869Appointment::class => ['patient' => ClassMetadata::FETCH_EAGER]],
        ];

        $uow = new UnitOfWork($em);
        $uow->createEntity(GH7869Appointment::class, ['id' => 1, 'patient_id' => 1], $hints);
        $uow->clear();
        $uow->triggerEagerLoads();
    }
}

/**
 * @Entity
 */
class GH7869Appointment
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var GH7869Patient
     * @OneToOne(targetEntity="GH7869Patient", inversedBy="appointment", fetch="EAGER")
     */
    public $patient;
}

/**
 * @Entity
 */
class GH7869Patient
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var GH7869Appointment
     * @OneToOne(targetEntity="GH7869Appointment", mappedBy="patient")
     */
    public $appointment;
}
