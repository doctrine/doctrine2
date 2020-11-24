<?php

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\DBAL\Result;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Mocks\HydratorMockStatement;
use Doctrine\Tests\Mocks\ResultMock;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Forum\ForumBoard;
use Doctrine\Tests\Models\Forum\ForumCategory;

use function class_exists;

class ArrayHydratorTest extends HydrationTestCase
{
    public function provideDataForUserEntityResult()
    {
        return [
            [0],
            ['user'],
            ['scalars'],
            ['newObjects'],
        ];
    }

    /**
     * SELECT PARTIAL u.{id, name}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testSimpleEntityQuery()
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
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));

        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals('romanb', $result[0]['name']);

        $this->assertEquals(2, $result[1]['id']);
        $this->assertEquals('jwage', $result[1]['name']);
    }

    /**
     * SELECT PARTIAL scalars.{id, name}, UPPER(scalars.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser scalars
     *
     * @dataProvider provideDataForUserEntityResult
     */
    public function testSimpleEntityWithScalarQuery($userEntityKey)
    {
        $alias = $userEntityKey ?: 'u';
        $rsm   = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, $alias);
        $rsm->addFieldResult($alias, 's__id', 'id');
        $rsm->addFieldResult($alias, 's__name', 'name');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');

        // Faked result set
        $resultSet = [
            [
                's__id' => '1',
                's__name' => 'romanb',
                'sclr0' => 'ROMANB',
            ],
            [
                's__id' => '2',
                's__name' => 'jwage',
                'sclr0' => 'JWAGE',
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));

        $this->assertArrayHasKey('nameUpper', $result[0]);
        $this->assertArrayNotHasKey('id', $result[0]);
        $this->assertArrayNotHasKey('name', $result[0]);

        $this->assertArrayHasKey(0, $result[0]);
        $this->assertArrayHasKey('id', $result[0][0]);
        $this->assertArrayHasKey('name', $result[0][0]);

        $this->assertArrayHasKey('nameUpper', $result[1]);
        $this->assertArrayNotHasKey('id', $result[1]);
        $this->assertArrayNotHasKey('name', $result[1]);

