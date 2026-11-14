<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH7869')]
class GH7869Test extends OrmTestCase
{
    public function testDQLDeferredEagerLoad(): void
    {
        $platform = $this->createStub(AbstractPlatform::class);
        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $connection = $this->createConnectionStub($platform);

        $em = new class (new EntityManagerMock($connection)) extends EntityManagerDecorator {
            /** @var int */
            public $getClassMetadataCalls = 0;

            public function getClassMetadata($className): ClassMetadata
            {
                ++$this->getClassMetadataCalls;

                return parent::getClassMetadata($className);
            }
        };

        $hints = [
            UnitOfWork::HINT_DEFEREAGERLOAD => true,
            'fetchMode' => [GH7869Appointment::class => ['patient' => ClassMetadata::FETCH_EAGER]],
        ];

        $uow = new UnitOfWork($em);
        $uow->createEntity(GH7869Appointment::class, ['id' => 1, 'patient_id' => 1], $hints);
        $uow->clear();
        $uow->triggerEagerLoads();

        self::assertSame(4, $em->getClassMetadataCalls);
    }
}

#[Entity]
class GH7869Appointment
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var GH7869Patient */
    #[OneToOne(targetEntity: 'GH7869Patient', inversedBy: 'appointment', fetch: 'EAGER')]
    public $patient;
}

#[Entity]
class GH7869Patient
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var GH7869Appointment */
    #[OneToOne(targetEntity: 'GH7869Appointment', mappedBy: 'patient')]
    public $appointment;
}
