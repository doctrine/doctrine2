<?php

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\DBAL\Result;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Mocks\HydratorMockStatement;
use Doctrine\Tests\Mocks\ResultMock;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\Company\CompanyPerson;
use Doctrine\Tests\Models\Issue5989\Issue5989Employee;
use Doctrine\Tests\Models\Issue5989\Issue5989Manager;
use Doctrine\Tests\Models\Issue5989\Issue5989Person;

use function class_exists;

class SimpleObjectHydratorTest extends HydrationTestCase
{
    /**
     * @group DDC-1470
     */
    public function testMissingDiscriminatorColumnException()
    {
        $this->expectException('Doctrine\ORM\Internal\Hydration\HydrationException');
        $this->expectExceptionMessage('The discriminator column "discr" is missing for "Doctrine\Tests\Models\Company\CompanyPerson" using the DQL alias "p".');
        $rsm = new ResultSetMapping;
        $rsm->addEntityResult(CompanyPerson::class, 'p');
        $rsm->addFieldResult('p', 'p__id', 'id');
        $rsm->addFieldResult('p', 'p__name', 'name');
        $rsm->addMetaResult('p ', 'discr', 'discr', false, 'string');
        $rsm->setDiscriminatorColumn('p', 'discr');
        $resultSet = [
              [
                  'u__id'   => '1',
                  'u__name' => 'Fabio B. Silva'
              ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator   = new \Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator($this->_em);
        $hydrator->hydrateAll($stmt, $rsm);
    }

    public function testExtraFieldInResultSetShouldBeIgnore()
    {
        $rsm = new ResultSetMapping;
        $rsm->addEntityResult(CmsAddress::class, 'a');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__city', 'city');
        $resultSet = [
            [
                'a__id'   => '1',
                'a__city' => 'Cracow',
                'doctrine_rownum' => '1'
            ],
        ];

        $expectedEntity = new \Doctrine\Tests\Models\CMS\CmsAddress();
        $expectedEntity->id = 1;
        $expectedEntity->city = 'Cracow';

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator   = new \Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator($this->_em);
        $result = $hydrator->hydrateAll($stmt, $rsm);
        $this->assertEquals($result[0], $expectedEntity);
    }

    /**
     * @group DDC-3076
     */
    public function testInvalidDiscriminatorValueException()
    {
        $this->expectException('Doctrine\ORM\Internal\Hydration\HydrationException');
        $this->expectExceptionMessage('The discriminator value "subworker" is invalid. It must be one of "person", "manager", "employee".');
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CompanyPerson::class, 'p');

        $rsm->addFieldResult('p', 'p__id', 'id');
        $rsm->addFieldResult('p', 'p__name', 'name');
        $rsm->addMetaResult('p', 'discr', 'discr', false, 'string');
        $rsm->setDiscriminatorColumn('p', 'discr');

        $resultSet = [
              [
                  'p__id'   => '1',
                  'p__name' => 'Fabio B. Silva',
                  'discr'   => 'subworker'
              ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator   = new \Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator($this->_em);
        $hydrator->hydrateAll($stmt, $rsm);
    }

    /**
     * @group issue-5989
     */
    public function testNullValueShouldNotOverwriteFieldWithSameNameInJoinedInheritance()
    {
        $rsm = new ResultSetMapping;
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
                'discr'   => 'manager'
            ],
        ];

        $expectedEntity = new Issue5989Manager();
        $expectedEntity->id = 1;
        $expectedEntity->tags = ['tag1', 'tag2'];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator   = new \Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator($this->_em);
        $result = $hydrator->hydrateAll($stmt, $rsm);
        $this->assertEquals($result[0], $expectedEntity);
    }
}
