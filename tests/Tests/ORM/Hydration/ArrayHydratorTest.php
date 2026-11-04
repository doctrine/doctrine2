<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Hydration;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Internal\Hydration\ArrayHydrator;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Forum\ForumBoard;
use Doctrine\Tests\Models\Forum\ForumCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

use function iterator_to_array;
use function strtoupper;

class ArrayHydratorTest extends HydrationTestCase
{
    use VerifyDeprecations;

    /** @phpstan-return list<array{int|string}> */
    public static function provideDataForUserEntityResult(): array
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
    public function testSimpleEntityQuery(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);

        self::assertEquals(1, $result[0]['id']);
        self::assertEquals('romanb', $result[0]['name']);

        self::assertEquals(2, $result[1]['id']);
        self::assertEquals('jwage', $result[1]['name']);
    }

    /**
     * SELECT PARTIAL scalars.{id, name}, UPPER(scalars.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser scalars
     */
    #[DataProvider('provideDataForUserEntityResult')]
    public function testSimpleEntityWithScalarQuery(int|string $userEntityKey): void
    {
        $alias = $userEntityKey ?: 'u';
        $rsm   = new ResultSetMapping();

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
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);

        self::assertArrayHasKey('nameUpper', $result[0]);
        self::assertArrayNotHasKey('id', $result[0]);
        self::assertArrayNotHasKey('name', $result[0]);

        self::assertArrayHasKey(0, $result[0]);
        self::assertArrayHasKey('id', $result[0][0]);
        self::assertArrayHasKey('name', $result[0][0]);

        self::assertArrayHasKey('nameUpper', $result[1]);
        self::assertArrayNotHasKey('id', $result[1]);
        self::assertArrayNotHasKey('name', $result[1]);

        self::assertArrayHasKey(0, $result[1]);
        self::assertArrayHasKey('id', $result[1][0]);
        self::assertArrayHasKey('name', $result[1][0]);
    }

    /**
     * SELECT PARTIAL u.{id, name} AS user
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testSimpleEntityQueryWithAliasedUserEntity(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u', 'user');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);

        self::assertArrayHasKey('user', $result[0]);
        self::assertEquals(1, $result[0]['user']['id']);
        self::assertEquals('romanb', $result[0]['user']['name']);

        self::assertArrayHasKey('user', $result[1]);
        self::assertEquals(2, $result[1]['user']['id']);
        self::assertEquals('jwage', $result[1]['user']['name']);
    }

    /**
     * SELECT PARTIAL u.{id, name}, PARTIAL a.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQuery(): void
    {
        $rsm = new ResultSetMapping();

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
                'a__topic' => 'Cool things.',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(4, $result);

        self::assertEquals(1, $result[0]['id']);
        self::assertEquals('romanb', $result[0]['name']);

        self::assertEquals(1, $result[1]['id']);
        self::assertEquals('Cool things.', $result[1]['topic']);

        self::assertEquals(2, $result[2]['id']);
        self::assertEquals('jwage', $result[2]['name']);

        self::assertEquals(2, $result[3]['id']);
        self::assertEquals('Cool things II.', $result[3]['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, name} AS user, PARTIAL a.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQueryWithAliasedUserEntity(): void
    {
        $rsm = new ResultSetMapping();

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
                'a__topic' => 'Cool things.',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(4, $result);

        self::assertArrayHasKey('user', $result[0]);
        self::assertEquals(1, $result[0]['user']['id']);
        self::assertEquals('romanb', $result[0]['user']['name']);

        self::assertArrayHasKey(0, $result[1]);
        self::assertEquals(1, $result[1][0]['id']);
        self::assertEquals('Cool things.', $result[1][0]['topic']);

        self::assertArrayHasKey('user', $result[2]);
        self::assertEquals(2, $result[2]['user']['id']);
        self::assertEquals('jwage', $result[2]['user']['name']);

        self::assertArrayHasKey(0, $result[3]);
        self::assertEquals(2, $result[3][0]['id']);
        self::assertEquals('Cool things II.', $result[3][0]['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, name}, PARTIAL a.{id, topic} AS article
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQueryWithAliasedArticleEntity(): void
    {
        $rsm = new ResultSetMapping();

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
                'a__topic' => 'Cool things.',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(4, $result);

        self::assertArrayHasKey(0, $result[0]);
        self::assertEquals(1, $result[0][0]['id']);
        self::assertEquals('romanb', $result[0][0]['name']);

        self::assertArrayHasKey('article', $result[1]);
        self::assertEquals(1, $result[1]['article']['id']);
        self::assertEquals('Cool things.', $result[1]['article']['topic']);

        self::assertArrayHasKey(0, $result[2]);
        self::assertEquals(2, $result[2][0]['id']);
        self::assertEquals('jwage', $result[2][0]['name']);

        self::assertArrayHasKey('article', $result[3]);
        self::assertEquals(2, $result[3]['article']['id']);
        self::assertEquals('Cool things II.', $result[3]['article']['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, name} AS user, PARTIAL a.{id, topic} AS article
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsArticle a
     */
    public function testSimpleMultipleRootEntityQueryWithAliasedEntities(): void
    {
        $rsm = new ResultSetMapping();

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
                'a__topic' => 'Cool things.',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
                'a__id' => '2',
                'a__topic' => 'Cool things II.',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(4, $result);

        self::assertArrayHasKey('user', $result[0]);
        self::assertEquals(1, $result[0]['user']['id']);
        self::assertEquals('romanb', $result[0]['user']['name']);

        self::assertArrayHasKey('article', $result[1]);
        self::assertEquals(1, $result[1]['article']['id']);
        self::assertEquals('Cool things.', $result[1]['article']['topic']);

        self::assertArrayHasKey('user', $result[2]);
        self::assertEquals(2, $result[2]['user']['id']);
        self::assertEquals('jwage', $result[2]['user']['name']);

        self::assertArrayHasKey('article', $result[3]);
        self::assertEquals(2, $result[3]['article']['id']);
        self::assertEquals('Cool things II.', $result[3]['article']['topic']);
    }

    /**
     * SELECT PARTIAL u.{id, status}, COUNT(p.phonenumber) AS numPhones
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.phonenumbers p
     *  GROUP BY u.status, u.id
     */
    #[DataProvider('provideDataForUserEntityResult')]
    public function testMixedQueryNormalJoin(int|string $userEntityKey): void
    {
        $rsm = new ResultSetMapping();

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
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);

        // first user => 2 phonenumbers
        self::assertArrayHasKey($userEntityKey, $result[0]);
        self::assertEquals(2, $result[0]['numPhones']);

        // second user => 1 phonenumber
        self::assertArrayHasKey($userEntityKey, $result[1]);
        self::assertEquals(1, $result[1]['numPhones']);
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL p.{phonenumber}, UPPER(u.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.phonenumbers p
     */
    #[DataProvider('provideDataForUserEntityResult')]
    public function testMixedQueryFetchJoin(int|string $userEntityKey): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers',
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
                'p__phonenumber' => '91',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);

        self::assertIsArray($result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);

        // first user => 2 phonenumbers
        self::assertCount(2, $result[0][$userEntityKey]['phonenumbers']);
        self::assertEquals('ROMANB', $result[0]['nameUpper']);

        // second user => 1 phonenumber
        self::assertCount(1, $result[1][$userEntityKey]['phonenumbers']);
        self::assertEquals('JWAGE', $result[1]['nameUpper']);

        self::assertEquals(42, $result[0][$userEntityKey]['phonenumbers'][0]['phonenumber']);
        self::assertEquals(43, $result[0][$userEntityKey]['phonenumbers'][1]['phonenumber']);
        self::assertEquals(91, $result[1][$userEntityKey]['phonenumbers'][0]['phonenumber']);
    }

    /**
     * SELECT PARTIAL u.{id, status}, UPPER(u.name) nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *        INDEX BY u.id
     *   JOIN u.phonenumbers p
     *        INDEX BY p.phonenumber
     */
    #[DataProvider('provideDataForUserEntityResult')]
    public function testMixedQueryFetchJoinCustomIndex(int|string $userEntityKey): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u', $userEntityKey ?: null);
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers',
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
                'p__phonenumber' => '91',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);

        self::assertIsArray($result);
        self::assertIsArray($result[1]);
        self::assertIsArray($result[2]);

        // test the scalar values
        self::assertEquals('ROMANB', $result[1]['nameUpper']);
        self::assertEquals('JWAGE', $result[2]['nameUpper']);

        // first user => 2 phonenumbers. notice the custom indexing by user id
        self::assertCount(2, $result[1][$userEntityKey]['phonenumbers']);

        // second user => 1 phonenumber. notice the custom indexing by user id
        self::assertCount(1, $result[2][$userEntityKey]['phonenumbers']);

        // test the custom indexing of the phonenumbers
        self::assertTrue(isset($result[1][$userEntityKey]['phonenumbers']['42']));
        self::assertTrue(isset($result[1][$userEntityKey]['phonenumbers']['43']));
        self::assertTrue(isset($result[2][$userEntityKey]['phonenumbers']['91']));
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
    public function testMixedQueryMultipleFetchJoin(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers',
        );
        $rsm->addJoinedEntityResult(
            CmsArticle::class,
            'a',
            'u',
            'articles',
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
                'a__topic' => 'Getting things done!',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '1',
                'a__topic' => 'Getting things done!',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id' => '2',
                'a__topic' => 'ZendCon',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '2',
                'a__topic' => 'ZendCon',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '3',
                'a__topic' => 'LINQ',
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '4',
                'a__topic' => 'PHP7',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);
        // first user => 2 phonenumbers, 2 articles
        self::assertCount(2, $result[0][0]['phonenumbers']);
        self::assertCount(2, $result[0][0]['articles']);
        self::assertEquals('ROMANB', $result[0]['nameUpper']);
        // second user => 1 phonenumber, 2 articles
        self::assertCount(1, $result[1][0]['phonenumbers']);
        self::assertCount(2, $result[1][0]['articles']);
        self::assertEquals('JWAGE', $result[1]['nameUpper']);

        self::assertEquals(42, $result[0][0]['phonenumbers'][0]['phonenumber']);
        self::assertEquals(43, $result[0][0]['phonenumbers'][1]['phonenumber']);
        self::assertEquals(91, $result[1][0]['phonenumbers'][0]['phonenumber']);

        self::assertEquals('Getting things done!', $result[0][0]['articles'][0]['topic']);
        self::assertEquals('ZendCon', $result[0][0]['articles'][1]['topic']);
        self::assertEquals('LINQ', $result[1][0]['articles'][0]['topic']);
        self::assertEquals('PHP7', $result[1][0]['articles'][1]['topic']);
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
    public function testMixedQueryMultipleDeepMixedFetchJoin(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(
            CmsPhonenumber::class,
            'p',
            'u',
            'phonenumbers',
        );
        $rsm->addJoinedEntityResult(
            CmsArticle::class,
            'a',
            'u',
            'articles',
        );
        $rsm->addJoinedEntityResult(
            CmsComment::class,
            'c',
            'a',
            'comments',
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
                'c__topic' => 'First!',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '1',
                'a__topic' => 'Getting things done!',
                'c__id' => '1',
                'c__topic' => 'First!',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '42',
                'a__id' => '2',
                'a__topic' => 'ZendCon',
                'c__id' => null,
                'c__topic' => null,
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'sclr0' => 'ROMANB',
                'p__phonenumber' => '43',
                'a__id' => '2',
                'a__topic' => 'ZendCon',
                'c__id' => null,
                'c__topic' => null,
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '3',
                'a__topic' => 'LINQ',
                'c__id' => null,
                'c__topic' => null,
            ],
            [
                'u__id' => '2',
                'u__status' => 'developer',
                'sclr0' => 'JWAGE',
                'p__phonenumber' => '91',
                'a__id' => '4',
                'a__topic' => 'PHP7',
                'c__id' => null,
                'c__topic' => null,
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);

        // first user => 2 phonenumbers, 2 articles, 1 comment on first article
        self::assertCount(2, $result[0][0]['phonenumbers']);
        self::assertCount(2, $result[0][0]['articles']);
        self::assertCount(1, $result[0][0]['articles'][0]['comments']);
        self::assertEquals('ROMANB', $result[0]['nameUpper']);
        // second user => 1 phonenumber, 2 articles, no comments
        self::assertCount(1, $result[1][0]['phonenumbers']);
        self::assertCount(2, $result[1][0]['articles']);
        self::assertEquals('JWAGE', $result[1]['nameUpper']);

        self::assertEquals(42, $result[0][0]['phonenumbers'][0]['phonenumber']);
        self::assertEquals(43, $result[0][0]['phonenumbers'][1]['phonenumber']);
        self::assertEquals(91, $result[1][0]['phonenumbers'][0]['phonenumber']);

        self::assertEquals('Getting things done!', $result[0][0]['articles'][0]['topic']);
        self::assertEquals('ZendCon', $result[0][0]['articles'][1]['topic']);
        self::assertEquals('LINQ', $result[1][0]['articles'][0]['topic']);
        self::assertEquals('PHP7', $result[1][0]['articles'][1]['topic']);

        self::assertEquals('First!', $result[0][0]['articles'][0]['comments'][0]['topic']);

        self::assertTrue(isset($result[0][0]['articles'][0]['comments']));

        // empty comment collections
        self::assertIsArray($result[0][0]['articles'][1]['comments']);
        self::assertCount(0, $result[0][0]['articles'][1]['comments']);
        self::assertIsArray($result[1][0]['articles'][0]['comments']);
        self::assertCount(0, $result[1][0]['articles'][0]['comments']);
        self::assertIsArray($result[1][0]['articles'][1]['comments']);
        self::assertCount(0, $result[1][0]['articles'][1]['comments']);
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
    public function testEntityQueryCustomResultSetOrder(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(ForumCategory::class, 'c');
        $rsm->addJoinedEntityResult(
            ForumBoard::class,
            'b',
            'c',
            'boards',
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
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);
        self::assertIsArray($result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);
        self::assertTrue(isset($result[0]['boards']));
        self::assertCount(3, $result[0]['boards']);
        self::assertTrue(isset($result[1]['boards']));
        self::assertCount(1, $result[1]['boards']);
    }

    /**
     * SELECT PARTIAL u.{id,status}, a.id, a.topic, c.id as cid, c.topic as ctopic
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   LEFT JOIN u.articles a
     *   LEFT JOIN a.comments c
     */
    #[DataProvider('provideDataForUserEntityResult')]
    public function testChainedJoinWithScalars(int|string $entityKey): void
    {
        $rsm = new ResultSetMapping();

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
                'c__topic' => 'First Comment',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'a__id' => '1',
                'a__topic' => 'The First',
                'c__id' => '2',
                'c__topic' => 'Second Comment',
            ],
            [
                'u__id' => '1',
                'u__status' => 'developer',
                'a__id' => '42',
                'a__topic' => 'The Answer',
                'c__id' => null,
                'c__topic' => null,
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(3, $result);

        self::assertCount(2, $result[0][$entityKey]); // User array
        self::assertEquals(1, $result[0]['id']);
        self::assertEquals('The First', $result[0]['topic']);
        self::assertEquals(1, $result[0]['cid']);
        self::assertEquals('First Comment', $result[0]['ctopic']);

        self::assertCount(2, $result[1][$entityKey]); // User array, duplicated
        self::assertEquals(1, $result[1]['id']); // duplicated
        self::assertEquals('The First', $result[1]['topic']); // duplicated
        self::assertEquals(2, $result[1]['cid']);
        self::assertEquals('Second Comment', $result[1]['ctopic']);

        self::assertCount(2, $result[2][$entityKey]); // User array, duplicated
        self::assertEquals(42, $result[2]['id']);
        self::assertEquals('The Answer', $result[2]['topic']);
        self::assertNull($result[2]['cid']);
        self::assertNull($result[2]['ctopic']);
    }

    private function createUserArticleCommentResultSetMapping(): ResultSetMapping
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(CmsArticle::class, 'a', 'u', 'articles');
        $rsm->addJoinedEntityResult(CmsComment::class, 'c', 'a', 'comments');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');
        $rsm->addFieldResult('c', 'c__id', 'id');
        $rsm->addFieldResult('c', 'c__topic', 'topic');

        return $rsm;
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL a.{id, topic}, PARTIAL c.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.articles a
     *   JOIN a.comments c
     */
    public function testDeepFetchJoinAttachesToElementsOfEarlierRows(): void
    {
        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '1', 'c__topic' => 'C1'],
            ['u__id' => '2', 'u__status' => 'admin', 'a__id' => '2', 'a__topic' => 'A2', 'c__id' => '2', 'c__topic' => 'C2'],
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '3', 'c__topic' => 'C3'],
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '3', 'a__topic' => 'A3', 'c__id' => null, 'c__topic' => null],
        ];

        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($this->createResultMock($resultSet), $this->createUserArticleCommentResultSetMapping());

        self::assertSame([
            [
                'id' => 1,
                'status' => 'developer',
                'articles' => [
                    [
                        'id' => 1,
                        'topic' => 'A1',
                        'comments' => [
                            ['id' => 1, 'topic' => 'C1'],
                            ['id' => 3, 'topic' => 'C3'],
                        ],
                    ],
                    ['id' => 3, 'topic' => 'A3', 'comments' => []],
                ],
            ],
            [
                'id' => 2,
                'status' => 'admin',
                'articles' => [
                    ['id' => 2, 'topic' => 'A2', 'comments' => [['id' => 2, 'topic' => 'C2']]],
                ],
            ],
        ], $result);
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL a.{id, topic}, PARTIAL c.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.articles a
     *   JOIN a.comments c
     */
    #[IgnoreDeprecations]
    public function testLegacyHydrateRowDataOverrideGetsNestedResultAfterEachRow(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12728');

        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '1', 'c__topic' => 'C1'],
            ['u__id' => '2', 'u__status' => 'admin', 'a__id' => '2', 'a__topic' => 'A2', 'c__id' => '2', 'c__topic' => 'C2'],
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '3', 'c__topic' => 'C3'],
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '3', 'a__topic' => 'A3', 'c__id' => null, 'c__topic' => null],
        ];
        $rsm       = $this->createUserArticleCommentResultSetMapping();

        $expected = (new ArrayHydrator($this->entityManager))->hydrateAll($this->createResultMock($resultSet), $rsm);
        $hydrator = new LegacyHydrateRowDataArrayHydrator($this->entityManager);

        self::assertSame($expected, $hydrator->hydrateAll($this->createResultMock($resultSet), $rsm));
        self::assertCount(4, $hydrator->resultsAfterRow);
        self::assertSame($expected, $hydrator->resultsAfterRow[3]);
        self::assertSame(
            [['id' => 1, 'topic' => 'C1'], ['id' => 3, 'topic' => 'C3']],
            $hydrator->resultsAfterRow[2][0]['articles'][0]['comments'],
        );
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL a.{id, topic}, PARTIAL c.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.articles a
     *   JOIN a.comments c
     */
    #[IgnoreDeprecations]
    public function testLegacyHydrateRowDataOverrideIsUsedByToIterable(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12728');

        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '1', 'c__topic' => 'C1'],
            ['u__id' => '2', 'u__status' => 'admin', 'a__id' => '2', 'a__topic' => 'A2', 'c__id' => '2', 'c__topic' => 'C2'],
        ];

        $hydrator = new LegacyHydrateRowDataArrayHydrator($this->entityManager);
        $rows     = iterator_to_array($hydrator->toIterable($this->createResultMock($resultSet), $this->createUserArticleCommentResultSetMapping()), false);

        self::assertSame([
            ['id' => 1, 'status' => 'developer', 'articles' => [['id' => 1, 'topic' => 'A1', 'comments' => [['id' => 1, 'topic' => 'C1']]]]],
            ['id' => 2, 'status' => 'admin', 'articles' => [['id' => 2, 'topic' => 'A2', 'comments' => [['id' => 2, 'topic' => 'C2']]]]],
        ], $rows);
        self::assertCount(2, $hydrator->resultsAfterRow);
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL a.{id, topic}, PARTIAL c.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.articles a
     *   JOIN a.comments c
     */
    #[IgnoreDeprecations]
    public function testLegacyGatherRowDataOverrideIsUsedByHydrateRow(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12728');

        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '1', 'c__topic' => 'C1'],
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '2', 'c__topic' => 'C2'],
        ];

        $hydrator = new LegacyGatherRowDataArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($this->createResultMock($resultSet), $this->createUserArticleCommentResultSetMapping());

        self::assertSame([
            [
                'id' => 1,
                'status' => 'DEVELOPER',
                'articles' => [
                    ['id' => 1, 'topic' => 'A1', 'comments' => [['id' => 1, 'topic' => 'C1'], ['id' => 2, 'topic' => 'C2']]],
                ],
            ],
        ], $result);
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL a.{id, topic}, PARTIAL c.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   JOIN u.articles a
     *   JOIN a.comments c
     */
    public function testResultIterationWithFetchJoinHydratesEachRowOnItsOwn(): void
    {
        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '1', 'c__topic' => 'C1'],
            ['u__id' => '1', 'u__status' => 'developer', 'a__id' => '1', 'a__topic' => 'A1', 'c__id' => '2', 'c__topic' => 'C2'],
        ];

        $hydrator = new ArrayHydrator($this->entityManager);
        $rows     = [];

        foreach ($hydrator->toIterable($this->createResultMock($resultSet), $this->createUserArticleCommentResultSetMapping()) as $row) {
            $rows[] = $row;
        }

        self::assertSame([
            [
                'id' => 1,
                'status' => 'developer',
                'articles' => [['id' => 1, 'topic' => 'A1', 'comments' => [['id' => 1, 'topic' => 'C1']]]],
            ],
            [
                'id' => 1,
                'status' => 'developer',
                'articles' => [['id' => 1, 'topic' => 'A1', 'comments' => [['id' => 2, 'topic' => 'C2']]]],
            ],
        ], $rows);
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL p.{phonenumber}, UPPER(u.name) nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   LEFT JOIN u.phonenumbers p
     */
    public function testResultIterationWithFetchJoinAndScalar(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(CmsPhonenumber::class, 'p', 'u', 'phonenumbers');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addScalarResult('sclr0', 'nameUpper', 'string');
        $rsm->addFieldResult('p', 'p__phonenumber', 'phonenumber');

        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'sclr0' => 'ROMANB', 'p__phonenumber' => '42'],
            ['u__id' => '1', 'u__status' => 'developer', 'sclr0' => 'ROMANB', 'p__phonenumber' => '43'],
            ['u__id' => '2', 'u__status' => 'developer', 'sclr0' => 'JWAGE', 'p__phonenumber' => null],
        ];

        $hydrator = new ArrayHydrator($this->entityManager);

        self::assertSame([
            [
                0 => ['id' => 1, 'status' => 'developer', 'phonenumbers' => [['phonenumber' => '42']]],
                'nameUpper' => 'ROMANB',
            ],
            [
                0 => ['id' => 1, 'status' => 'developer', 'phonenumbers' => [['phonenumber' => '43']]],
                'nameUpper' => 'ROMANB',
            ],
            [
                0 => ['id' => 2, 'status' => 'developer', 'phonenumbers' => []],
                'nameUpper' => 'JWAGE',
            ],
        ], iterator_to_array($hydrator->toIterable($this->createResultMock($resultSet), $rsm), false));
    }

    /**
     * SELECT PARTIAL u.{id, status}, PARTIAL ad.{id, city}, PARTIAL a.{id, topic}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *   LEFT JOIN u.address ad
     *   LEFT JOIN u.articles a INDEX BY a.id
     */
    public function testResultIterationWithToOneAndIndexedFetchJoin(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addJoinedEntityResult(CmsAddress::class, 'ad', 'u', 'address');
        $rsm->addJoinedEntityResult(CmsArticle::class, 'a', 'u', 'articles');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__status', 'status');
        $rsm->addFieldResult('ad', 'ad__id', 'id');
        $rsm->addFieldResult('ad', 'ad__city', 'city');
        $rsm->addFieldResult('a', 'a__id', 'id');
        $rsm->addFieldResult('a', 'a__topic', 'topic');
        $rsm->addIndexBy('a', 'id');

        $resultSet = [
            ['u__id' => '1', 'u__status' => 'developer', 'ad__id' => '1', 'ad__city' => 'Berlin', 'a__id' => '7', 'a__topic' => 'A7'],
            ['u__id' => '2', 'u__status' => 'developer', 'ad__id' => null, 'ad__city' => null, 'a__id' => '9', 'a__topic' => 'A9'],
            ['u__id' => '3', 'u__status' => 'developer', 'ad__id' => null, 'ad__city' => null, 'a__id' => null, 'a__topic' => null],
        ];

        $hydrator = new ArrayHydrator($this->entityManager);

        self::assertSame([
            [
                'id' => 1,
                'status' => 'developer',
                'address' => ['id' => 1, 'city' => 'Berlin'],
                'articles' => [7 => ['id' => 7, 'topic' => 'A7']],
            ],
            [
                'id' => 2,
                'status' => 'developer',
                'address' => null,
                'articles' => [9 => ['id' => 9, 'topic' => 'A9']],
            ],
            [
                'id' => 3,
                'status' => 'developer',
                'address' => null,
                'articles' => [],
            ],
        ], iterator_to_array($hydrator->toIterable($this->createResultMock($resultSet), $rsm), false));
    }

    /**
     * SELECT PARTIAL u.{id, status}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testResultIteration(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $iterator = $hydrator->toIterable($stmt, $rsm);
        $rowNum   = 0;

        foreach ($iterator as $row) {
            if ($rowNum === 0) {
                self::assertEquals(1, $row['id']);
                self::assertEquals('romanb', $row['name']);
            } elseif ($rowNum === 1) {
                self::assertEquals(2, $row['id']);
                self::assertEquals('jwage', $row['name']);
            }

            ++$rowNum;
        }
    }

    /**
     * SELECT PARTIAL u.{id, status}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    public function testResultIterationWithAliasedUserEntity(): void
    {
        $rsm = new ResultSetMapping();

        $rsm->addEntityResult(CmsUser::class, 'u', 'user');
        $rsm->addFieldResult('u', 'u__id', 'id');
        $rsm->addFieldResult('u', 'u__name', 'name');

        // Faked result set
        $resultSet = [
            [
                'u__id' => '1',
                'u__name' => 'romanb',
            ],
            [
                'u__id' => '2',
                'u__name' => 'jwage',
            ],
        ];

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $iterator = $hydrator->toIterable($stmt, $rsm);
        $rowNum   = 0;

        foreach ($iterator as $row) {
            self::assertArrayHasKey('user', $row);

            if ($rowNum === 0) {
                self::assertEquals(1, $row['user']['id']);
                self::assertEquals('romanb', $row['user']['name']);
            } elseif ($rowNum === 1) {
                self::assertEquals(2, $row['user']['id']);
                self::assertEquals('jwage', $row['user']['name']);
            }

            ++$rowNum;
        }
    }

    /**
     * SELECT PARTIAL u.{id, name}
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    #[Group('DDC-644')]
    public function testSkipUnknownColumns(): void
    {
        $rsm = new ResultSetMapping();

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

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(1, $result);
        self::assertArrayHasKey('id', $result[0]);
        self::assertArrayHasKey('name', $result[0]);
        self::assertArrayNotHasKey('foo', $result[0]);
    }

    /**
     * SELECT PARTIAL u.{id, status}, UPPER(u.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     */
    #[DataProvider('provideDataForUserEntityResult')]
    #[Group('DDC-1358')]
    public function testMissingIdForRootEntity(int|string $userEntityKey): void
    {
        $rsm = new ResultSetMapping();

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

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(4, $result, 'Should hydrate four results.');

        self::assertEquals('ROMANB', $result[0]['nameUpper']);
        self::assertEquals('ROMANB', $result[1]['nameUpper']);
        self::assertEquals('JWAGE', $result[2]['nameUpper']);
        self::assertEquals('JWAGE', $result[3]['nameUpper']);

        self::assertEquals(['id' => 1, 'status' => 'developer'], $result[0][$userEntityKey]);
        self::assertNull($result[1][$userEntityKey]);
        self::assertEquals(['id' => 2, 'status' => 'developer'], $result[2][$userEntityKey]);
        self::assertNull($result[3][$userEntityKey]);
    }

    /**
     * SELECT PARTIAL u.{id, status}, UPPER(u.name) AS nameUpper
     *   FROM Doctrine\Tests\Models\CMS\CmsUser u
     *        INDEX BY u.id
     */
    #[DataProvider('provideDataForUserEntityResult')]
    #[Group('DDC-1385')]
    public function testIndexByAndMixedResult(int|string $userEntityKey): void
    {
        $rsm = new ResultSetMapping();

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

        $stmt     = $this->createResultMock($resultSet);
        $hydrator = new ArrayHydrator($this->entityManager);
        $result   = $hydrator->hydrateAll($stmt, $rsm);

        self::assertCount(2, $result);

        self::assertArrayHasKey(1, $result);
        self::assertEquals(1, $result[1][$userEntityKey]['id']);

        self::assertArrayHasKey(2, $result);
        self::assertEquals(2, $result[2][$userEntityKey]['id']);
    }
}

/**
 * Overrides the deprecated by-reference method and inspects the result after each row.
 */
class LegacyHydrateRowDataArrayHydrator extends ArrayHydrator
{
    /** @var list<mixed[]> */
    public array $resultsAfterRow = [];

    /**
     * @param mixed[] $row
     * @param mixed[] $result
     */
    protected function hydrateRowData(array $row, array &$result): void
    {
        parent::hydrateRowData($row, $result);

        $this->resultsAfterRow[] = $result;
    }
}

/**
 * Overrides the deprecated by-reference method to change the gathered data.
 */
class LegacyGatherRowDataArrayHydrator extends ArrayHydrator
{
    /**
     * @param mixed[]               $data
     * @param array<string, string> $id
     * @param array<string, bool>   $nonemptyComponents
     *
     * @return array<string, array<string, mixed>>
     */
    protected function gatherRowData(array $data, array &$id, array &$nonemptyComponents): array
    {
        $rowData = parent::gatherRowData($data, $id, $nonemptyComponents);

        $rowData['data']['u']['status'] = strtoupper($rowData['data']['u']['status']);

        return $rowData;
    }
}
