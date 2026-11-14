<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\ORM\Internal\Hydration\HydrationException;
use Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\DbalTypes\GH8565EmployeePayloadType;
use Doctrine\Tests\DbalTypes\GH8565ManagerPayloadType;
use Doctrine\Tests\Mocks\ArrayResultFactory;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\Enums\Scale;
use Doctrine\Tests\Models\Enums\Unit;
use Doctrine\Tests\Models\GH8565\GH8565Employee;
use Doctrine\Tests\Models\GH8565\GH8565Manager;
use Doctrine\Tests\Models\GH8565\GH8565Person;
use Doctrine\Tests\Models\Issue5989\Issue5989Employee;
use Doctrine\Tests\Models\Issue5989\Issue5989Manager;
use Doctrine\Tests\Models\Issue5989\Issue5989Person;
use PHPUnit\Framework\Attributes\Group;

class SimpleObjectHydratorTest extends HydrationTestCase
{
    #[Group('DDC-1470')]
    public function testMissingDiscriminatorColumnException(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('The discriminator column "discr" is missing for "Doctrine\Tests\Models\Company\CompanyPerson" using the DQL alias "p".');
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CompanyPerson::class, 'p');
        $rsm->addFieldResult('p', 'p__id', 'id');
        $rsm->addFieldResult('p', 'p__name', 'name');
        $rsm->addMetaResult('p ', 'discr', 'discr', false, 'string');
        $rsm->setDiscriminatorColumn('p', 'discr');
        $resultSet = [
            [
                'u__id'   => '1',
                'u__name' => 'Fabio B. Silva',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new SimpleObjectHydrator($this->entityManager);
        $hydrator->hydrateAll($stmt, $rsm);
    }

    public function testExtraFieldInResultSetShouldBeIgnore(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsAddress::class, 'a');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__city', 'city');
        $resultSet = [
            [
                'a__id'   => '1',
                'a__city' => 'Cracow',
                'doctrine_rownum' => '1',
            ],
        ];

        $expectedEntity       = new CmsAddress();
        $expectedEntity->id   = 1;
        $expectedEntity->city = 'Cracow';

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new SimpleObjectHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);
        self::assertEquals($result[0], $expectedEntity);
    }

    #[Group('DDC-3076')]
    public function testInvalidDiscriminatorValueException(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('The discriminator value "subworker" is invalid. It must be one of "person", "manager", "employee".');
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CompanyPerson::class, 'p');

        $rsm->addFieldResult('p', 'p__id', 'id');
        $rsm->addFieldResult('p', 'p__name', 'name');
        $rsm->addMetaResult('p', 'discr', 'discr', false, 'string');
        $rsm->setDiscriminatorColumn('p', 'discr');

        $resultSet = [
            [
                'p__id'   => '1',
                'p__name' => 'Fabio B. Silva',
                'discr'   => 'subworker',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new SimpleObjectHydrator($this->entityManager);
        $hydrator->hydrateAll($stmt, $rsm);
    }

    #[Group('issue-5989')]
    public function testNullValueShouldNotOverwriteFieldWithSameNameInJoinedInheritance(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(Issue5989Person::class, 'p');
        $rsm->addFieldResult('p', 'p__id', 'id');
        $rsm->addFieldResult('p', 'm__tags', 'tags', Issue5989Manager::class);
        $rsm->addFieldResult('p', 'e__tags', 'tags', Issue5989Employee::class);
        $rsm->addMetaResult('p', 'discr', 'discr', false, 'string');
        $resultSet = [
            [
                'p__id'   => '1',
                'm__tags' => 'tag1,tag2',
                'e__tags' => null,
                'discr'   => 'manager',
            ],
        ];

        $expectedEntity       = new Issue5989Manager();
        $expectedEntity->id   = 1;
        $expectedEntity->tags = ['tag1', 'tag2'];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new SimpleObjectHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);
        self::assertEquals($result[0], $expectedEntity);
    }

    public function testWrongValuesShouldNotBeConvertedToPhpValue(): void
    {
        self::registerDbalType(GH8565EmployeePayloadType::NAME, GH8565EmployeePayloadType::class);
        self::registerDbalType(GH8565ManagerPayloadType::NAME, GH8565ManagerPayloadType::class);

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(GH8565Person::class, 'p');
        $rsm->addFieldResult('p', 'p__id', 'id');
        $rsm->addFieldResult('p', 'm__type', 'type', GH8565Manager::class);
        $rsm->addFieldResult('p', 'e__type', 'type', GH8565Employee::class);
        $rsm->addMetaResult('p', 'discr', 'discr', false, 'string');
        $rsm->setDiscriminatorColumn('p', 'type');
        $resultSet = [
            [
                'p__id'   => '1',
                'm__type' => 'type field',
                'e__type' => 'type field',
                'e__tags' => null,
                'discr'   => 'manager',
            ],
        ];

        $expectedEntity       = new GH8565Manager();
        $expectedEntity->id   = 1;
        $expectedEntity->type = 'type field';

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new SimpleObjectHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);
        self::assertEquals($result[0], $expectedEntity);
    }

    public function testNotListedValueInEnumArray(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Case "unknown_case" is not listed in enum "Doctrine\Tests\Models\Enums\Unit"');
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(Scale::class, 's');
        $rsm->addFieldResult('s', 's__id', 'id');
        $rsm->addFieldResult('s', 's__supported_units', 'supportedUnits');
        $rsm->addEnumResult('s__supported_units', Unit::class);
        $resultSet = [
            [
                's__id' => 1,
                's__supported_units' => 'g,m,unknown_case',
            ],
        ];

        $stmt     = ArrayResultFactory::createWrapperResultFromArray($resultSet);
        $hydrator = new SimpleObjectHydrator($this->entityManager);
        $hydrator->hydrateAll($stmt, $rsm);
    }
}
