<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Tools\Pagination\CursorWalker;
use LogicException;

class CursorWalkerTest extends PaginationTestCase
{
    public function testThrowsExceptionWithoutOrderBy(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No ORDER BY clause found. Cursor pagination requires a deterministic sort order.');

        $query->getSQL();
    }

    public function testBasicQueryWithOrderBy(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, []);

        self::assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ ORDER BY b0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testQueryWithReversedOrderBy(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, true);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, []);

        self::assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ ORDER BY b0_.id DESC',
            $query->getSQL(),
        );
    }

    public function testQueryWithMultipleOrderByColumnsReversed(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p ORDER BY p.title ASC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, true);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, []);

        self::assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, m0_.author_id AS author_id_2, m0_.category_id AS category_id_3 FROM MyBlogPost m0_ ORDER BY m0_.title DESC, m0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionSingleColumnAsc(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.id' => 10]);

        self::assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ WHERE (b0_.id > ?) ORDER BY b0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionSingleColumnDesc(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p ORDER BY p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.id' => 10]);

        self::assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ WHERE (b0_.id < ?) ORDER BY b0_.id DESC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionWithExistingWhere(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p WHERE p.id > 5 ORDER BY p.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.id' => 10]);

        self::assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ WHERE b0_.id > 5 AND (b0_.id > ?) ORDER BY b0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionDoesNotOverwriteAParameterOfThePaginatedQuery(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p WHERE p.id <= :p_id_0 ORDER BY p.id ASC',
        );
        $query->setParameter('p_id_0', 5);
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.id' => 10]);

        self::assertEquals(
            'SELECT b0_.id AS id_0, b0_.author_id AS author_id_1, b0_.category_id AS category_id_2 FROM BlogPost b0_ WHERE b0_.id <= ? AND (b0_.id > ?) ORDER BY b0_.id ASC',
            $query->getSQL(),
        );

        self::assertSame(5, $query->getParameter('p_id_0')?->getValue());
        self::assertSame(10, $query->getParameter('dctrn_cursor_0')?->getValue());
    }

    public function testQueryWithJoinAndOrderByJoinedEntity(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p, a FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p JOIN p.author a ORDER BY a.name ASC, p.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, []);

        self::assertEquals(
            'SELECT b0_.id AS id_0, a1_.id AS id_1, a1_.name AS name_2, b0_.author_id AS author_id_3, b0_.category_id AS category_id_4 FROM BlogPost b0_ INNER JOIN Author a1_ ON b0_.author_id = a1_.id ORDER BY a1_.name ASC, b0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionMultipleColumnsDesc(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p ORDER BY p.title DESC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.title' => 'Test', 'p.id' => 10]);

        self::assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, m0_.author_id AS author_id_2, m0_.category_id AS category_id_3 FROM MyBlogPost m0_ WHERE (m0_.title <= ? AND (m0_.title < ? OR (m0_.title = ? AND (m0_.id < ?)))) ORDER BY m0_.title DESC, m0_.id DESC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionMultipleColumnsAsc(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT a FROM Doctrine\Tests\ORM\Tools\Pagination\Author a ORDER BY a.name ASC, a.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['a.name' => 'John', 'a.id' => 5]);

        self::assertEquals(
            'SELECT a0_.id AS id_0, a0_.name AS name_1 FROM Author a0_ WHERE (a0_.name >= ? AND (a0_.name > ? OR (a0_.name = ? AND (a0_.id > ?)))) ORDER BY a0_.name ASC, a0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionThreeColumnsDesc(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\Person p ORDER BY p.biography DESC, p.name DESC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, [
            'p.biography' => '2019-11-04',
            'p.name' => 'test@example.com',
            'p.id' => 529,
        ]);

        self::assertEquals(
            'SELECT p0_.id AS id_0, p0_.name AS name_1, p0_.biography AS biography_2 FROM Person p0_ WHERE (p0_.biography <= ? AND (p0_.biography < ? OR (p0_.biography = ? AND (p0_.name < ? OR (p0_.name = ? AND (p0_.id < ?)))))) ORDER BY p0_.biography DESC, p0_.name DESC, p0_.id DESC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionMultipleColumnsMixedDirections(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p ORDER BY p.title ASC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.title' => 'Test', 'p.id' => 10]);

        self::assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, m0_.author_id AS author_id_2, m0_.category_id AS category_id_3 FROM MyBlogPost m0_ WHERE (m0_.title >= ? AND (m0_.title > ? OR (m0_.title = ? AND (m0_.id < ?)))) ORDER BY m0_.title ASC, m0_.id DESC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionMultipleColumnsReversed(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p ORDER BY p.title DESC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, true);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.title' => 'Test', 'p.id' => 10]);

        self::assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, m0_.author_id AS author_id_2, m0_.category_id AS category_id_3 FROM MyBlogPost m0_ WHERE (m0_.title >= ? AND (m0_.title > ? OR (m0_.title = ? AND (m0_.id > ?)))) ORDER BY m0_.title ASC, m0_.id ASC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionMultipleColumnsWithExistingWhere(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p WHERE p.id > 5 ORDER BY p.title DESC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.title' => 'Test', 'p.id' => 10]);

        self::assertEquals(
            'SELECT m0_.id AS id_0, m0_.title AS title_1, m0_.author_id AS author_id_2, m0_.category_id AS category_id_3 FROM MyBlogPost m0_ WHERE m0_.id > 5 AND (m0_.title <= ? AND (m0_.title < ? OR (m0_.title = ? AND (m0_.id < ?)))) ORDER BY m0_.title DESC, m0_.id DESC',
            $query->getSQL(),
        );
    }

    public function testCursorConditionLeadingBoundReusesTheParameterOfTheFirstColumn(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p FROM Doctrine\Tests\ORM\Tools\Pagination\MyBlogPost p ORDER BY p.title DESC, p.id DESC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, ['p.title' => 'Test', 'p.id' => 10]);

        $result = (new Parser($query))->parse();

        // m0_.title <= ? AND (m0_.title < ? OR (m0_.title = ? AND (m0_.id < ?)))
        self::assertSame([0, 1, 2], $result->getSqlParameterPositions('dctrn_cursor_0'));
        self::assertSame([3], $result->getSqlParameterPositions('dctrn_cursor_1'));
        self::assertSame('Test', $query->getParameter('dctrn_cursor_0')?->getValue());
        self::assertSame(10, $query->getParameter('dctrn_cursor_1')?->getValue());
    }

    public function testNoExceptionWithToManyJoinWhenQueryProducesDuplicatesIsTrue(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT u, g FROM Doctrine\Tests\ORM\Tools\Pagination\User u JOIN u.groups g ORDER BY u.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, []);
        $query->setHint(CursorWalker::HINT_QUERY_PRODUCES_DUPLICATES, true);

        $sql = $query->getSQL();
        self::assertIsString($sql);
        self::assertStringContainsString('JOIN', $sql);
    }

    public function testNoExceptionWithToOneJoinWhenQueryProducesDuplicatesIsFalse(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT p, a FROM Doctrine\Tests\ORM\Tools\Pagination\BlogPost p JOIN p.author a ORDER BY a.name ASC, p.id ASC',
        );
        $query->setHint(Query::HINT_CUSTOM_TREE_WALKERS, [CursorWalker::class]);
        $query->setHint(CursorWalker::HINT_CURSOR_REVERSE, false);
        $query->setHint(CursorWalker::HINT_CURSOR_PARAMETERS, []);
        $query->setHint(CursorWalker::HINT_QUERY_PRODUCES_DUPLICATES, false);

        $sql = $query->getSQL();
        self::assertIsString($sql);
        self::assertStringContainsString('JOIN', $sql);
    }
}
