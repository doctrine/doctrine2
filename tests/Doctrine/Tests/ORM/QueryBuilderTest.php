<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Cache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\OrmTestCase;
use InvalidArgumentException;
use function array_filter;
use function get_class;

/**
 * Test case for the QueryBuilder class used to build DQL query string in a
 * object oriented way.
 */
class QueryBuilderTest extends OrmTestCase
{
    /** @var EntityManagerInterface */
    private $em;

    protected function setUp() : void
    {
        $this->em = $this->getTestEntityManager();
    }

    protected function assertValidQueryBuilder(QueryBuilder $qb, $expectedDql)
    {
        $dql = $qb->getDQL();
        $q   = $qb->getQuery();

        self::assertEquals($expectedDql, $dql);
    }

    public function testSelectSetsType() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select('u.id', 'u.username');

        self::assertEquals($qb->getType(), QueryBuilder::SELECT);
    }

    public function testEmptySelectSetsType() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(CmsUser::class, 'u')
            ->select();

        self::assertEquals($qb->getType(), QueryBuilder::SELECT);
    }

    public function testDeleteSetsType() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->delete();

        self::assertEquals($qb->getType(), QueryBuilder::DELETE);
    }

    public function testUpdateSetsType() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->update();

        self::assertEquals($qb->getType(), QueryBuilder::UPDATE);
    }

    public function testSimpleSelect() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->select('u.id', 'u.username');

        self::assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSimpleDelete() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(CmsUser::class, 'u');

        self::assertValidQueryBuilder($qb, 'DELETE Doctrine\Tests\Models\CMS\CmsUser u');
    }

    public function testSimpleSelectWithFromIndexBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->from(CmsUser::class, 'u', 'u.id')
            ->select('u.id', 'u.username');

        self::assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u INDEX BY u.id');
    }

    public function testSimpleSelectWithIndexBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->from(CmsUser::class, 'u')
            ->indexBy('u', 'u.id')
            ->select('u.id', 'u.username');

        self::assertValidQueryBuilder($qb, 'SELECT u.id, u.username FROM Doctrine\Tests\Models\CMS\CmsUser u INDEX BY u.id');
    }

    public function testSimpleUpdate() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->update(CmsUser::class, 'u')
            ->set('u.username', ':username');

        self::assertValidQueryBuilder($qb, 'UPDATE Doctrine\Tests\Models\CMS\CmsUser u SET u.username = :username');
    }

    public function testInnerJoin() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a');

        self::assertValidQueryBuilder($qb, 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a');
    }

    public function testComplexInnerJoin() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a', 'ON', 'u.id = a.author_id');

        self::assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a ON u.id = a.author_id'
        );
    }

    public function testComplexInnerJoinWithIndexBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->innerJoin('u.articles', 'a', 'ON', 'u.id = a.author_id', 'a.name');

        self::assertValidQueryBuilder(
            $qb,
            'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a INDEX BY a.name ON u.id = a.author_id'
        );
    }

    public function testLeftJoin() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->leftJoin('u.articles', 'a');

        self::assertValidQueryBuilder($qb, 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
    }

    public function testLeftJoinWithIndexBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'a')
            ->from(CmsUser::class, 'u')
            ->leftJoin('u.articles', 'a', null, null, 'a.name');

        self::assertValidQueryBuilder($qb, 'SELECT u, a FROM Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a INDEX BY a.name');
    }

    public function testMultipleFrom() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsGroup::class, 'g');

        self::assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsGroup g');
    }

    public function testMultipleFromWithIndexBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsGroup::class, 'g')
            ->indexBy('g', 'g.id');

        self::assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u, Doctrine\Tests\Models\CMS\CmsGroup g INDEX BY g.id');
    }

    public function testMultipleFromWithJoin() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsGroup::class, 'g')
            ->innerJoin('u.articles', 'a', 'ON', 'u.id = a.author_id');

        self::assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.articles a ON u.id = a.author_id, Doctrine\Tests\Models\CMS\CmsGroup g');
    }

    public function testMultipleFromWithMultipleJoin() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u', 'g')
            ->from(CmsUser::class, 'u')
            ->from(CmsArticle::class, 'a')
            ->innerJoin('u.groups', 'g')
            ->leftJoin('u.address', 'ad')
            ->innerJoin('a.comments', 'c');

        self::assertValidQueryBuilder($qb, 'SELECT u, g FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.groups g LEFT JOIN u.address ad, Doctrine\Tests\Models\CMS\CmsArticle a INNER JOIN a.comments c');
    }

    public function testWhere() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid');
    }

    public function testComplexAndWhere() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid OR u.id = :uid2 OR u.id = :uid3')
            ->andWhere('u.name = :name');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE (u.id = :uid OR u.id = :uid2 OR u.id = :uid3) AND u.name = :name');
    }

    public function testAndWhere() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid')
            ->andWhere('u.id = :uid2');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id = :uid2');
    }

    public function testOrWhere() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid')
            ->orWhere('u.id = :uid2');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id = :uid2');
    }

    public function testComplexAndWhereOrWhereNesting() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->orWhere('u.id = :uid2')
           ->andWhere('u.id = :uid3')
           ->orWhere('u.name = :name1', 'u.name = :name2')
           ->andWhere('u.name <> :noname');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE (((u.id = :uid OR u.id = :uid2) AND u.id = :uid3) OR u.name = :name1 OR u.name = :name2) AND u.name <> :noname');
    }

    public function testAndWhereIn() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->andWhere($qb->expr()->in('u.id', [1, 2, 3]));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id IN(1, 2, 3)');
    }

    public function testOrWhereIn() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->orWhere($qb->expr()->in('u.id', [1, 2, 3]));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id IN(1, 2, 3)');
    }

    public function testAndWhereNotIn() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->andWhere($qb->expr()->notIn('u.id', [1, 2, 3]));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id NOT IN(1, 2, 3)');
    }

    public function testOrWhereNotIn() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :uid')
           ->orWhere($qb->expr()->notIn('u.id', [1, 2, 3]));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id NOT IN(1, 2, 3)');
    }

    public function testGroupBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->addGroupBy('u.username');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id, u.username');
    }

    public function testHaving() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->having('COUNT(u.id) > 1');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id HAVING COUNT(u.id) > 1');
    }

    public function testAndHaving() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->having('COUNT(u.id) > 1')
            ->andHaving('COUNT(u.id) < 1');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id HAVING COUNT(u.id) > 1 AND COUNT(u.id) < 1');
    }

    public function testOrHaving() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->groupBy('u.id')
            ->having('COUNT(u.id) > 1')
            ->andHaving('COUNT(u.id) < 1')
            ->orHaving('COUNT(u.id) > 1');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u GROUP BY u.id HAVING (COUNT(u.id) > 1 AND COUNT(u.id) < 1) OR COUNT(u.id) > 1');
    }

    public function testOrderBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy('u.username', 'ASC');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC');
    }

    public function testOrderByWithExpression() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy($qb->expr()->asc('u.username'));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC');
    }

    public function testAddOrderBy() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy('u.username', 'ASC')
            ->addOrderBy('u.username', 'DESC');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC, u.username DESC');
    }

    public function testAddOrderByWithExpression() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->orderBy('u.username', 'ASC')
            ->addOrderBy($qb->expr()->desc('u.username'));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u ORDER BY u.username ASC, u.username DESC');
    }

    public function testAddCriteriaWhere() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('field', 'value'));

        $qb->addCriteria($criteria);

        self::assertEquals('u.field = :field', (string) $qb->getDQLPart('where'));
        self::assertNotNull($qb->getParameter('field'));
    }

    public function testAddMultipleSameCriteriaWhere() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->andX(
            $criteria->expr()->eq('field', 'value1'),
            $criteria->expr()->eq('field', 'value2')
        ));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :field AND alias1.field = :field_1', (string) $qb->getDQLPart('where'));
        self::assertNotNull($qb->getParameter('field'));
        self::assertNotNull($qb->getParameter('field_1'));
    }

    /**
     * @group DDC-2844
     */
    public function testAddCriteriaWhereWithMultipleParametersWithSameField() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :field AND alias1.field > :field_1', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field')->getValue());
        self::assertSame('value2', $qb->getParameter('field_1')->getValue());
    }

    /**
     * @group DDC-2844
     */
    public function testAddCriteriaWhereWithMultipleParametersWithDifferentFields() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('field1', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field2', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field1 = :field1 AND alias1.field2 > :field2', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field1')->getValue());
        self::assertSame('value2', $qb->getParameter('field2')->getValue());
    }

    /**
     * @group DDC-2844
     */
    public function testAddCriteriaWhereWithMultipleParametersWithSubpathsAndDifferentProperties() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('field1', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field2', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field1 = :field1 AND alias1.field2 > :field2', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field1')->getValue());
        self::assertSame('value2', $qb->getParameter('field2')->getValue());
    }

    /**
     * @group DDC-2844
     */
    public function testAddCriteriaWhereWithMultipleParametersWithSubpathsAndSameProperty() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('field1', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('field1', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field1 = :field1 AND alias1.field1 > :field1_1', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field1')->getValue());
        self::assertSame('value2', $qb->getParameter('field1_1')->getValue());
    }

    public function testAddCriteriaOrder() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u');

        $criteria = new Criteria();
        $criteria->orderBy(['field' => Criteria::DESC]);

        $qb->addCriteria($criteria);

        self::assertCount(1, $orderBy = $qb->getDQLPart('orderBy'));
        self::assertEquals('u.field DESC', (string) $orderBy[0]);
    }

    /**
     * @group DDC-3108
     */
    public function testAddCriteriaOrderOnJoinAlias() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->join('u.article', 'a');

        $criteria = new Criteria();
        $criteria->orderBy(['a.field' => Criteria::DESC]);

        $qb->addCriteria($criteria);

        self::assertCount(1, $orderBy = $qb->getDQLPart('orderBy'));
        self::assertEquals('a.field DESC', (string) $orderBy[0]);
    }

    public function testAddCriteriaLimit() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u');

        $criteria = new Criteria();
        $criteria->setFirstResult(2);
        $criteria->setMaxResults(10);

        $qb->addCriteria($criteria);

        self::assertEquals(2, $qb->getFirstResult());
        self::assertEquals(10, $qb->getMaxResults());
    }

    public function testAddCriteriaUndefinedLimit() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(CmsUser::class, 'u')
            ->setFirstResult(2)
            ->setMaxResults(10);

        $criteria = new Criteria();

        $qb->addCriteria($criteria);

        self::assertEquals(2, $qb->getFirstResult());
        self::assertEquals(10, $qb->getMaxResults());
    }

    public function testGetQuery() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');
        $q  = $qb->getQuery();

        self::assertEquals(Query::class, get_class($q));
    }

    public function testSetParameter() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :id')
            ->setParameter('id', 1);

        $parameter = new Parameter('id', 1, ParameterTypeInferer::inferType(1));

        self::assertEquals($parameter, $qb->getParameter('id'));
    }

    public function testSetParameters() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($qb->expr()->orX('u.username = :username', 'u.username = :username2'));

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter('username', 'jwage'));
        $parameters->add(new Parameter('username2', 'jonwage'));

        $qb->setParameters($parameters);

        self::assertEquals($parameters, $qb->getQuery()->getParameters());
    }

    public function testGetParameters() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where('u.id = :id');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter('id', 1));

        $qb->setParameters($parameters);

        self::assertEquals($parameters, $qb->getParameters());
    }

    public function testGetParameter() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :id');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter('id', 1));

        $qb->setParameters($parameters);

        self::assertEquals($parameters->first(), $qb->getParameter('id'));
    }

    public function testMultipleWhere() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.id = :uid', 'u.id = :uid2');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id = :uid2');
    }

    public function testMultipleAndWhere() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->andWhere('u.id = :uid', 'u.id = :uid2');

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid AND u.id = :uid2');
    }

    public function testMultipleOrWhere() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->orWhere('u.id = :uid', $qb->expr()->eq('u.id', ':uid2'));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid OR u.id = :uid2');
    }

    public function testComplexWhere() : void
    {
        $qb     = $this->em->createQueryBuilder();
        $orExpr = $qb->expr()->orX();
        $orExpr->add($qb->expr()->eq('u.id', ':uid3'));
        $orExpr->add($qb->expr()->in('u.id', [1]));

        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($orExpr);

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid3 OR u.id IN(1)');
    }

    public function testWhereInWithStringLiterals() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($qb->expr()->in('u.name', ['one', 'two', 'three']));

        self::assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('one', 'two', 'three')");

        $qb->where($qb->expr()->in('u.name', ["O'Reilly", "O'Neil", 'Smith']));

        self::assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('O''Reilly', 'O''Neil', 'Smith')");
    }

    public function testWhereInWithObjectLiterals() : void
    {
        $qb   = $this->em->createQueryBuilder();
        $expr = $this->em->getExpressionBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($expr->in('u.name', [$expr->literal('one'), $expr->literal('two'), $expr->literal('three')]));

        self::assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('one', 'two', 'three')");

        $qb->where($expr->in('u.name', [$expr->literal("O'Reilly"), $expr->literal("O'Neil"), $expr->literal('Smith')]));

        self::assertValidQueryBuilder($qb, "SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.name IN('O''Reilly', 'O''Neil', 'Smith')");
    }

    public function testNegation() : void
    {
        $expr   = $this->em->getExpressionBuilder();
        $orExpr = $expr->orX();
        $orExpr->add($expr->eq('u.id', ':uid3'));
        $orExpr->add($expr->not($expr->in('u.id', [1])));

        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($orExpr);

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = :uid3 OR NOT(u.id IN(1))');
    }

    public function testSomeAllAny() : void
    {
        $qb   = $this->em->createQueryBuilder();
        $expr = $this->em->getExpressionBuilder();

        $qb->select('u')
           ->from(CmsUser::class, 'u')
           ->where($expr->gt('u.id', $expr->all('select a.id from Doctrine\Tests\Models\CMS\CmsArticle a')));

        self::assertValidQueryBuilder($qb, 'SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id > ALL(select a.id from Doctrine\Tests\Models\CMS\CmsArticle a)');
    }

    public function testMultipleIsolatedQueryConstruction() : void
    {
        $qb   = $this->em->createQueryBuilder();
        $expr = $this->em->getExpressionBuilder();

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

    public function testGetEntityManager() : void
    {
        $qb = $this->em->createQueryBuilder();
        self::assertEquals($this->em, $qb->getEntityManager());
    }

    public function testInitialStateIsClean() : void
    {
        $qb = $this->em->createQueryBuilder();
        self::assertEquals(QueryBuilder::STATE_CLEAN, $qb->getState());
    }

    public function testAlteringQueryChangesStateToDirty() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals(QueryBuilder::STATE_DIRTY, $qb->getState());
    }

    public function testSelectWithFuncExpression() : void
    {
        $qb   = $this->em->createQueryBuilder();
        $expr = $qb->expr();
        $qb->select($expr->count('e.id'));

        self::assertValidQueryBuilder($qb, 'SELECT COUNT(e.id)');
    }

    public function testResetDQLPart() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.username = ?1')->orderBy('u.username');

        self::assertEquals('u.username = ?1', (string) $qb->getDQLPart('where'));
        self::assertCount(1, $qb->getDQLPart('orderBy'));

        $qb->resetDQLPart('where')->resetDQLPart('orderBy');

        self::assertNull($qb->getDQLPart('where'));
        self::assertCount(0, $qb->getDQLPart('orderBy'));
    }

    public function testResetDQLParts() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.username = ?1')->orderBy('u.username');

        $qb->resetDQLParts(['where', 'orderBy']);

        self::assertCount(1, $qb->getDQLPart('select'));
        self::assertNull($qb->getDQLPart('where'));
        self::assertCount(0, $qb->getDQLPart('orderBy'));
    }

    public function testResetAllDQLParts() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where('u.username = ?1')->orderBy('u.username');

        $qb->resetDQLParts();

        self::assertCount(0, $qb->getDQLPart('select'));
        self::assertNull($qb->getDQLPart('where'));
        self::assertCount(0, $qb->getDQLPart('orderBy'));
    }

    /**
     * @group DDC-867
     */
    public function testDeepClone() : void
    {
        $qb = $this->em->createQueryBuilder()
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

    /**
     * @group DDC-3108
     */
    public function testAddCriteriaWhereWithJoinAlias() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');
        $qb->join('alias1.articles', 'alias2');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('alias2.field', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :field AND alias2.field > :alias2_field', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('field')->getValue());
        self::assertSame('value2', $qb->getParameter('alias2_field')->getValue());
    }

    /**
     * @group DDC-3108
     */
    public function testAddCriteriaWhereWithDefaultAndJoinAlias() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');
        $qb->join('alias1.articles', 'alias2');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('alias1.field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('alias2.field', 'value2'));

        $qb->addCriteria($criteria);

        self::assertEquals('alias1.field = :alias1_field AND alias2.field > :alias2_field', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('alias1_field')->getValue());
        self::assertSame('value2', $qb->getParameter('alias2_field')->getValue());
    }

    /**
     * @group DDC-3108
     */
    public function testAddCriteriaWhereOnJoinAliasWithDuplicateFields() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('alias1')->from(CmsUser::class, 'alias1');
        $qb->join('alias1.articles', 'alias2');

        $criteria = new Criteria();
        $criteria->where($criteria->expr()->eq('alias1.field', 'value1'));
        $criteria->andWhere($criteria->expr()->gt('alias2.field', 'value2'));
        $criteria->andWhere($criteria->expr()->lt('alias2.field', 'value3'));

        $qb->addCriteria($criteria);

        self::assertEquals('(alias1.field = :alias1_field AND alias2.field > :alias2_field) AND alias2.field < :alias2_field_2', (string) $qb->getDQLPart('where'));
        self::assertSame('value1', $qb->getParameter('alias1_field')->getValue());
        self::assertSame('value2', $qb->getParameter('alias2_field')->getValue());
        self::assertSame('value3', $qb->getParameter('alias2_field_2')->getValue());
    }

    /**
     * @group DDC-1933
     */
    public function testParametersAreCloned() : void
    {
        $originalQb = new QueryBuilder($this->em);

        $originalQb->setParameter('parameter1', 'value1');

        $copy = clone $originalQb;
        $copy->setParameter('parameter2', 'value2');

        self::assertCount(1, $originalQb->getParameters());
        self::assertSame('value1', $copy->getParameter('parameter1')->getValue());
        self::assertSame('value2', $copy->getParameter('parameter2')->getValue());
    }

    public function testGetRootAlias() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals('u', $qb->getRootAlias());
    }

    public function testGetRootAliases() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals(['u'], $qb->getRootAliases());
    }

    public function testGetRootEntities() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u');

        self::assertEquals([CmsUser::class], $qb->getRootEntities());
    }

    public function testGetSeveralRootAliases() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->from(CmsUser::class, 'u2');

        self::assertEquals(['u', 'u2'], $qb->getRootAliases());
        self::assertEquals('u', $qb->getRootAlias());
    }

    public function testBCAddJoinWithoutRootAlias() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->add('join', ['INNER JOIN u.groups g'], true);

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u INNER JOIN u.groups g', $qb->getDQL());
    }

    /**
     * @group DDC-1211
     */
    public function testEmptyStringLiteral() : void
    {
        $expr = $this->em->getExpressionBuilder();
        $qb   = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where($expr->eq('u.username', $expr->literal('')));

        self::assertEquals("SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = ''", $qb->getDQL());
    }

    /**
     * @group DDC-1211
     */
    public function testEmptyNumericLiteral() : void
    {
        $expr = $this->em->getExpressionBuilder();
        $qb   = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->where($expr->eq('u.username', $expr->literal(0)));

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.username = 0', $qb->getDQL());
    }

    /**
     * @group DDC-1227
     */
    public function testAddFromString() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->add('select', 'u')
            ->add('from', CmsUser::class . ' u');

        self::assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u', $qb->getDQL());
    }

    /**
     * @group DDC-1619
     */
    public function testAddDistinct() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->distinct()
            ->from(CmsUser::class, 'u');

        self::assertEquals('SELECT DISTINCT u FROM Doctrine\Tests\Models\CMS\CmsUser u', $qb->getDQL());
    }

    /**
     * @group DDC-2192
     */
    public function testWhereAppend() : void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Using \$append = true does not have an effect with 'where' or 'having' parts. See QueryBuilder#andWhere() for an example for correct usage.");

        $qb = $this->em->createQueryBuilder()
            ->add('where', 'u.foo = ?1')
            ->add('where', 'u.bar = ?2', true);
    }

    public function testSecondLevelCacheQueryBuilderOptions() : void
    {
        $defaultQueryBuilder = $this->em->createQueryBuilder()
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

        $builder = $this->em->createQueryBuilder()
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

    /**
     * @group DDC-2253
     */
    public function testRebuildsFromParts() : void
    {
        $qb = $this->em->createQueryBuilder()
          ->select('u')
          ->from(CmsUser::class, 'u')
          ->join('u.article', 'a');

        $dqlParts = $qb->getDQLParts();
        $dql      = $qb->getDQL();

        $qb2 = $this->em->createQueryBuilder();
        foreach (array_filter($dqlParts) as $name => $part) {
            $qb2->add($name, $part);
        }
        $dql2 = $qb2->getDQL();

        self::assertEquals($dql, $dql2);
    }

    public function testGetAllAliasesWithNoJoins() : void
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')->from(CmsUser::class, 'u');

        $aliases = $qb->getAllAliases();

        self::assertEquals(['u'], $aliases);
    }

    public function testGetAllAliasesWithJoins() : void
    {
        $qb = $this->em->createQueryBuilder()
            ->select('u')
            ->from(CmsUser::class, 'u')
            ->join('u.groups', 'g');

        $aliases = $qb->getAllAliases();

        self::assertEquals(['u', 'g'], $aliases);
    }

    /**
     * @group 6699
     */
    public function testGetParameterTypeJuggling() : void
    {
        $builder = $this->em->createQueryBuilder()
                            ->select('u')
                            ->from(CmsUser::class, 'u')
                            ->where('u.id = ?0');

        $builder->setParameter(0, 0);

        self::assertCount(1, $builder->getParameters());
        self::assertSame(0, $builder->getParameter(0)->getValue());
        self::assertSame(0, $builder->getParameter('0')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithNameZeroIsNotOverridden() : void
    {
        $builder = $this->em->createQueryBuilder()
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

    /**
     * @group 6699
     */
    public function testSetParameterWithNameZeroDoesNotOverrideAnotherParameter() : void
    {
        $builder = $this->em->createQueryBuilder()
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

    /**
     * @group 6699
     */
    public function testSetParameterWithTypeJugglingWorks() : void
    {
        $builder = $this->em->createQueryBuilder()
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
    
    /**
     * @group 5609
     */
    public function testUnknownParameterException() : void
    {
        self::expectException(Query\QueryException::class);
        self::expectExceptionMessage('Invalid parameter: token title is not defined in the query.');
        
        $builder = $this->em->createQueryBuilder()
                            ->select('u')
                            ->from(CmsUser::class, 'u')
                            ->where('u.username = :name');

        $builder->setParameter('title', 'Doctrine');
        $builder->getQuery()->execute();
    }
    
    /**
     * @group 5609
     */
    public function testMissingParameterException() : void
    {
        self::expectException(Query\QueryException::class);
        self::expectExceptionMessage('Missing parameter: no parameter set for token name.');
        
        $builder = $this->em->createQueryBuilder()
                            ->select('u')
                            ->from(CmsUser::class, 'u')
                            ->where('u.username = :name');
        
        $builder->getQuery()->execute();
    }
}
