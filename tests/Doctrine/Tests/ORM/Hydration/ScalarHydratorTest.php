<?php

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\DBAL\Result;
use Doctrine\ORM\Internal\Hydration\ScalarHydrator;
use Doctrine\Tests\Mocks\HydratorMockStatement;
use Doctrine\Tests\Mocks\ResultMock;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Models\CMS\CmsUser;

use function class_exists;

class ScalarHydratorTest extends HydrationTestCase
{
    /**
     * Select u.id, u.name from CmsUser u
     */
    public function testNewHydrationSimpleEntityQuery()
    {
        $rsm = new ResultSetMapping;
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb'
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage'
            ]
        ];


        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new ScalarHydrator($this->_em);

        $result = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('romanb', $result[0]['u_name']);
        $this->assertEquals(1, $result[0]['u_id']);
        $this->assertEquals('jwage', $result[1]['u_name']);
        $this->assertEquals(2, $result[1]['u_id']);
    }

    /**
     * @group DDC-407
     */
    public function testHydrateScalarResults()
    {
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('foo1', 'foo', 'string');
        $rsm->addScalarResult('bar2', 'bar', 'string');
        $rsm->addScalarResult('baz3', 'baz', 'string');

        $resultSet = [
            [
                'foo1' => 'A',
                'bar2' => 'B',
                'baz3' => 'C',
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new ScalarHydrator($this->_em);

        self::assertCount(1, $hydrator->hydrateAll($stmt, $rsm));
    }

    /**
     * @group DDC-644
     */
    public function testSkipUnknownColumns()
    {
        $rsm = new ResultSetMapping;
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');
        $rsm->addScalarResult('foo1', 'foo', 'string');
        $rsm->addScalarResult('bar2', 'bar', 'string');
        $rsm->addScalarResult('baz3', 'baz', 'string');

        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
                'foo1' => 'A',
                'bar2' => 'B',
                'baz3' => 'C',
                'foo' => 'bar', // Unknown!
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new ScalarHydrator($this->_em);

        self::assertCount(1, $hydrator->hydrateAll($stmt, $rsm));
    }
}
