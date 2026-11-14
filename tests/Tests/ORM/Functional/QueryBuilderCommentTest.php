<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function count;
use function str_starts_with;

class QueryBuilderCommentTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('company');

        parent::setUp();

        foreach (['Roman' => 'IT', 'Jonathan' => 'IT', 'Benjamin' => 'Sales'] as $name => $department) {
            $employee = new CompanyEmployee();
            $employee->setName($name);
            $employee->setSalary(100000);
            $employee->setDepartment($department);
            $this->_em->persist($employee);
        }

        $this->_em->flush();
        $this->_em->clear();
    }

    public function testCommentedSelectIsUnderstoodByThePlatform(): void
    {
        $this->getQueryLog()->reset()->enable();

        $result = $this->_em->createQueryBuilder()
            ->select('e.name')
            ->from(CompanyEmployee::class, 'e')
            ->where('e.department = :department')
            ->orderBy('e.name')
            ->setParameter('department', 'IT')
            ->setMaxResults(10)
            ->addComment('a comment')
            ->getQuery()
            ->getSingleColumnResult();

        self::assertSame(['Jonathan', 'Roman'], $result);
        self::assertStringStartsWith("-- a comment\n\n", $this->getLastLoggedQuery()['sql']);
    }

    /**
     * Updating a class of a joined inheritance hierarchy runs several statements: each one carries the comments.
     */
    public function testCommentedMultiTableUpdateIsUnderstoodByThePlatform(): void
    {
        $this->getQueryLog()->reset()->enable();

        $affected = $this->_em->createQueryBuilder()
            ->update(CompanyEmployee::class, 'e')
            ->set('e.salary', ':salary')
            ->where('e.department = :department')
            ->setParameter('salary', 1)
            ->setParameter('department', 'IT')
            ->addComment('a comment')
            ->getQuery()
            ->execute();

        self::assertSame(2, $affected);
        $this->assertEveryLoggedQueryIsCommented();
        self::assertSame(2, $this->countEmployees('e.salary = 1'));
    }

    public function testCommentedMultiTableDeleteIsUnderstoodByThePlatform(): void
    {
        $this->getQueryLog()->reset()->enable();

        $affected = $this->_em->createQueryBuilder()
            ->delete(CompanyEmployee::class, 'e')
            ->where('e.department = :department')
            ->setParameter('department', 'IT')
            ->addComment('a comment')
            ->getQuery()
            ->execute();

        self::assertSame(2, $affected);
        self::assertSame(1, $this->countEmployees('1 = 1'));
    }

    /**
     * A line terminator inside a comment must not let its remainder escape into the query, otherwise
     * the comment would be a way to inject arbitrary SQL.
     */
    #[DataProvider('lineTerminatorProvider')]
    public function testCommentCannotEscapeIntoTheQuery(string $lineTerminator): void
    {
        $result = $this->_em->createQueryBuilder()
            ->select('e.name')
            ->from(CompanyEmployee::class, 'e')
            ->orderBy('e.name')
            ->addComment('a comment' . $lineTerminator . "AND e.department = 'Sales'")
            ->getQuery()
            ->getSingleColumnResult();

        self::assertSame(['Benjamin', 'Jonathan', 'Roman'], $result);
    }

    /** @return iterable<string, array{string}> */
    public static function lineTerminatorProvider(): iterable
    {
        yield 'line feed' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'carriage return and line feed' => ["\r\n"];
    }

    public function testQueryCacheKeepsCommentsApart(): void
    {
        $cache = new ArrayAdapter();
        $this->getQueryLog()->reset()->enable();

        foreach (['first', 'second'] as $comment) {
            $this->_em->createQueryBuilder()
                ->update(CompanyEmployee::class, 'e')
                ->set('e.salary', ':salary')
                ->setParameter('salary', 1)
                ->addComment($comment)
                ->getQuery()
                ->setQueryCache($cache)
                ->execute();
        }

        $this->assertSomeLoggedQueryStartsWith("-- first\n\n");
        $this->assertSomeLoggedQueryStartsWith("-- second\n\n");
    }

    public function testPaginatorQueriesCarryTheComments(): void
    {
        $query = $this->_em->createQueryBuilder()
            ->select('e')
            ->from(CompanyEmployee::class, 'e')
            ->orderBy('e.name')
            ->setMaxResults(2)
            ->addComment('a comment')
            ->getQuery();

        $this->getQueryLog()->reset()->enable();

        $paginator = new Paginator($query);

        self::assertCount(3, $paginator);
        self::assertSame(2, count($paginator->getIterator()));
        $this->assertEveryLoggedQueryIsCommented();
    }

    private function countEmployees(string $condition): int
    {
        return (int) $this->_em->createQuery('SELECT COUNT(e) FROM ' . CompanyEmployee::class . ' e WHERE ' . $condition)
            ->getSingleScalarResult();
    }

    private function assertEveryLoggedQueryIsCommented(): void
    {
        $queries = $this->getQueryLog()->queries;

        self::assertNotEmpty($queries);

        foreach ($queries as $query) {
            self::assertStringStartsWith("-- a comment\n\n", $query['sql']);
        }
    }

    private function assertSomeLoggedQueryStartsWith(string $prefix): void
    {
        foreach ($this->getQueryLog()->queries as $query) {
            if (str_starts_with($query['sql'], $prefix)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        self::fail('No logged query starts with ' . $prefix);
    }
}
