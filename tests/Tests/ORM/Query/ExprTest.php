<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\Query\Expr\From;
use Doctrine\ORM\Query\Expr\Func;
use Doctrine\ORM\Query\Expr\GroupBy;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\Literal;
use Doctrine\ORM\Query\Expr\Math;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\Query\Expr\Select;
use Doctrine\Tests\Models\Company\CompanyEmployee;
use Doctrine\Tests\OrmTestCase;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SortDirection;

/**
 * Test case for the DQL Expr class used for generating DQL snippets through
 * a programmatic interface
 *
 * @link        http://www.phpdoctrine.org
 */
class ExprTest extends OrmTestCase
{
    use VerifyDeprecations;

    private EntityManagerInterface $entityManager;

    private Expr $expr;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
        $this->expr          = new Expr();
    }

    public function testAvgExpr(): void
    {
        self::assertEquals('AVG(u.id)', (string) $this->expr->avg('u.id'));
    }

    public function testMaxExpr(): void
    {
        self::assertEquals('MAX(u.id)', (string) $this->expr->max('u.id'));
    }

    public function testMinExpr(): void
    {
        self::assertEquals('MIN(u.id)', (string) $this->expr->min('u.id'));
    }

    public function testCountExpr(): void
    {
        self::assertEquals('MAX(u.id)', (string) $this->expr->max('u.id'));
    }

    public function testCountDistinctExpr(): void
    {
        self::assertEquals('COUNT(DISTINCT u.id)', (string) $this->expr->countDistinct('u.id'));
    }

    public function testCountDistinctExprMulti(): void
    {
        self::assertEquals('COUNT(DISTINCT u.id, u.name)', (string) $this->expr->countDistinct('u.id', 'u.name'));
    }

    public function testExistsExpr(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')->from('User', 'u')->where('u.name = ?1');

        self::assertEquals('EXISTS(SELECT u FROM User u WHERE u.name = ?1)', (string) $this->expr->exists($qb));
    }

    public function testAllExpr(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')->from('User', 'u')->where('u.name = ?1');

        self::assertEquals('ALL(SELECT u FROM User u WHERE u.name = ?1)', (string) $this->expr->all($qb));
    }

    public function testSomeExpr(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')->from('User', 'u')->where('u.name = ?1');

        self::assertEquals('SOME(SELECT u FROM User u WHERE u.name = ?1)', (string) $this->expr->some($qb));
    }

    public function testAnyExpr(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')->from('User', 'u')->where('u.name = ?1');

        self::assertEquals('ANY(SELECT u FROM User u WHERE u.name = ?1)', (string) $this->expr->any($qb));
    }

    public function testNotExpr(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')->from('User', 'u')->where('u.name = ?1');

        self::assertEquals('NOT(SELECT u FROM User u WHERE u.name = ?1)', (string) $this->expr->not($qb));
    }

    public function testAndExpr(): void
    {
        self::assertEquals('1 = 1 AND 2 = 2', (string) $this->expr->andX((string) $this->expr->eq(1, 1), (string) $this->expr->eq(2, 2)));
    }

    public function testIntelligentParenthesisPreventionAndExpr(): void
    {
        self::assertEquals(
            '1 = 1 AND 2 = 2',
            (string) $this->expr->andX($this->expr->orX($this->expr->andX($this->expr->eq(1, 1))), (string) $this->expr->eq(2, 2)),
        );
    }

    public function testOrExpr(): void
    {
        self::assertEquals('1 = 1 OR 2 = 2', (string) $this->expr->orX((string) $this->expr->eq(1, 1), (string) $this->expr->eq(2, 2)));
    }

    public function testAbsExpr(): void
    {
        self::assertEquals('ABS(1)', (string) $this->expr->abs(1));
    }

    public function testProdExpr(): void
    {
        self::assertEquals('1 * 2', (string) $this->expr->prod(1, 2));
    }

    public function testDiffExpr(): void
    {
        self::assertEquals('1 - 2', (string) $this->expr->diff(1, 2));
    }

    public function testSumExpr(): void
    {
        self::assertEquals('1 + 2', (string) $this->expr->sum(1, 2));
    }

    public function testQuotientExpr(): void
    {
        self::assertEquals('10 / 2', (string) $this->expr->quot(10, 2));
    }

    public function testScopeInArithmeticExpr(): void
    {
        self::assertEquals('(100 - 20) / 2', (string) $this->expr->quot($this->expr->diff(100, 20), 2));
        self::assertEquals('100 - (20 / 2)', (string) $this->expr->diff(100, $this->expr->quot(20, 2)));
    }

    public function testSquareRootExpr(): void
    {
        self::assertEquals('SQRT(1)', (string) $this->expr->sqrt(1));
    }

    public function testEqualExpr(): void
    {
        self::assertEquals('1 = 1', (string) $this->expr->eq(1, 1));
    }

    public function testLikeExpr(): void
    {
        self::assertEquals('a.description LIKE :description', (string) $this->expr->like('a.description', ':description'));
    }

    public function testNotLikeExpr(): void
    {
        self::assertEquals('a.description NOT LIKE :description', (string) $this->expr->notLike('a.description', ':description'));
    }

    public function testConcatExpr(): void
    {
        self::assertEquals('CONCAT(u.first_name, u.last_name)', (string) $this->expr->concat('u.first_name', 'u.last_name'));
        self::assertEquals('CONCAT(u.first_name, u.middle_name, u.last_name)', (string) $this->expr->concat('u.first_name', 'u.middle_name', 'u.last_name'));
    }

    public function testSubstringExpr(): void
    {
        self::assertEquals('SUBSTRING(a.title, 0, 25)', (string) $this->expr->substring('a.title', 0, 25));
    }

    public function testModExpr(): void
    {
        self::assertEquals('MOD(10, 1)', (string) $this->expr->mod(10, 1));
    }

    #[Group('regression')]
    #[Group('DDC-612')]
    public function testSubstringExprAcceptsTwoArguments(): void
    {
        self::assertEquals('SUBSTRING(a.title, 5)', (string) $this->expr->substring('a.title', 5));
    }

    public function testLowerExpr(): void
    {
        self::assertEquals('LOWER(u.first_name)', (string) $this->expr->lower('u.first_name'));
    }

    public function testUpperExpr(): void
    {
        self::assertEquals('UPPER(u.first_name)', (string) $this->expr->upper('u.first_name'));
    }

    public function testLengthExpr(): void
    {
        self::assertEquals('LENGTH(u.first_name)', (string) $this->expr->length('u.first_name'));
    }

    public function testGreaterThanExpr(): void
    {
        self::assertEquals('5 > 2', (string) $this->expr->gt(5, 2));
    }

    public function testLessThanExpr(): void
    {
        self::assertEquals('2 < 5', (string) $this->expr->lt(2, 5));
    }

    public function testStringLiteralExpr(): void
    {
        self::assertEquals("'word'", (string) $this->expr->literal('word'));
    }

    public function testNumericLiteralExpr(): void
    {
        self::assertEquals(5, (string) $this->expr->literal(5));
    }

    #[Group('regression')]
    #[Group('DDC-610')]
    public function testLiteralExprProperlyQuotesStrings(): void
    {
        self::assertEquals("'00010001'", (string) $this->expr->literal('00010001'));
    }

    public function testGreaterThanOrEqualToExpr(): void
    {
        self::assertEquals('5 >= 2', (string) $this->expr->gte(5, 2));
    }

    public function testLessThanOrEqualTo(): void
    {
        self::assertEquals('2 <= 5', (string) $this->expr->lte(2, 5));
    }

    public function testBetweenExpr(): void
    {
        self::assertEquals('u.id BETWEEN 3 AND 6', (string) $this->expr->between('u.id', 3, 6));
    }

    public function testTrimExpr(): void
    {
        self::assertEquals('TRIM(u.id)', (string) $this->expr->trim('u.id'));
    }

    public function testIsNullExpr(): void
    {
        self::assertEquals('u.id IS NULL', (string) $this->expr->isNull('u.id'));
    }

    public function testIsNotNullExpr(): void
    {
        self::assertEquals('u.id IS NOT NULL', (string) $this->expr->isNotNull('u.id'));
    }

    public function testIsInstanceOfExpr(): void
    {
        self::assertEquals('u INSTANCE OF Doctrine\Tests\Models\Company\CompanyEmployee', (string) $this->expr->isInstanceOf('u', CompanyEmployee::class));
    }

    public function testIsMemberOfExpr(): void
    {
        self::assertEquals(':groupId MEMBER OF u.groups', (string) $this->expr->isMemberOf(':groupId', 'u.groups'));
    }

    public static function provideIterableValue(): Generator
    {
        $gen = static function () {
            yield from [1, 2, 3];
        };

        yield 'simple_array' => [[1, 2, 3]];
        yield 'generator' => [$gen()];
    }

    public static function provideLiteralIterableValue(): Generator
    {
        $gen = static function () {
            yield from ['foo', 'bar'];
        };

        yield 'simple_array' => [['foo', 'bar']];
        yield 'generator' => [$gen()];
    }

    #[DataProvider('provideIterableValue')]
    public function testInExpr(iterable $value): void
    {
        self::assertEquals('u.id IN(1, 2, 3)', (string) $this->expr->in('u.id', $value));
    }

    #[DataProvider('provideLiteralIterableValue')]
    public function testInLiteralExpr(iterable $value): void
    {
        self::assertEquals("u.type IN('foo', 'bar')", (string) $this->expr->in('u.type', $value));
    }

    #[DataProvider('provideIterableValue')]
    public function testNotInExpr(iterable $value): void
    {
        self::assertEquals('u.id NOT IN(1, 2, 3)', (string) $this->expr->notIn('u.id', $value));
    }

    #[DataProvider('provideLiteralIterableValue')]
    public function testNotInLiteralExpr(iterable $value): void
    {
        self::assertEquals("u.type NOT IN('foo', 'bar')", (string) $this->expr->notIn('u.type', $value));
    }

    public function testAndxOrxExpr(): void
    {
        $andExpr = $this->expr->andX();
        $andExpr->add($this->expr->eq(1, 1));
        $andExpr->add($this->expr->lt(1, 5));

        $orExpr = $this->expr->orX();
        $orExpr->add($andExpr);
        $orExpr->add($this->expr->eq(1, 1));

        self::assertEquals('(1 = 1 AND 1 < 5) OR 1 = 1', (string) $orExpr);
    }

    public function testOrxExpr(): void
    {
        $orExpr = $this->expr->orX();
        $orExpr->add($this->expr->eq(1, 1));
        $orExpr->add($this->expr->lt(1, 5));

        self::assertEquals('1 = 1 OR 1 < 5', (string) $orExpr);
    }

    public function testOrderByCountExpr(): void
    {
        $orderExpr = $this->expr->desc('u.username');

        self::assertEquals($orderExpr->count(), 1);
        self::assertEquals('u.username DESC', (string) $orderExpr);
    }

    public function testOrderByOrder(): void
    {
        $orderExpr = $this->expr->desc('u.username');
        self::assertEquals('u.username DESC', (string) $orderExpr);
    }

    public function testOrderByAsc(): void
    {
        $orderExpr = $this->expr->asc('u.username');
        self::assertEquals('u.username ASC', (string) $orderExpr);
    }

    public function testAddThrowsException(): void
    {
        $orExpr = $this->expr->orX();
        $this->expectException(InvalidArgumentException::class);
        $orExpr->add($this->expr->quot(5, 2));
    }

    #[DataProvider('provideInvalidTypesForAdd')]
    public function testAddThrowsExceptionOnInvalidType(mixed $arg): void
    {
        $orExpr = $this->expr->orX();
        $this->expectException(InvalidArgumentException::class);
        $orExpr->add($arg);
    }

    /** @return Generator<string, array{mixed}> */
    public static function provideInvalidTypesForAdd(): Generator
    {
        yield 'integer 1' => [1];
        yield 'object' => [(object) ['foo' => 'bar']];
        yield 'array' => [['foo' => 'bar']];
    }

    #[Group('DDC-1683')]
    public function testBooleanLiteral(): void
    {
        self::assertEquals('true', $this->expr->literal(true));
        self::assertEquals('false', $this->expr->literal(false));
    }

    #[Group('DDC-1686')]
    public function testExpressionGetter(): void
    {
        // Andx
        $andx = new Andx(['1 = 1', '2 = 2']);
        self::assertEquals(['1 = 1', '2 = 2'], $andx->getParts());

        // Comparison
        $comparison = new Comparison('foo', Comparison::EQ, 'bar');
        self::assertEquals('foo', $comparison->getLeftExpr());
        self::assertEquals('bar', $comparison->getRightExpr());
        self::assertEquals(Comparison::EQ, $comparison->getOperator());

        // From
        $from = new From('Foo', 'f', 'f.id');
        self::assertEquals('f', $from->getAlias());
        self::assertEquals('Foo', $from->getFrom());
        self::assertEquals('f.id', $from->getIndexBy());

        // Func
        $func = new Func('MAX', ['f.id']);
        self::assertEquals('MAX', $func->getName());
        self::assertEquals(['f.id'], $func->getArguments());

        // GroupBy
        $group = new GroupBy(['foo DESC', 'bar ASC']);
        self::assertEquals(['foo DESC', 'bar ASC'], $group->getParts());

        // Join
        $join = new Join(Join::INNER_JOIN, 'f.bar', 'b', Join::WITH, 'b.bar_id = 1', 'b.bar_id');
        self::assertEquals(Join::INNER_JOIN, $join->getJoinType());
        self::assertEquals(Join::WITH, $join->getConditionType());
        self::assertEquals('b.bar_id = 1', $join->getCondition());
        self::assertEquals('b.bar_id', $join->getIndexBy());
        self::assertEquals('f.bar', $join->getJoin());
        self::assertEquals('b', $join->getAlias());

        // Literal
        $literal = new Literal(['foo']);
        self::assertEquals(['foo'], $literal->getParts());

        // Math
        $math = new Math(10, '+', 20);
        self::assertEquals(10, $math->getLeftExpr());
        self::assertEquals(20, $math->getRightExpr());
        self::assertEquals('+', $math->getOperator());

        // OrderBy
        $order = new OrderBy('foo', SortDirection::Descending);
        self::assertEquals(['foo DESC'], $order->getParts());

        // Andx
        $orx = new Orx(['foo = 1', 'bar = 2']);
        self::assertEquals(['foo = 1', 'bar = 2'], $orx->getParts());

        // Select
        $select = new Select(['foo', 'bar']);
        self::assertEquals(['foo', 'bar'], $select->getParts());
    }

    public function testAddEmpty(): void
    {
        $andExpr = $this->expr->andX();
        $andExpr->add($this->expr->andX());

        self::assertEquals(0, $andExpr->count());
    }

    public function testAddNull(): void
    {
        $andExpr = $this->expr->andX();
        $andExpr->add(null);

        self::assertEquals(0, $andExpr->count());
    }
}
