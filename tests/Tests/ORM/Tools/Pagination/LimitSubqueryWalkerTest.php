<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\LimitSubqueryWalker;
use Doctrine\ORM\Tools\Pagination\PaginatorInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('DDC-1613')]
class LimitSubqueryWalkerTest extends PaginationTestCase
{
    public function testLimitSubquery(): void
    {
        $dql        = 'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertEquals(
            'SELECT DISTINCT m0_.id AS id_0 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id',
            $limitQuery->getSQL(),
        );
    }

    public function testHintCanDisableDistinct(): void
    {
        $dql        = 'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);
        $limitQuery->setHint(PaginatorInterface::HINT_ENABLE_DISTINCT, false);

        self::assertEquals(
            'SELECT m0_.id AS id_0 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id',
            $limitQuery->getSQL(),
        );
    }

    public function testLimitSubqueryWithSort(): void
    {
        $dql        = 'SELECT p, c, a FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c JOIN p.author a ORDER BY p.title';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertEquals(
            'SELECT DISTINCT m0_.id AS id_0, m0_.title AS title_1 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id INNER JOIN Author a2_ ON m0_.author_id = a2_.id ORDER BY m0_.title ASC',
            $limitQuery->getSQL(),
        );
    }

    public function testLimitSubqueryWithSortFunction(): void
    {
        $dql   = 'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN p.category c GROUP BY p.id ORDER BY COUNT(c.id)';
        $query = $this->entityManager->createQuery($dql);

        $limitQuery = clone $query;
        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertSame(
            'SELECT DISTINCT m0_.id AS id_0, COUNT(c1_.id) AS sclr_1 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON m0_.category_id = c1_.id GROUP BY m0_.id ORDER BY COUNT(c1_.id) ASC',
            $limitQuery->getSQL(),
        );
    }

    public function testLimitSubqueryRejectsExpressionReferencingToManyAssociation(): void
    {
        $dql   = 'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY LENGTH(g.id)';
        $query = $this->entityManager->createQuery($dql);
        $query->setMaxResults(10);
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot select distinct identifiers from query with LIMIT and ORDER BY on an expression referencing a fetch joined to-many association. Use output walkers.');

        $query->getSQL();
    }

    public function testLimitSubqueryAllowsExpressionReferencingRootWithToManyJoin(): void
    {
        $dql   = 'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY LENGTH(u.id)';
        $query = $this->entityManager->createQuery($dql);
        $query->setMaxResults(10);
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertStringContainsString('LENGTH(', $query->getSQL());
    }

    public function testCountQueryMixedResultsWithName(): void
    {
        $dql        = 'SELECT a, sum(a.name) as foo FROM Doctrine\Tests\ORM\Tools\Pagination\Author a';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertEquals(
            'SELECT DISTINCT a0_.id AS id_0 FROM Author a0_',
            $limitQuery->getSQL(),
        );
    }

    public function testAggQueryMixedResultsWithNameAndSort(): void
    {
        $dql   = 'SELECT a, sum(a.name) as foo FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY foo DESC';
        $query = $this->entityManager->createQuery($dql);

        $limitQuery = clone $query;
        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertSame(
            'SELECT DISTINCT a0_.id AS id_0, sum(a0_.name) AS sclr_1 FROM Author a0_ ORDER BY sclr_1 DESC',
            $limitQuery->getSQL(),
        );
    }

    public function testAggQueryMultipleMixedResultsWithSort(): void
    {
        $dql   = 'SELECT a, sum(a.name) as foo, (SELECT count(subA.id) FROM Doctrine\Tests\ORM\Tools\Pagination\Author subA WHERE subA.id = a.id ) as bar FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY foo DESC, bar ASC';
        $query = $this->entityManager->createQuery($dql);

        $limitQuery = clone $query;
        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertSame(
            'SELECT DISTINCT a0_.id AS id_0, sum(a0_.name) AS sclr_1, (SELECT count(a1_.id) AS sclr_3 FROM Author a1_ WHERE a1_.id = a0_.id) AS sclr_2 FROM Author a0_ ORDER BY sclr_1 DESC, sclr_2 ASC',
            $limitQuery->getSQL(),
        );
    }

    #[Group('DDC-2890')]
    public function testLimitSubqueryWithSortOnAssociation(): void
    {
        $dql        = 'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p ORDER BY p.author';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertEquals(
            'SELECT DISTINCT m0_.id AS id_0, m0_.author_id AS sclr_1 FROM MyBlogPost m0_ ORDER BY m0_.author_id ASC',
            $limitQuery->getSQL(),
        );
    }

    /**
     * Arbitrary Join
     */
    public function testLimitSubqueryWithArbitraryJoin(): void
    {
        $dql        = 'SELECT p, c FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN Doctrine\Tests\ORM\Tools\Pagination\Category c ON p.category = c';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertEquals(
            'SELECT DISTINCT m0_.id AS id_0 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON (m0_.category_id = c1_.id)',
            $limitQuery->getSQL(),
        );
    }

    public function testLimitSubqueryWithSortWithArbitraryJoin(): void
    {
        $dql        = 'SELECT p, c FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p JOIN Doctrine\Tests\ORM\Tools\Pagination\Category c ON p.category = c ORDER BY p.title';
        $query      = $this->entityManager->createQuery($dql);
        $limitQuery = clone $query;

        $limitQuery->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [LimitSubqueryWalker::class]);

        self::assertEquals(
            'SELECT DISTINCT m0_.id AS id_0, m0_.title AS title_1 FROM MyBlogPost m0_ INNER JOIN Category c1_ ON (m0_.category_id = c1_.id) ORDER BY m0_.title ASC',
            $limitQuery->getSQL(),
        );
    }
}
