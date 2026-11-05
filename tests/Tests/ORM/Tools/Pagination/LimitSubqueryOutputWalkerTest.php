<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\LimitSubqueryOutputWalker;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class LimitSubqueryOutputWalkerTest extends PaginationTestCase
{
    public function testSubqueryClonedCompletely(): void
    {
        $query = $this->createQuery('SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p');
        $query->setParameter('dummy-param', 123);
        $query->setHint('dummy-hint', 'dummy-value');
        $query->setCacheable(true);

        $walker = new LimitSubqueryOutputWalker($query, new Query\ParserResult(), []);

        self::assertNotSame($query, $walker->getQuery());
        self::assertTrue($walker->getQuery()->hasHint('dummy-hint'));
        self::assertSame('dummy-value', $walker->getQuery()->getHint('dummy-hint'));
        self::assertNotSame($query->getParameters(), $walker->getQuery()->getParameters());
        self::assertInstanceOf(Query\Parameter::class, $param = $walker->getQuery()->getParameter('dummy-param'));
        self::assertSame(123, $param->getValue());
        self::assertFalse($walker->getQuery()->isCacheable());
    }

    public function testLimitSubquery(): void
    {
        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id) dctrn_result',
            'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a',
        );
    }

    public function testLimitSubqueryWithSortPg(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0, MIN(sclr_5) AS dctrn_minrownum FROM (SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, ROW_NUMBER() OVER(ORDER BY m0_.title ASC) AS sclr_5, m0_.author_id AS author_id_6, m0_.category_id AS category_id_7 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id) dctrn_result GROUP BY id_0 ORDER BY dctrn_minrownum ASC',
            'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a ORDER BY p.title',
        );
    }

    public function testLimitSubqueryWithScalarSortPg(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_1, MIN(sclr_3) AS dctrn_minrownum FROM (SELECT COUNT(g0_.id) AS sclr_0, u1_.id AS id_1, g0_.id AS id_2, ROW_NUMBER() OVER(ORDER BY COUNT(g0_.id) ASC) AS sclr_3 FROM User u1_ INNER JOIN user_group u2_ ON u1_.id = u2_.user_id INNER JOIN groups g0_ ON g0_.id = u2_.group_id) dctrn_result GROUP BY id_1 ORDER BY dctrn_minrownum ASC',
            'SELECT u, g, COUNT(g.id) AS g_quantity FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY g_quantity',
        );
    }

    public function testLimitSubqueryWithMixedSortPg(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_1, MIN(sclr_3) AS dctrn_minrownum FROM (SELECT COUNT(g0_.id) AS sclr_0, u1_.id AS id_1, g0_.id AS id_2, ROW_NUMBER() OVER(ORDER BY COUNT(g0_.id) ASC, u1_.id DESC) AS sclr_3 FROM User u1_ INNER JOIN user_group u2_ ON u1_.id = u2_.user_id INNER JOIN groups g0_ ON g0_.id = u2_.group_id) dctrn_result GROUP BY id_1 ORDER BY dctrn_minrownum ASC',
            'SELECT u, g, COUNT(g.id) AS g_quantity FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY g_quantity, u.id DESC',
        );
    }

    public function testLimitSubqueryWithHiddenScalarSortPg(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_1, MIN(sclr_3) AS dctrn_minrownum FROM (SELECT COUNT(g0_.id) AS sclr_0, u1_.id AS id_1, g0_.id AS id_2, ROW_NUMBER() OVER(ORDER BY COUNT(g0_.id) ASC, u1_.id DESC) AS sclr_3 FROM User u1_ INNER JOIN user_group u2_ ON u1_.id = u2_.user_id INNER JOIN groups g0_ ON g0_.id = u2_.group_id) dctrn_result GROUP BY id_1 ORDER BY dctrn_minrownum ASC',
            'SELECT u, g, COUNT(g.id) AS hidden g_quantity FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY g_quantity, u.id DESC',
        );
    }

    public function testLimitSubqueryPg(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->testLimitSubquery();
    }

    public function testLimitSubqueryWithSortOracle(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new OraclePlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT ID_0, MIN(SCLR_5) AS dctrn_minrownum FROM (SELECT m0_.id AS ID_0, m0_.title AS TITLE_1, c1_.id AS ID_2, a2_.id AS ID_3, a2_.name AS NAME_4, ROW_NUMBER() OVER(ORDER BY m0_.title ASC) AS SCLR_5, m0_.author_id AS AUTHOR_ID_6, m0_.category_id AS CATEGORY_ID_7 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id) dctrn_result GROUP BY ID_0 ORDER BY dctrn_minrownum ASC',
            'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a ORDER BY p.title',
        );
    }

    public function testLimitSubqueryWithScalarSortOracle(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new OraclePlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT ID_1, MIN(SCLR_3) AS dctrn_minrownum FROM (SELECT COUNT(g0_.id) AS SCLR_0, u1_.id AS ID_1, g0_.id AS ID_2, ROW_NUMBER() OVER(ORDER BY COUNT(g0_.id) ASC) AS SCLR_3 FROM User u1_ INNER JOIN user_group u2_ ON u1_.id = u2_.user_id INNER JOIN groups g0_ ON g0_.id = u2_.group_id) dctrn_result GROUP BY ID_1 ORDER BY dctrn_minrownum ASC',
            'SELECT u, g, COUNT(g.id) AS g_quantity FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY g_quantity',
        );
    }

    public function testLimitSubqueryWithMixedSortOracle(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new OraclePlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT ID_1, MIN(SCLR_3) AS dctrn_minrownum FROM (SELECT COUNT(g0_.id) AS SCLR_0, u1_.id AS ID_1, g0_.id AS ID_2, ROW_NUMBER() OVER(ORDER BY COUNT(g0_.id) ASC, u1_.id DESC) AS SCLR_3 FROM User u1_ INNER JOIN user_group u2_ ON u1_.id = u2_.user_id INNER JOIN groups g0_ ON g0_.id = u2_.group_id) dctrn_result GROUP BY ID_1 ORDER BY dctrn_minrownum ASC',
            'SELECT u, g, COUNT(g.id) AS g_quantity FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY g_quantity, u.id DESC',
        );
    }

    public function testLimitSubqueryOracle(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new OraclePlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT ID_0 FROM (SELECT m0_.id AS ID_0, m0_.title AS TITLE_1, c1_.id AS ID_2, a2_.id AS ID_3, a2_.name AS NAME_4, m0_.author_id AS AUTHOR_ID_5, m0_.category_id AS CATEGORY_ID_6 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id) dctrn_result',
            'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a',
        );
    }

    public function testCountQueryMixedResultsWithName(): void
    {
        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT a0_.id AS id_0, a0_.name AS name_1, sum(a0_.name) AS sclr_2 FROM Author a0_) dctrn_result',
            'SELECT a, sum(a.name) as foo FROM Doctrine\Tests\ORM\Tools\Pagination\Author a',
        );
    }

    #[Group('DDC-3336')]
    public function testCountQueryWithArithmeticOrderByCondition(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, (1 - 1000) * 1 FROM (SELECT a0_.id AS id_0, a0_.name AS name_1 FROM Author a0_) dctrn_result_inner ORDER BY (1 - 1000) * 1 DESC) dctrn_result',
            'SELECT a FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY (1 - 1000) * 1 DESC',
        );
    }

    public function testCountQueryWithComplexScalarOrderByItemWithoutJoin(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, imageHeight_2 * imageWidth_3 FROM (SELECT a0_.id AS id_0, a0_.image AS image_1, a0_.imageHeight AS imageHeight_2, a0_.imageWidth AS imageWidth_3, a0_.imageAltDesc AS imageAltDesc_4, a0_.user_id AS user_id_5 FROM Avatar a0_) dctrn_result_inner ORDER BY imageHeight_2 * imageWidth_3 DESC) dctrn_result',
            'SELECT a FROM Doctrine\Tests\ORM\Tools\Pagination\Avatar a ORDER BY a.imageHeight * a.imageWidth DESC',
        );
    }

    public function testCountQueryWithComplexScalarOrderByItemJoinedWithoutPartial(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, imageHeight_3 * imageWidth_4 FROM (SELECT u0_.id AS id_0, a1_.id AS id_1, a1_.image AS image_2, a1_.imageHeight AS imageHeight_3, a1_.imageWidth AS imageWidth_4, a1_.imageAltDesc AS imageAltDesc_5, a1_.user_id AS user_id_6 FROM User u0_ INNER JOIN Avatar a1_ ON u0_.id = a1_.user_id) dctrn_result_inner ORDER BY imageHeight_3 * imageWidth_4 DESC) dctrn_result',
            'SELECT u FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.avatar a ORDER BY a.imageHeight * a.imageWidth DESC',
        );
    }

    public function testCountQueryWithComplexScalarOrderByItemJoinedWithPartial(): void
    {
        $entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $query = $entityManager->createQuery(
            'SELECT u, partial a.{id, imageAltDesc} FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.avatar a ORDER BY a.imageHeight * a.imageWidth DESC',
        );

        $query->setHydrationMode(Query::HYDRATE_ARRAY);
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, LimitSubqueryOutputWalker::class);

        self::assertSame(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, imageHeight_5 * imageWidth_6 FROM (SELECT u0_.id AS id_0, a1_.id AS id_1, a1_.imageAltDesc AS imageAltDesc_2, a1_.id AS id_3, a1_.image AS image_4, a1_.imageHeight AS imageHeight_5, a1_.imageWidth AS imageWidth_6, a1_.imageAltDesc AS imageAltDesc_7 FROM User u0_ INNER JOIN Avatar a1_ ON u0_.id = a1_.user_id) dctrn_result_inner ORDER BY imageHeight_5 * imageWidth_6 DESC) dctrn_result',
            $query->getSQL(),
        );
    }

    public function testCountQueryWithComplexScalarOrderByItemOracle(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new OraclePlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT ID_0, MIN(SCLR_5) AS dctrn_minrownum FROM (SELECT a0_.id AS ID_0, a0_.image AS IMAGE_1, a0_.imageHeight AS IMAGEHEIGHT_2, a0_.imageWidth AS IMAGEWIDTH_3, a0_.imageAltDesc AS IMAGEALTDESC_4, ROW_NUMBER() OVER(ORDER BY a0_.imageHeight * a0_.imageWidth DESC) AS SCLR_5, a0_.user_id AS USER_ID_6 FROM Avatar a0_) dctrn_result GROUP BY ID_0 ORDER BY dctrn_minrownum ASC',
            'SELECT a FROM Doctrine\Tests\ORM\Tools\Pagination\Avatar a ORDER BY a.imageHeight * a.imageWidth DESC',
        );
    }

    #[Group('DDC-3434')]
    public function testLimitSubqueryWithHiddenSelectionInOrderBy(): void
    {
        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, name_2 FROM (SELECT a0_.id AS id_0, a0_.name AS name_1, a0_.name AS name_2 FROM Author a0_) dctrn_result_inner ORDER BY name_2 DESC) dctrn_result',
            'SELECT a, a.name AS HIDDEN ord FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY ord DESC',
        );
    }

    public function testLimitSubqueryWithColumnWithSortDirectionInName(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, imageAltDesc_4 FROM (SELECT a0_.id AS id_0, a0_.image AS image_1, a0_.imageHeight AS imageHeight_2, a0_.imageWidth AS imageWidth_3, a0_.imageAltDesc AS imageAltDesc_4, a0_.user_id AS user_id_5 FROM Avatar a0_) dctrn_result_inner ORDER BY imageAltDesc_4 DESC) dctrn_result',
            'SELECT a FROM Doctrine\Tests\ORM\Tools\Pagination\Avatar a ORDER BY a.imageAltDesc DESC',
        );
    }

    public function testLimitSubqueryWithOrderByInnerJoined(): void
    {
        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, name_2 FROM (SELECT b0_.id AS id_0, a1_.id AS id_1, a1_.name AS name_2, b0_.author_id AS author_id_3, b0_.category_id AS category_id_4 FROM BlogPost b0_ INNER JOIN Author a1_ ON b0_.author_id = a1_.id) dctrn_result_inner ORDER BY name_2 ASC) dctrn_result',
            'SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b JOIN b.author a ORDER BY a.name ASC',
        );
    }

    public function testLimitSubqueryWithOrderByAndSubSelectInWhereClauseMySql(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0 FROM (SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ WHERE ((SELECT COUNT(b1_.id) AS sclr_3 FROM BlogPost b1_) = 1)) dctrn_result_inner ORDER BY id_0 DESC) dctrn_result',
            'SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b WHERE  ((SELECT COUNT(simple.id) FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost simple) = 1) ORDER BY b.id DESC',
        );
    }

    public function testLimitSubqueryWithOrderByAndSubSelectInWhereClausePgSql(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0, MIN(sclr_1) AS dctrn_minrownum FROM (SELECT b0_.id AS id_0, ROW_NUMBER() OVER(ORDER BY b0_.id DESC) AS sclr_1, b0_.author_id AS author_id_2, b0_.category_id AS category_id_3 FROM BlogPost b0_ WHERE ((SELECT COUNT(b1_.id) AS sclr_4 FROM BlogPost b1_) = 1)) dctrn_result GROUP BY id_0 ORDER BY dctrn_minrownum ASC',
            'SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b WHERE  ((SELECT COUNT(simple.id) FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost simple) = 1) ORDER BY b.id DESC',
        );
    }

    /**
     * Path expressions of a subselect that follow a nested subselect must not be
     * added to the SELECT clause as if they were ORDER BY items of the main query.
     */
    #[Group('GH12697')]
    public function testLimitSubqueryWithOrderByAndNestedSubSelectInWhereClauseMySql(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, name_2 FROM (SELECT b0_.id AS id_0, a1_.id AS id_1, a1_.name AS name_2, b0_.author_id AS author_id_3, b0_.category_id AS category_id_4 FROM BlogPost b0_ INNER JOIN Author a1_ ON b0_.author_id = a1_.id WHERE b0_.id IN (SELECT b2_.id FROM BlogPost b2_ INNER JOIN Author a3_ ON b2_.author_id = a3_.id WHERE NOT EXISTS (SELECT 1 AS sclr_5 FROM BlogPost b4_ WHERE b4_.author_id = a3_.id AND b4_.id > b2_.id) AND a3_.name = ?)) dctrn_result_inner ORDER BY name_2 ASC) dctrn_result',
            'SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b JOIN b.author a WHERE b IN (SELECT b2 FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b2 JOIN b2.author a2 WHERE NOT EXISTS (SELECT 1 FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost b3 WHERE b3.author = a2 AND b3.id > b2.id) AND a2.name = :name) ORDER BY a.name ASC',
        );
    }

    /**
     * This tests ordering by property that has the 'declared' field.
     */
    public function testLimitSubqueryOrderByFieldFromMappedSuperclass(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0 FROM (SELECT b0_.id AS id_0, b0_.name AS name_1 FROM Banner b0_) dctrn_result_inner ORDER BY id_0 DESC) dctrn_result',
            'SELECT b FROM Doctrine\Tests\ORM\Tools\Pagination\Banner b ORDER BY b.id DESC',
        );
    }

    /**
     * Tests order by on a subselect expression (mysql).
     */
    public function testLimitSubqueryOrderBySubSelectOrderByExpression(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new MySQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0 FROM (SELECT DISTINCT id_0, sclr_2 FROM (SELECT a0_.id AS id_0, a0_.name AS name_1, (SELECT MIN(m1_.title) AS sclr_3 FROM MyBlogPost m1_ WHERE m1_.author_id = a0_.id) AS sclr_2 FROM Author a0_) dctrn_result_inner ORDER BY sclr_2 DESC) dctrn_result',
            'SELECT a, ( SELECT MIN(bp.title) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost bp WHERE bp.author = a ) AS HIDDEN first_blog_post FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY first_blog_post DESC',
        );
    }

    /**
     * Tests order by on a subselect expression invoking RowNumberOverFunction (postgres).
     */
    public function testLimitSubqueryOrderBySubSelectOrderByExpressionPg(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new PostgreSQLPlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT id_0, MIN(sclr_4) AS dctrn_minrownum FROM (SELECT a0_.id AS id_0, a0_.name AS name_1, (SELECT MIN(m1_.title) AS sclr_3 FROM MyBlogPost m1_ WHERE m1_.author_id = a0_.id) AS sclr_2, ROW_NUMBER() OVER(ORDER BY (SELECT MIN(m1_.title) AS sclr_5 FROM MyBlogPost m1_ WHERE m1_.author_id = a0_.id) DESC) AS sclr_4 FROM Author a0_) dctrn_result GROUP BY id_0 ORDER BY dctrn_minrownum ASC',
            'SELECT a, ( SELECT MIN(bp.title) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost bp WHERE bp.author = a ) AS HIDDEN first_blog_post FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY first_blog_post DESC',
        );
    }

    /**
     * Tests order by on a subselect expression invoking RowNumberOverFunction (oracle).
     */
    public function testLimitSubqueryOrderBySubSelectOrderByExpressionOracle(): void
    {
        $this->entityManager = $this->createTestEntityManagerWithPlatform(new OraclePlatform());

        $this->assertQuerySql(
            'SELECT DISTINCT ID_0, MIN(SCLR_4) AS dctrn_minrownum FROM (SELECT a0_.id AS ID_0, a0_.name AS NAME_1, (SELECT MIN(m1_.title) AS SCLR_3 FROM MyBlogPost m1_ WHERE m1_.author_id = a0_.id) AS SCLR_2, ROW_NUMBER() OVER(ORDER BY (SELECT MIN(m1_.title) AS SCLR_5 FROM MyBlogPost m1_ WHERE m1_.author_id = a0_.id) DESC) AS SCLR_4 FROM Author a0_) dctrn_result GROUP BY ID_0 ORDER BY dctrn_minrownum ASC',
            'SELECT a, ( SELECT MIN(bp.title) FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost bp WHERE bp.author = a ) AS HIDDEN first_blog_post FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY first_blog_post DESC',
        );
    }

    public function testParsingQueryWithDifferentLimitOffsetValuesTakesOnlyOneCacheEntry(): void
    {
        $queryCache = new ArrayAdapter();
        $this->entityManager->getConfiguration()->setQueryCache($queryCache);

        $query = $this->createQuery('SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a');

        self::assertSame(
            'SELECT DISTINCT id_0 FROM (SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id) dctrn_result LIMIT 20 OFFSET 10',
            $query->getSQL(),
        );

        $query->setFirstResult(30)->setMaxResults(40);

        self::assertSame(
            'SELECT DISTINCT id_0 FROM (SELECT m0_.id AS id_0, m0_.title AS title_1, c1_.id AS id_2, a2_.id AS id_3, a2_.name AS name_4, m0_.author_id AS author_id_5, m0_.category_id AS category_id_6 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id) dctrn_result LIMIT 40 OFFSET 30',
            $query->getSQL(),
        );

        self::assertCount(1, $queryCache->getValues());
    }

    private function createQuery(string $dql): Query
    {
        $query = $this->entityManager->createQuery($dql);
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, LimitSubqueryOutputWalker::class);
        $query->setFirstResult(10);
        $query->setMaxResults(20);

        return $query;
    }

    private function assertQuerySql(string $expectedSql, string $dql): void
    {
        $sql   = $this->entityManager->getConnection()->getDatabasePlatform()->modifyLimitQuery($expectedSql, 20, 10);
        $query = $this->createQuery($dql);

        self::assertSame($sql, $query->getSQL());
    }
}