        $this->assertArrayHasKey(0, $result[1]);
        $this->assertArrayHasKey('id', $result[1][0]);
        $this->assertArrayHasKey('name', $result[1][0]);
    }

    /**
     * SELECT PARTIAL u.{id, name} AS user
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testSimpleEntityQueryWithAliasedUserEntity()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', 'user');
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
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));

        $this->assertArrayHasKey('user', $result[0]);
        $this->assertEquals(1, $result[0]['user']['id']);
        $this->assertEquals('romanb', $result[0]['user']['name']);

        $this->assertArrayHasKey('user', $result[1]);
        $this->assertEquals(2, $result[1]['user']['id']);
        $this->assertEquals('jwage', $result[1]['user']['name']);
    }

    /**
     * SELECT PARTIAL u.{id, name}, PARTIAL a.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQuery()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addEntityResult(CmsArticle::class, 'a');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
                'a__id' => '1',
                'a__topic' => 'Cool things.'
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.'
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(4, count($result));

        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals('romanb', $result[0]['name']);

        $this->assertEquals(1, $result[1]['id']);
        $this->assertEquals('Cool things.', $result[1]['topic']);

        $this->assertEquals(2, $result[2]['id']);
        $this->assertEquals('jwage', $result[2]['name']);

        $this->assertEquals(2, $result[3]['id']);
        $this->assertEquals('Cool things II.', $result[3]['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, name} AS user, PARTIAL a.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQueryWithAliasedUserEntity()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', 'user');
        $rsm->addEntityResult(CmsArticle::class, 'a');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
                'a__id' => '1',
                'a__topic' => 'Cool things.'
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.'
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(4, count($result));

        $this->assertArrayHasKey('user', $result[0]);
        $this->assertEquals(1, $result[0]['user']['id']);
        $this->assertEquals('romanb', $result[0]['user']['name']);

        $this->assertArrayHasKey(0, $result[1]);
        $this->assertEquals(1, $result[1][0]['id']);
        $this->assertEquals('Cool things.', $result[1][0]['topic']);

        $this->assertArrayHasKey('user', $result[2]);
        $this->assertEquals(2, $result[2]['user']['id']);
        $this->assertEquals('jwage', $result[2]['user']['name']);

        $this->assertArrayHasKey(0, $result[3]);
        $this->assertEquals(2, $result[3][0]['id']);
        $this->assertEquals('Cool things II.', $result[3][0]['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, name}, PARTIAL a.{id, topic} AS article
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQueryWithAliasedArticleEntity()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addEntityResult(CmsArticle::class, 'a', 'article');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
                'a__id' => '1',
                'a__topic' => 'Cool things.'
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.'
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(4, count($result));

        $this->assertArrayHasKey(0, $result[0]);
        $this->assertEquals(1, $result[0][0]['id']);
        $this->assertEquals('romanb', $result[0][0]['name']);

        $this->assertArrayHasKey('article', $result[1]);
        $this->assertEquals(1, $result[1]['article']['id']);
        $this->assertEquals('Cool things.', $result[1]['article']['topic']);

        $this->assertArrayHasKey(0, $result[2]);
        $this->assertEquals(2, $result[2][0]['id']);
        $this->assertEquals('jwage', $result[2][0]['name']);

        $this->assertArrayHasKey('article', $result[3]);
        $this->assertEquals(2, $result[3]['article']['id']);
        $this->assertEquals('Cool things II.', $result[3]['article']['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, name} AS user, PARTIAL a.{id, topic} AS article
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQueryWithAliasedEntities()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', 'user');
        $rsm->addEntityResult(CmsArticle::class, 'a', 'article');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
                'a__id' => '1',
                'a__topic' => 'Cool things.'
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.'
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(4, count($result));

        $this->assertArrayHasKey('user', $result[0]);
        $this->assertEquals(1, $result[0]['user']['id']);
        $this->assertEquals('romanb', $result[0]['user']['name']);

        $this->assertArrayHasKey('article', $result[1]);
        $this->assertEquals(1, $result[1]['article']['id']);
        $this->assertEquals('Cool things.', $result[1]['article']['topic']);

        $this->assertArrayHasKey('user', $result[2]);
        $this->assertEquals(2, $result[2]['user']['id']);
        $this->assertEquals('jwage', $result[2]['user']['name']);

        $this->assertArrayHasKey('article', $result[3]);
        $this->assertEquals(2, $result[3]['article']['id']);
        $this->assertEquals('Cool things II.', $result[3]['article']['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, status}, COUNT(p.phonenumber) AS numPhones
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.phonenumbers p
     *  GROUP BY u.status, u.id
     *
     * @dataProvider provideDataForUserEntityResult
     */
    public function testMixedQueryNormalJoin($userEntityKey)
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'numPhones', 'integer');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => '2',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => '1',
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));
        $this->assertTrue(is_array($result[0]));
        $this->assertTrue(is_array($result[1]));

        // first user => 2 phonenumbers
        $this->assertArrayHasKey($userEntityKey, $result[0]);
        $this->assertEquals(2, $result[0]['numPhones']);

        // second user => 1 phonenumber
        $this->assertArrayHasKey($userEntityKey, $result[1]);
        $this->assertEquals(1, $result[1]['numPhones']);
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL p.{phonenumber}, UPPER(u.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.phonenumbers p
     *
     * @dataProvider provideDataForUserEntityResult
     */
    public function testMixedQueryFetchJoin($userEntityKey)
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers'
        );
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');
        $rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91'
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));

        $this->assertTrue(is_array($result));
        $this->assertTrue(is_array($result[0]));
        $this->assertTrue(is_array($result[1]));

        // first user => 2 phonenumbers
        $this->assertEquals(2, count($result[0][$userEntityKey]['phonenumbers']));
        $this->assertEquals('ROMANB', $result[0]['nameUpper']);

        // second user => 1 phonenumber
        $this->assertEquals(1, count($result[1][$userEntityKey]['phonenumbers']));
        $this->assertEquals('JWAGE', $result[1]['nameUpper']);

        $this->assertEquals(42, $result[0][$userEntityKey]['phonenumbers'][0]['phonenumber']);
        $this->assertEquals(43, $result[0][$userEntityKey]['phonenumbers'][1]['phonenumber']);
        $this->assertEquals(91, $result[1][$userEntityKey]['phonenumbers'][0]['phonenumber']);
    }

    /**
     * SELECT PARTIAL u.{id, status}, UPPER(u.name) nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *        INDEX BY u.id
     *   JOIN u.phonenumbers p
     *        INDEX BY p.phonenumber
     *
     * @dataProvider provideDataForUserEntityResult
     */
    public function testMixedQueryFetchJoinCustomIndex($userEntityKey)
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers'
        );
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');
        $rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');
        $rsm->addIndexBy('u', 'id');
        $rsm->addIndexBy('p', 'phonenumber');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91'
            ]
        ];


        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));

        $this->assertTrue(is_array($result));
        $this->assertTrue(is_array($result[1]));
        $this->assertTrue(is_array($result[2]));

        // test the scalar values
        $this->assertEquals('ROMANB', $result[1]['nameUpper']);
        $this->assertEquals('JWAGE', $result[2]['nameUpper']);

        // first user => 2 phonenumbers. notice the custom indexing by user id
        $this->assertEquals(2, count($result[1][$userEntityKey]['phonenumbers']));

        // second user => 1 phonenumber. notice the custom indexing by user id
        $this->assertEquals(1, count($result[2][$userEntityKey]['phonenumbers']));

        // test the custom indexing of the phonenumbers
        $this->assertTrue(isset($result[1][$userEntityKey]['phonenumbers']['42']));
        $this->assertTrue(isset($result[1][$userEntityKey]['phonenumbers']['43']));
        $this->assertTrue(isset($result[2][$userEntityKey]['phonenumbers']['91']));
    }

    /**
     * select u.id, u.status, p.phonenumber, upper(u.name) nameUpper, a.id, a.topic
     * from User u
     * join u.phonenumbers p
     * join u.articles a
     * =
     * select u.id, u.status, p.phonenumber, upper(u.name) AS u___0, a.id, a.topic
     * from USERS u
     * inner join PHONENUMBERS p ON u.id = p.user_id
     * inner join ARTICLES a ON u.id = a.user_id
     */
    public function testMixedQueryMultipleFetchJoin()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers'
        );
        $rsm->addJoinedEntityResult(
            CmsArticle::class,
            'a',
            'u',
            'articles'
        );
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');
        $rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id' => '1',
                'a__topic' => 'Getting things done!'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '1',
                'a__topic' => 'Getting things done!'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id' => '2',
                'a__topic' => 'ZendCon'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '2',
                'a__topic' => 'ZendCon'
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '3',
                'a__topic' => 'LINQ'
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '4',
                'a__topic' => 'PHP7'
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));
        $this->assertTrue(is_array($result[0]));
        $this->assertTrue(is_array($result[1]));
        // first user => 2 phonenumbers, 2 articles
        $this->assertEquals(2, count($result[0][0]['phonenumbers']));
        $this->assertEquals(2, count($result[0][0]['articles']));
        $this->assertEquals('ROMANB', $result[0]['nameUpper']);
        // second user => 1 phonenumber, 2 articles
        $this->assertEquals(1, count($result[1][0]['phonenumbers']));
        $this->assertEquals(2, count($result[1][0]['articles']));
        $this->assertEquals('JWAGE', $result[1]['nameUpper']);

        $this->assertEquals(42, $result[0][0]['phonenumbers'][0]['phonenumber']);
        $this->assertEquals(43, $result[0][0]['phonenumbers'][1]['phonenumber']);
        $this->assertEquals(91, $result[1][0]['phonenumbers'][0]['phonenumber']);

        $this->assertEquals('Getting things done!', $result[0][0]['articles'][0]['topic']);
        $this->assertEquals('ZendCon', $result[0][0]['articles'][1]['topic']);
        $this->assertEquals('LINQ', $result[1][0]['articles'][0]['topic']);
        $this->assertEquals('PHP7', $result[1][0]['articles'][1]['topic']);
    }

    /**
     * select u.id, u.status, p.phonenumber, upper(u.name) nameUpper, a.id, a.topic,
     * c.id, c.topic
     * from User u
     * join u.phonenumbers p
     * join u.articles a
     * left join a.comments c
     * =
     * select u.id, u.status, p.phonenumber, upper(u.name) AS u___0, a.id, a.topic,
     * c.id, c.topic
     * from USERS u
     * inner join PHONENUMBERS p ON u.id = p.user_id
     * inner join ARTICLES a ON u.id = a.user_id
     * left outer join COMMENTS c ON a.id = c.article_id
     */
    public function testMixedQueryMultipleDeepMixedFetchJoin()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers'
        );
        $rsm->addJoinedEntityResult(
            CmsArticle::class,
            'a',
            'u',
            'articles'
        );
        $rsm->addJoinedEntityResult(
            CmsComment::class,
            'c',
            'a',
            'comments'
        );
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');
        $rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');
        $rsm->addFieldResult('c', 'c__id', 'id');
        $rsm->addFieldResult('c', 'c__topic', 'topic');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id' => '1',
                'a__topic' => 'Getting things done!',
                'c__id' => '1',
                'c__topic' => 'First!'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '1',
                'a__topic' => 'Getting things done!',
                'c__id' => '1',
                'c__topic' => 'First!'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id' => '2',
                'a__topic' => 'ZendCon',
                'c__id' => null,
                'c__topic' => null
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '2',
                'a__topic' => 'ZendCon',
                'c__id' => null,
                'c__topic' => null
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '3',
                'a__topic' => 'LINQ',
                'c__id' => null,
                'c__topic' => null
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '4',
                'a__topic' => 'PHP7',
                'c__id' => null,
                'c__topic' => null
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));
        $this->assertTrue(is_array($result[0]));
        $this->assertTrue(is_array($result[1]));

        // first user => 2 phonenumbers, 2 articles, 1 comment on first article
        $this->assertEquals(2, count($result[0][0]['phonenumbers']));
        $this->assertEquals(2, count($result[0][0]['articles']));
        $this->assertEquals(1, count($result[0][0]['articles'][0]['comments']));
        $this->assertEquals('ROMANB', $result[0]['nameUpper']);
        // second user => 1 phonenumber, 2 articles, no comments
        $this->assertEquals(1, count($result[1][0]['phonenumbers']));
        $this->assertEquals(2, count($result[1][0]['articles']));
        $this->assertEquals('JWAGE', $result[1]['nameUpper']);

        $this->assertEquals(42, $result[0][0]['phonenumbers'][0]['phonenumber']);
        $this->assertEquals(43, $result[0][0]['phonenumbers'][1]['phonenumber']);
        $this->assertEquals(91, $result[1][0]['phonenumbers'][0]['phonenumber']);

        $this->assertEquals('Getting things done!', $result[0][0]['articles'][0]['topic']);
        $this->assertEquals('ZendCon', $result[0][0]['articles'][1]['topic']);
        $this->assertEquals('LINQ', $result[1][0]['articles'][0]['topic']);
        $this->assertEquals('PHP7', $result[1][0]['articles'][1]['topic']);

        $this->assertEquals('First!', $result[0][0]['articles'][0]['comments'][0]['topic']);

        $this->assertTrue(isset($result[0][0]['articles'][0]['comments']));

        // empty comment collections
        $this->assertTrue(is_array($result[0][0]['articles'][1]['comments']));
        $this->assertEquals(0, count($result[0][0]['articles'][1]['comments']));
        $this->assertTrue(is_array($result[1][0]['articles'][0]['comments']));
        $this->assertEquals(0, count($result[1][0]['articles'][0]['comments']));
        $this->assertTrue(is_array($result[1][0]['articles'][1]['comments']));
        $this->assertEquals(0, count($result[1][0]['articles'][1]['comments']));
    }

    /**
     * Tests that the hydrator does not rely on a particular order of the rows
     * in the result set.
     *
     * DQL:
     * select c.id, c.position, c.name, b.id, b.position
     * from \Doctrine\Tests\Models\Forum\ForumCategory c inner join c.boards b
     * order by c.position asc, b.position asc
     *
     * Checks whether the boards are correctly assigned to the categories.
     *
     * The 'evil' result set that confuses the object population is displayed below.
     *
     * c.id  | c.position | c.name   | boardPos | b.id | b.category_id (just for clarity)
     *  1    | 0          | First    | 0        |   1  | 1
     *  2    | 0          | Second   | 0        |   2  | 2   <--
     *  1    | 0          | First    | 1        |   3  | 1
     *  1    | 0          | First    | 2        |   4  | 1
     */
    public function testEntityQueryCustomResultSetOrder()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(ForumCategory::class, 'c');
        $rsm->addJoinedEntityResult(
            ForumBoard::class,
            'b',
            'c',
            'boards'
        );

        $rsm->addFieldResult('c', 'c__id', 'id');
        $rsm->addFieldResult('c', 'c__position', 'position');
        $rsm->addFieldResult('c', 'c__name', 'name');
        $rsm->addFieldResult('b', 'b__id', 'id');
        $rsm->addFieldResult('b', 'b__position', 'position');

        // Faked result set
        $resultSet = [
            [
                'c__id' => '1',
                'c__position' => '0',
                'c__name' => 'First',
                'b__id' => '1',
                'b__position' => '0',
                //'b__category_id' => '1'
            ],
            [
                'c__id' => '2',
                'c__position' => '0',
                'c__name' => 'Second',
                'b__id' => '2',
                'b__position' => '0',
                //'b__category_id' => '2'
            ],
            [
                'c__id' => '1',
                'c__position' => '0',
                'c__name' => 'First',
                'b__id' => '3',
                'b__position' => '1',
                //'b__category_id' => '1'
            ],
            [
                'c__id' => '1',
                'c__position' => '0',
                'c__name' => 'First',
                'b__id' => '4',
                'b__position' => '2',
                //'b__category_id' => '1'
            ]
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));
        $this->assertTrue(is_array($result));
        $this->assertTrue(is_array($result[0]));
        $this->assertTrue(is_array($result[1]));
        $this->assertTrue(isset($result[0]['boards']));
        $this->assertEquals(3, count($result[0]['boards']));
        $this->assertTrue(isset($result[1]['boards']));
        $this->assertEquals(1, count($result[1]['boards']));
    }

    /**
     * SELECT PARTIAL u.{id,status}, a.id, a.topic, c.id as cid, c.topic as ctopic
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   LEFT JOIN u.articles a
     *   LEFT JOIN a.comments c
     *
     * @dataProvider provideDataForUserEntityResult
     */
    public function testChainedJoinWithScalars($entityKey)
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', $entityKey ?: null);
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('a__id', 'id', 'integer');
        $rsm->addScalarResult('a__topic', 'topic', 'string');
        $rsm->addScalarResult('c__id', 'cid', 'integer');
        $rsm->addScalarResult('c__topic', 'ctopic', 'string');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'a__id' => '1',
                'a__topic' => 'The First',
                'c__id' => '1',
                'c__topic' => 'First Comment'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'a__id' => '1',
                'a__topic' => 'The First',
                'c__id' => '2',
                'c__topic' => 'Second Comment'
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'a__id' => '42',
                'a__topic' => 'The Answer',
                'c__id' => null,
                'c__topic' => null
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(3, count($result));

        $this->assertEquals(2, count($result[0][$entityKey])); // User array
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals('The First', $result[0]['topic']);
        $this->assertEquals(1, $result[0]['cid']);
        $this->assertEquals('First Comment', $result[0]['ctopic']);

        $this->assertEquals(2, count($result[1][$entityKey])); // User array, duplicated
        $this->assertEquals(1, $result[1]['id']); // duplicated
        $this->assertEquals('The First', $result[1]['topic']); // duplicated
        $this->assertEquals(2, $result[1]['cid']);
        $this->assertEquals('Second Comment', $result[1]['ctopic']);

        $this->assertEquals(2, count($result[2][$entityKey])); // User array, duplicated
        $this->assertEquals(42, $result[2]['id']);
        $this->assertEquals('The Answer', $result[2]['topic']);
        $this->assertNull($result[2]['cid']);
        $this->assertNull($result[2]['ctopic']);
    }

    /**
     * SELECT PARTIAL u.{id, status}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testResultIteration()
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
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $iterator = $hydrator->iterate($stmt, $rsm);
        $rowNum   = 0;

        while (($row = $iterator->next()) !== false) {
            $this->assertEquals(1, count($row));
            $this->assertTrue(is_array($row[0]));

            if ($rowNum == 0) {
                $this->assertEquals(1, $row[0]['id']);
                $this->assertEquals('romanb', $row[0]['name']);
            } else if ($rowNum == 1) {
                $this->assertEquals(2, $row[0]['id']);
                $this->assertEquals('jwage', $row[0]['name']);
            }

            ++$rowNum;
        }
    }

    /**
     * SELECT PARTIAL u.{id, status}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testResultIterationWithAliasedUserEntity()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', 'user');
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
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $iterator = $hydrator->iterate($stmt, $rsm);
        $rowNum   = 0;

        while (($row = $iterator->next()) !== false) {
            $this->assertEquals(1, count($row));
            $this->assertArrayHasKey(0, $row);
            $this->assertArrayHasKey('user', $row[0]);

            if ($rowNum == 0) {
                $this->assertEquals(1, $row[0]['user']['id']);
                $this->assertEquals('romanb', $row[0]['user']['name']);
            } else if ($rowNum == 1) {
                $this->assertEquals(2, $row[0]['user']['id']);
                $this->assertEquals('jwage', $row[0]['user']['name']);
            }

            ++$rowNum;
        }
    }

    /**
     * SELECT PARTIAL u.{id, name}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *
     * @group DDC-644
     */
    public function testSkipUnknownColumns()
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
                'foo' => 'bar', // unknown!
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(1, count($result));
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayNotHasKey('foo', $result[0]);
    }

    /**
     * SELECT PARTIAL u.{id, status}, UPPER(u.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *
     * @group DDC-1358
     * @dataProvider provideDataForUserEntityResult
     */
    public function testMissingIdForRootEntity($userEntityKey)
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
            ],
            [
                'u__id' => null,
                'u__status' => null,
                'sclr0' => 'ROMANB',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
            ],
            [
                'u__id' => null,
                'u__status' => null,
                'sclr0' => 'JWAGE',
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(4, count($result), "Should hydrate four results.");

        $this->assertEquals('ROMANB', $result[0]['nameUpper']);
        $this->assertEquals('ROMANB', $result[1]['nameUpper']);
        $this->assertEquals('JWAGE', $result[2]['nameUpper']);
        $this->assertEquals('JWAGE', $result[3]['nameUpper']);

        $this->assertEquals(['id' => 1, 'status' => 'developer'], $result[0][$userEntityKey]);
        $this->assertNull($result[1][$userEntityKey]);
        $this->assertEquals(['id' => 2, 'status' => 'developer'], $result[2][$userEntityKey]);
        $this->assertNull($result[3][$userEntityKey]);
    }

    /**
     * SELECT PARTIAL u.{id, status}, UPPER(u.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *        INDEX BY u.id
     *
     * @group DDC-1385
     * @dataProvider provideDataForUserEntityResult
     */
    public function testIndexByAndMixedResult($userEntityKey)
    {
        $rsm = new ResultSetMapping;

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');
        $rsm->addIndexBy('u', 'id');

        // Faked result set
        $resultSet = [
            //row1
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
            ],
        ];

        $stmt     = class_exists(Result::class) ? new ResultMock($resultSet) : new HydratorMockStatement($resultSet);
        $hydrator = new \Doctrine\ORM\Internal\Hydration\ArrayHydrator($this->_em);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        $this->assertEquals(2, count($result));

        $this->assertTrue(isset($result[1]));
        $this->assertEquals(1, $result[1][$userEntityKey]['id']);

        $this->assertTrue(isset($result[2]));
        $this->assertEquals(2, $result[2][$userEntityKey]['id']);
    }
}
