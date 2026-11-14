<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use BadMethodCallException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\QueryType;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SortDirection;

use function array_filter;

/**
 * Test case for the QueryBuilder class used to build DQL query string in a
 * object oriented way.
 */
class QueryBuilderTest extends OrmTestCase
{
    use VerifyDeprecations;

    private EntityManagerMock $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
    }

    protected function assertValidQueryBuilder(QueryBuilder $qb, string $expectedDql): void
    {
        $dql = $qb->getDQL();
        $q   = $qb->getQuery();

        self::assertEquals($expectedDql, $dql);
    }

    public function testSelectSetsType(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username');

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testDeleteSetsType(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->delete();

        $this->assertValidQueryBuilder($qb, 'DELETE Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testDeleteWithLimitNotSupported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Setting a limit is not supported for delete or update queries.');

        $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'c')
            ->setMaxResults(1);
    }

    public function testUpdateWithLimitNotSupported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Setting a limit is not supported for delete or update queries.');

        $this->entityManager->createQueryBuilder()
            ->update(CmsUser::class, 'c')
            ->setMaxResults(1);
    }

    public function testUpdateSetsType(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->update();

        $this->assertValidQueryBuilder($qb, 'UPDATE Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSimpleSelect(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->select('u.id', 'u.username');

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSimpleSelectArray(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->select(['u.id', 'u.username']);

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSimpleDelete(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u');

        $this->assertValidQueryBuilder($qb, 'DELETE Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSimpleSelectWithFromIndexBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CmsUser::class, 'u', 'u.id')
            ->select('u.id', 'u.username');

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u INDEX BY u.id');
    }

    public function testSimpleSelectWithIndexBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->indexBy('u', 'u.id')
            ->select('u.id', 'u.username');

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u INDEX BY u.id');
    }

    public function testSimpleUpdate(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->update(CmsUser::class, 'u')
            ->set('u.username', ':username');

        $this->assertValidQueryBuilder($qb, 'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.username = :username');
    }

    public function testInnerJoin(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a');

        $this->assertValidQueryBuilder($qb, 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a');
    }

    public function testComplexInnerJoin(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a', 'ON', 'u.id = a.author_id');

        $this->assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a ON u.id = a.author_id',
        );
    }

    public function testComplexInnerJoinWithComparisonCondition(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin(CmsArticle::class, 'a', Join::ON, $qb->expr()->eq('u.id', 'a.author_id'));

        $this->assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN Doctrine\Tests\Models\CMS\CmsArticle a ON u.id = a.author_id',
        );
    }

    public function testComplexInnerJoinWithCompositeCondition(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a', Join::ON, $qb->expr()->andX(
                $qb->expr()->eq('u.id', 'a.author_id'),
                $qb->expr()->isNotNull('u.name'),
            ));

        $this->assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a ON u.id = a.author_id AND u.name IS NOT NULL',
        );
    }

    public function testComplexInnerJoinWithFuncCondition(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a', Join::WITH, $qb->expr()->in(
                'u.id',
                [1, 2, 3],
            ));

        $this->assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a WITH u.id IN(1, 2, 3)',
        );
    }

    public function testComplexInnerJoinWithIndexBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a', 'ON', 'u.id = a.author_id', 'a.name');

        $this->assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a INDEX BY a.name ON u.id = a.author_id',
        );
    }

    public function testLeftJoin(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->leftJoin('u.articles', 'a');

        $this->assertValidQueryBuilder($qb, 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
    }

    public function testLeftJoinWithIndexBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->leftJoin('u.articles', 'a', null, null, 'a.name');

        $this->assertValidQueryBuilder($qb, 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a INDEX BY a.name');
    }

    public function testMultipleFrom(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsGroup::class, 'g');

        $this->assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsGroup g');
    }

    public function testMultipleFromWithIndexBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsGroup::class, 'g')
            ->indexBy('g', 'g.id');

        $this->assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsGroup g INDEX BY g.id');
    }

    public function testMultipleFromWithJoin(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsGroup::class, 'g')
            ->innerJoin('u.articles', 'a', 'ON', 'u.id = a.author_id');

        $this->assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a ON u.id = a.author_id, Doctrine\Tests\Models\CMS\CmsGroup g');
    }

    public function testMultipleFromWithMultipleJoin(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsArticle::class, 'a')
            ->innerJoin('u.groups', 'g')
            ->leftJoin('u.address', 'ad')
            ->innerJoin('a.comments', 'c');

        $this->assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.groups g LEFT JOIN u.address ad, Doctrine\Tests\Models\CMS\CmsArticle a INNER JOIN a.comments c');
    }

    public function testWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid');
    }

    public function testWhereWithUnexpectedNamedArguments(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Invalid call to Doctrine\ORM\QueryBuilder::where(), unknown named arguments: foo, bar');

        $qb->where(foo: 'u.id = :uid', bar: 'u.name = :name');
    }

    public function testComplexAndWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid OR u.id = :uid2 OR u.id = :uid3')
            ->andWhere('u.name = :name');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE (u.id = :uid OR u.id = :uid2 OR u.id = :uid3) AND u.name = :name');
    }

    public function testAndWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid')
            ->andWhere('u.id = :uid2');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id = :uid2');
    }

    public function testOrWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid')
            ->orWhere('u.id = :uid2');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id = :uid2');
    }

    public function testComplexAndWhereOrWhereNesting(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->orWhere('u.id = :uid2')
           ->andWhere('u.id = :uid3')
           ->orWhere('u.name = :name1', 'u.name = :name2')
           ->andWhere('u.name <> :noname');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE (((u.id = :uid OR u.id = :uid2) AND u.id = :uid3) OR u.name = :name1 OR u.name = :name2) AND u.name <> :noname');
    }

    public function testAndWhereIn(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->andWhere($qb->expr()->in('u.id', [1, 2, 3]));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id IN(1, 2, 3)');
    }

    public function testOrWhereIn(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->orWhere($qb->expr()->in('u.id', [1, 2, 3]));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id IN(1, 2, 3)');
    }

    public function testAndWhereNotIn(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->andWhere($qb->expr()->notIn('u.id', [1, 2, 3]));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id NOT IN(1, 2, 3)');
    }

    public function testOrWhereNotIn(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->orWhere($qb->expr()->notIn('u.id', [1, 2, 3]));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id NOT IN(1, 2, 3)');
    }

    public function testGroupBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->addGroupBy('u.username');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id, u.username');
    }

    public function testHaving(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->having('COUNT(u.id) > 1');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id HAVING COUNT(u.id) > 1');
    }

    public function testAndHaving(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->having('COUNT(u.id) > 1')
            ->andHaving('COUNT(u.id) < 1');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id HAVING COUNT(u.id) > 1 AND COUNT(u.id) < 1');
    }

    public function testOrHaving(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->having('COUNT(u.id) > 1')
            ->andHaving('COUNT(u.id) < 1')
            ->orHaving('COUNT(u.id) > 1');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id HAVING (COUNT(u.id) > 1 AND COUNT(u.id) < 1) OR COUNT(u.id) > 1');
    }

    public function testOrderBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy('u.username', SortDirection::Ascending);

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC');
    }

    public function testOrderByWithExpression(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy($qb->expr()->asc('u.username'));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC');
    }

    public function testAddOrderBy(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy('u.username', SortDirection::Ascending)
            ->addOrderBy('u.username', SortDirection::Descending);

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC, u.username DESC');
    }

    public function testAddOrderByWithExpression(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy('u.username', SortDirection::Ascending)
            ->addOrderBy($qb->expr()->desc('u.username'));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC, u.username DESC');
    }

    public function testAddCriteriaWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('field', 'value'));

        $qb->addCriteria($criteria);

        self::assertEquals('u.field = :field', (string) $qb->getDQLPart('where'));
        self::assertNotNull($qb->getParameter('field'));
    }

    public function testAddMultipleSameCriteriaWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->andX(
            $criteria->expr()->eq('field', 'value1'),
            $criteria->expr()->eq('field', 'value2'),
        ));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :field AND alias1.field = :field_1', (string) $qb->getDQLPart('where'));
        self::assertNotNull($qb->getParameter('field'));
        self::assertNotNull($qb->getParameter('field_1'));
    }

    #[Group('DDC-2844')]
    public function testAddCriteriaWhereWithMultipleParametersWithSameField(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :field AND alias1.field > :field_1', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field')->getValue());
        self::assertSame('value2', $qb->getParameter('field_1')->getValue());
    }

    #[Group('DDC-2844')]
    public function testAddCriteriaWhereWithMultipleParametersWithDifferentFields(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('field1', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field2', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field1 = :field1 AND alias1.field2 > :field2', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field1')->getValue());
        self::assertSame('value2', $qb->getParameter('field2')->getValue());
    }

    #[Group('DDC-2844')]
    public function testAddCriteriaWhereWithMultipleParametersWithSubpathsAndDifferentProperties(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('field1', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field2', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field1 = :field1 AND alias1.field2 > :field2', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field1')->getValue());
        self::assertSame('value2', $qb->getParameter('field2')->getValue());
    }

    #[Group('DDC-2844')]
    public function testAddCriteriaWhereWithMultipleParametersWithSubpathsAndSameProperty(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('field1', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field1', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field1 = :field1 AND alias1.field1 > :field1_1', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field1')->getValue());
        self::assertSame('value2', $qb->getParameter('field1_1')->getValue());
    }

    public function testAddCriteriaOrder(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u');

        $criteria = Criteria::create();
        $criteria->orderBy(['field' => SortDirection::Descending]);

        $qb->addCriteria($criteria);

        self::assertCount(1, $orderBy = $qb->getDQLPart('orderBy'));
        self::assertEquals('u.field DESC', (string) $orderBy[0]);
    }

    #[Group('DDC-3108')]
    public function testAddCriteriaOrderOnJoinAlias(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->join('u.article', 'a');

        $criteria = Criteria::create();
        $criteria->orderBy(['a.field' => SortDirection::Descending]);

        $qb->addCriteria($criteria);

        self::assertCount(1, $orderBy = $qb->getDQLPart('orderBy'));
        self::assertEquals('a.field DESC', (string) $orderBy[0]);
    }

    public function testAddCriteriaLimit(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u');

        $criteria = Criteria::create();
        $criteria->setFirstResult(2);
        $criteria->setMaxResults(10);

        $qb->addCriteria($criteria);

        self::assertEquals(2, $qb->getFirstResult());
        self::assertEquals(10, $qb->getMaxResults());
    }

    public function testAddCriteriaUndefinedLimit(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->setFirstResult(2)
            ->setMaxResults(10);

        $criteria = Criteria::create();

        $qb->addCriteria($criteria);

        self::assertEquals(2, $qb->getFirstResult());
        self::assertEquals(10, $qb->getMaxResults());
    }

    public function testGetQuery(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');
        $q  = $qb->getQuery();

        self::assertEquals(Query::class, $q::class);
    }

    public function testSetParameter(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', 1);

        $parameter = new Parameter('id', 1, ParameterTypeInferer::inferType(1));
        $inferred  = $qb->getParameter('id');

        self::assertSame($parameter->getValue(), $inferred->getValue());
        self::assertSame($parameter->getType(), $inferred->getType());
        self::assertFalse($inferred->typeWasSpecified());
    }

    public function testSetParameters(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($qb->expr()->orX('u.username = :username', 'u.username = :username2'));

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter('username', 'jwage'));
        $parameters->add(new Parameter('username2', 'jonwage'));

        $qb->setParameters($parameters);

        self::assertEquals($parameters, $qb->getQuery()->getParameters());
    }

    public function testGetParameters(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :id');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter('id', 1));

        $qb->setParameters($parameters);

        self::assertEquals($parameters, $qb->getParameters());
    }

    public function testGetParameter(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :id');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter('id', 1));

        $qb->setParameters($parameters);

        self::assertEquals($parameters->first(), $qb->getParameter('id'));
    }

    public function testMultipleWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid', 'u.id = :uid2');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id = :uid2');
    }

    public function testMultipleAndWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->andWhere('u.id = :uid', 'u.id = :uid2');

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id = :uid2');
    }

    public function testMultipleOrWhere(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->orWhere('u.id = :uid', $qb->expr()->eq('u.id', ':uid2'));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id = :uid2');
    }

    public function testComplexWhere(): void
    {
        $qb     = $this->entityManager->createQueryBuilder();
        $orExpr = $qb->expr()->orX();
        $orExpr->add($qb->expr()->eq('u.id', ':uid3'));
        $orExpr->add($qb->expr()->in('u.id', [1]));

        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($orExpr);

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid3 OR u.id IN(1)');
    }

    public function testWhereInWithStringLiterals(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($qb->expr()->in('u.name', ['one', 'two', 'three']));

        $this->assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('one', 'two', 'three')");

        $qb->where($qb->expr()->in('u.name', ["O'Reilly", "O'Neil", 'Smith']));

        $this->assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('O''Reilly', 'O''Neil', 'Smith')");
    }

    public function testWhereInWithObjectLiterals(): void
    {
        $qb   = $this->entityManager->createQueryBuilder();
        $expr = $this->entityManager->getExpressionBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($expr->in('u.name', [$expr->literal('one'), $expr->literal('two'), $expr->literal('three')]));

        $this->assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('one', 'two', 'three')");

        $qb->where($expr->in('u.name', [$expr->literal("O'Reilly"), $expr->literal("O'Neil"), $expr->literal('Smith')]));

        $this->assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('O''Reilly', 'O''Neil', 'Smith')");
    }

    public function testNegation(): void
    {
        $expr   = $this->entityManager->getExpressionBuilder();
        $orExpr = $expr->orX();
        $orExpr->add($expr->eq('u.id', ':uid3'));
        $orExpr->add($expr->not($expr->in('u.id', [1])));

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($orExpr);

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid3 OR NOT(u.id IN(1))');
    }

    public function testSomeAllAny(): void
    {
        $qb   = $this->entityManager->createQueryBuilder();
        $expr = $this->entityManager->getExpressionBuilder();

        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($expr->gt('u.id', $expr->all('select a.id from Doctrine\Tests\Models\CMS\CmsArticle a')));

        $this->assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id > ALL(select a.id from Doctrine\Tests\Models\CMS\CmsArticle a)');
    }

    public function testMultipleIsolatedQueryConstruction(): void
    {
        $qb   = $this->entityManager->createQueryBuilder();
        $expr = $this->entityManager->getExpressionBuilder();

        $qb->select('u')->from(CmsUser::class, 'u');
        $qb->where($expr->eq('u.name', ':name'));
        $qb->setParameter('name', 'romanb');

        $q1 = $qb->getQuery();

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = :name', $q1->getDQL());
        self::assertCount(1, $q1->getParameters());

        // add another condition and construct a second query
        $qb->andWhere($expr->eq('u.id', ':id'));
        $qb->setParameter('id', 42);

        $q2 = $qb->getQuery();

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = :name AND u.id = :id', $q2->getDQL());
        self::assertNotSame($q1, $q2); // two different, independent queries
        self::assertCount(2, $q2->getParameters());
        self::assertCount(1, $q1->getParameters()); // $q1 unaffected
    }

    public function testGetEntityManager(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        self::assertEquals($this->entityManager, $qb->getEntityManager());
    }

    public function testSelectWithFuncExpression(): void
    {
        $qb   = $this->entityManager->createQueryBuilder();
        $expr = $qb->expr();
        $qb->select($expr->count('e.id'));

        $this->assertValidQueryBuilder($qb, 'SELECT COUNT(e.id)');
    }

    public function testResetDQLPart(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.username = ?1')->orderBy('u.username');

        self::assertEquals('u.username = ?1', (string) $qb->getDQLPart('where'));
        self::assertCount(1, $qb->getDQLPart('orderBy'));

        $qb->resetDQLPart('where')->resetDQLPart('orderBy');

        self::assertNull($qb->getDQLPart('where'));
        self::assertCount(0, $qb->getDQLPart('orderBy'));
    }

    public function testResetDQLParts(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.username = ?1')->orderBy('u.username');

        $qb->resetDQLParts(['where', 'orderBy']);

        self::assertCount(1, $qb->getDQLPart('select'));
        self::assertNull($qb->getDQLPart('where'));
        self::assertCount(0, $qb->getDQLPart('orderBy'));
    }

    public function testResetAllDQLParts(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.username = ?1')->orderBy('u.username');

        $qb->resetDQLParts();

        self::assertCount(0, $qb->getDQLPart('select'));
        self::assertNull($qb->getDQLPart('where'));
        self::assertCount(0, $qb->getDQLPart('orderBy'));
    }

    #[Group('DDC-867')]
    public function testDeepClone(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->andWhere('u.username = ?1')
            ->andWhere('u.status = ?2');

        $expr = $qb->getDQLPart('where');
        self::assertEquals(2, $expr->count(), 'Modifying the second query should affect the first one.');

        $qb2 = clone $qb;
        $qb2->andWhere('u.name = ?3');

        self::assertEquals(2, $expr->count(), 'Modifying the second query should affect the first one.');
    }

    #[Group('DDC-3108')]
    public function testAddCriteriaWhereWithJoinAlias(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');
        $qb->join('alias1.articles', 'alias2');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('alias2.field', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :field AND alias2.field > :alias2_field', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field')->getValue());
        self::assertSame('value2', $qb->getParameter('alias2_field')->getValue());
    }

    #[Group('DDC-3108')]
    public function testAddCriteriaWhereWithDefaultAndJoinAlias(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');
        $qb->join('alias1.articles', 'alias2');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('alias1.field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('alias2.field', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :alias1_field AND alias2.field > :alias2_field', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('alias1_field')->getValue());
        self::assertSame('value2', $qb->getParameter('alias2_field')->getValue());
    }

    #[Group('DDC-3108')]
    public function testAddCriteriaWhereOnJoinAliasWithDuplicateFields(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');
        $qb->join('alias1.articles', 'alias2');

        $criteria = Criteria::create();
        $criteria->where($criteria->expr()->eq('alias1.field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('alias2.field', 'value2'));
        $criteria->andWhere($criteria->expr()->lt('alias2.field', 'value3'));

        $qb->addCriteria($criteria);

        self::assertEquals('(alias1.field = :alias1_field AND alias2.field > :alias2_field) AND alias2.field < :alias2_field_2', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('alias1_field')->getValue());
        self::assertSame('value2', $qb->getParameter('alias2_field')->getValue());
        self::assertSame('value3', $qb->getParameter('alias2_field_2')->getValue());
    }

    #[Group('DDC-1933')]
    public function testParametersAreCloned(): void
    {
        $originalQb = new QueryBuilder($this->entityManager);

        $originalQb->setParameter('parameter1', 'value1');

        $copy = clone $originalQb;
        $copy->setParameter('parameter2', 'value2');

        self::assertCount(1, $originalQb->getParameters());
        self::assertSame('value1', $copy->getParameter('parameter1')->getValue());
        self::assertSame('value2', $copy->getParameter('parameter2')->getValue());
    }

    public function testGetRootAlias(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals('u', $qb->getRootAlias());
    }

    public function testGetRootAliases(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals(['u'], $qb->getRootAliases());
    }

    public function testGetRootEntities(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals([CmsUser::class], $qb->getRootEntities());
    }

    public function testGetSeveralRootAliases(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->from(CmsUser::class, 'u2');

        self::assertEquals(['u', 'u2'], $qb->getRootAliases());
        self::assertEquals('u', $qb->getRootAlias());
    }

    #[WithoutErrorHandler]
    public function testBCAddJoinWithoutRootAlias(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12051');
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->add('join', ['INNER JOIN u.groups g'], true);

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.groups g', $qb->getDQL());
    }

    #[Group('DDC-1211')]
    public function testEmptyStringLiteral(): void
    {
        $expr = $this->entityManager->getExpressionBuilder();
        $qb   = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where($expr->eq('u.username', $expr->literal('')));

        self::assertEquals("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = ''", $qb->getDQL());
    }

    #[Group('DDC-1211')]
    public function testEmptyNumericLiteral(): void
    {
        $expr = $this->entityManager->getExpressionBuilder();
        $qb   = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where($expr->eq('u.username', $expr->literal(0)));

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = 0', $qb->getDQL());
    }

    #[Group('DDC-1227')]
    public function testAddFromString(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->add('select', 'u')
            ->add('from', CmsUser::class . ' u');

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u', $qb->getDQL());
    }

    #[Group('DDC-1619')]
    public function testAddDistinct(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->distinct()
            ->from(CmsUser::class, 'u');

        self::assertEquals('SELECT DISTINCT u FROM Doctrine\Tests\Models\CMS\CmsUser u', $qb->getDQL());
    }

    public function testDistinctUpdatesState(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        $qb->getDQL();
        $qb->distinct();

        self::assertEquals('SELECT DISTINCT u FROM Doctrine\Tests\Models\CMS\CmsUser u', $qb->getDQL());
    }

    #[Group('DDC-2192')]
    public function testWhereAppend(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Using \$append = true does not have an effect with 'where' or 'having' parts. See QueryBuilder#andWhere() for an example for correct usage.");

        $qb = $this->entityManager->createQueryBuilder()
            ->add('where', 'u.foo = ?1')
            ->add('where', 'u.bar = ?2', true);
    }

    public function testSecondLevelCacheQueryBuilderOptions(): void
    {
        $defaultQueryBuilder = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(State::class, 's');

        self::assertFalse($defaultQueryBuilder->isCacheable());
        self::assertEquals(0, $defaultQueryBuilder->getLifetime());
        self::assertNull($defaultQueryBuilder->getCacheRegion());
        self::assertNull($defaultQueryBuilder->getCacheMode());

        $defaultQuery = $defaultQueryBuilder->getQuery();

        self::assertFalse($defaultQuery->isCacheable());
        self::assertEquals(0, $defaultQuery->getLifetime());
        self::assertNull($defaultQuery->getCacheRegion());
        self::assertNull($defaultQuery->getCacheMode());

        $builder = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->setLifetime(123)
            ->setCacheable(true)
            ->setCacheRegion('foo_reg')
            ->setCacheMode(Cache::MODE_REFRESH)
            ->from(State::class, 's');

        self::assertTrue($builder->isCacheable());
        self::assertEquals(123, $builder->getLifetime());
        self::assertEquals('foo_reg', $builder->getCacheRegion());
        self::assertEquals(Cache::MODE_REFRESH, $builder->getCacheMode());

        $query = $builder->getQuery();

        self::assertTrue($query->isCacheable());
        self::assertEquals(123, $query->getLifetime());
        self::assertEquals('foo_reg', $query->getCacheRegion());
        self::assertEquals(Cache::MODE_REFRESH, $query->getCacheMode());
    }

    #[Group('DDC-2253')]
    public function testRebuildsFromParts(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
          ->select('u')
          ->from(CmsUser::class, 'u')
          ->join('u.article', 'a');

        $dqlParts = $qb->getDQLParts();
        $dql      = $qb->getDQL();

        $qb2 = $this->entityManager->createQueryBuilder();
        foreach (array_filter($dqlParts) as $name => $part) {
            $qb2->add($name, $part);
        }

        $dql2 = $qb2->getDQL();

        self::assertEquals($dql, $dql2);
    }

    public function testGetAllAliasesWithNoJoins(): void
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')->from(CmsUser::class, 'u');

        $aliases = $qb->getAllAliases();

        self::assertEquals(['u'], $aliases);
    }

    public function testGetAllAliasesWithJoins(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->join('u.groups', 'g');

        $aliases = $qb->getAllAliases();

        self::assertEquals(['u', 'g'], $aliases);
    }

    #[Group('6699')]
    public function testGetParameterTypeJuggling(): void
    {
        $builder = $this->entityManager->createQueryBuilder()
                             ->select('u')
                             ->from(CmsUser::class, 'u')
                             ->where('u.id = ?0');

        $builder->setParameter(0, 0);

        self::assertCount(1, $builder->getParameters());
        self::assertSame(0, $builder->getParameter(0)->getValue());
        self::assertSame(0, $builder->getParameter('0')->getValue());
    }

    #[Group('6699')]
    public function testSetParameterWithNameZeroIsNotOverridden(): void
    {
        $builder = $this->entityManager->createQueryBuilder()
                             ->select('u')
                             ->from(CmsUser::class, 'u')
                             ->where('u.id != ?0')
                             ->andWhere('u.username = :name');

        $builder->setParameter(0, 0);
        $builder->setParameter('name', 'Doctrine');

        self::assertCount(2, $builder->getParameters());
        self::assertSame(0, $builder->getParameter('0')->getValue());
        self::assertSame('Doctrine', $builder->getParameter('name')->getValue());
    }

    #[Group('6699')]
    public function testSetParameterWithNameZeroDoesNotOverrideAnotherParameter(): void
    {
        $builder = $this->entityManager->createQueryBuilder()
                             ->select('u')
                             ->from(CmsUser::class, 'u')
                             ->where('u.id != ?0')
                             ->andWhere('u.username = :name');

        $builder->setParameter('name', 'Doctrine');
        $builder->setParameter(0, 0);

        self::assertCount(2, $builder->getParameters());
        self::assertSame(0, $builder->getParameter(0)->getValue());
        self::assertSame('Doctrine', $builder->getParameter('name')->getValue());
    }

    #[Group('6699')]
    public function testSetParameterWithTypeJugglingWorks(): void
    {
        $builder = $this->entityManager->createQueryBuilder()
                             ->select('u')
                             ->from(CmsUser::class, 'u')
                             ->where('u.id != ?0')
                             ->andWhere('u.username = :name');

        $builder->setParameter('0', 1);
        $builder->setParameter('name', 'Doctrine');
        $builder->setParameter(0, 2);
        $builder->setParameter('0', 3);

        self::assertCount(2, $builder->getParameters());
        self::assertSame(3, $builder->getParameter(0)->getValue());
        self::assertSame(3, $builder->getParameter('0')->getValue());
        self::assertSame('Doctrine', $builder->getParameter('name')->getValue());
    }

    public function testJoin(): void
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->leftJoin(CmsArticle::class, 'a0')
            ->innerJoin(CmsArticle::class, 'a1');

        self::assertSame('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN Doctrine\Tests\Models\CMS\CmsArticle a0 INNER JOIN Doctrine\Tests\Models\CMS\CmsArticle a1', $builder->getDQL());
    }

    public function testUpdateWithoutAlias(): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Doctrine\ORM\QueryBuilder::update(): The alias for entity Doctrine\Tests\Models\CMS\CmsUser u must not be omitted.');
        $qb->update(CmsUser::class . ' u');
    }

    public function testDeleteWithoutAlias(): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Doctrine\ORM\QueryBuilder::delete(): The alias for entity Doctrine\Tests\Models\CMS\CmsUser u must not be omitted.');
        $qb->delete(CmsUser::class . ' u');
    }

    public function testCreateNamedParameter(): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->where(
                $qb->expr()->eq('u.name', $qb->createNamedParameter('john doe', Types::STRING)),
            )
            ->orWhere(
                $qb->expr()->eq('u.rank', $qb->createNamedParameter(100, Types::INTEGER)),
            );

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = :dcValue1 OR u.rank = :dcValue2', $qb->getDQL());
        self::assertEquals('john doe', $qb->getParameter('dcValue1')->getValue());
        self::assertEquals(Types::STRING, $qb->getParameter('dcValue1')->getType());
        self::assertEquals(100, $qb->getParameter('dcValue2')->getValue());
        self::assertEquals(Types::INTEGER, $qb->getParameter('dcValue2')->getType());
    }

    public function testCreateNamedParameterCustomPlaceholder(): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->where(
                $qb->expr()->eq('u.name', $qb->createNamedParameter('john doe', Types::STRING, ':test')),
            )
            ->andWhere(
                $qb->expr()->eq('u.rank', $qb->createNamedParameter(100, Types::INTEGER)),
            );

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name = :test AND u.rank = :dcValue1', $qb->getDQL());
        self::assertEquals('john doe', $qb->getParameter('test')->getValue());
        self::assertEquals(Types::STRING, $qb->getParameter('test')->getType());
        self::assertEquals(100, $qb->getParameter('dcValue1')->getValue());
        self::assertEquals(Types::INTEGER, $qb->getParameter('dcValue1')->getType());
    }

    public function testType(): void
    {
        $qb = new class ($this->entityManager) extends QueryBuilder {
            public function test(): void
            {
                TestCase::assertSame(QueryType::Select, $this->getType());

                $this->delete();
                TestCase::assertSame(QueryType::Delete, $this->getType());

                $this->update();
                TestCase::assertSame(QueryType::Update, $this->getType());

                $this->select();
                TestCase::assertSame(QueryType::Select, $this->getType());
            }
        };

        $qb->test();
    }

    #[DataProvider('provideHint')]
    public function testSingleHint(mixed $expected): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username')
            ->setHint('foo', $expected);

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');

        $query = $qb->getQuery();
        self::assertTrue($query->hasHint('foo'));
        self::assertEquals($expected, $query->getHint('foo'));
    }

    public static function provideHint(): array
    {
        return [
            ['bar'],
            [new CmsUser()],
            [['a','b','c']],
            [1],
            [true],
        ];
    }

    public function testMultipleHints(): void
    {
        $object = new CmsUser();
        $qb     = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username')
            ->setHint('string', 'bar')
            ->setHint('object', $object)
            ->setHint('array', ['a', 'b', 'c'])
            ->setHint('int', 5)
            ->setHint('bool', true);

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');

        $query = $qb->getQuery();
        self::assertTrue($query->hasHint('string'));
        self::assertTrue($query->hasHint('object'));
        self::assertTrue($query->hasHint('array'));
        self::assertTrue($query->hasHint('int'));
        self::assertTrue($query->hasHint('bool'));

        self::assertEquals('bar', $query->getHint('string'));
        self::assertInstanceOf(CmsUser::class, $query->getHint('object'));
        self::assertEquals(['a', 'b', 'c'], $query->getHint('array'));
        self::assertEquals(5, $query->getHint('int'));
        self::assertTrue($query->getHint('bool'));
    }

    public function testHasHint(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username')
            ->setHint('foo', 'bar');

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');

        self::assertTrue($qb->hasHint('foo'));
    }

    public function testGetHint(): void
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username')
            ->setHint('foo', 'bar');

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');

        self::assertEquals('bar', $qb->getHint('foo'));
    }

    public function testGetHints(): void
    {
        $object = new CmsUser();
        $qb     = $this->entityManager->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username')
            ->setHint('string', 'bar')
            ->setHint('object', $object)
            ->setHint('array', ['a', 'b', 'c'])
            ->setHint('int', 5)
            ->setHint('bool', true);

        $this->assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');

        self::assertCount(5, $qb->getHints('foo'));
    }
}
