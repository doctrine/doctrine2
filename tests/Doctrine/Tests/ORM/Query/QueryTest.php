<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use DateTime;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Tests\Mocks\DriverConnectionMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\StatementArrayMock;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Generic\DateTimeModel;
use Doctrine\Tests\OrmTestCase;

class QueryTest extends OrmTestCase
{
    /** @var EntityManagerMock */
    protected $em;

    protected function setUp() : void
    {
        $this->em = $this->getTestEntityManager();
    }

    public function testGetParameters() : void
    {
        $query = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $parameters = new ArrayCollection();

        self::assertEquals($parameters, $query->getParameters());
    }

    public function testGetParametersHasSomeAlready() : void
    {
        $query = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
        $query->setParameter(2, 84);

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(2, 84));

        self::assertEquals($parameters, $query->getParameters());
    }

    public function testSetParameters() : void
    {
        $query = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(1, 'foo'));
        $parameters->add(new Parameter(2, 'bar'));

        $query->setParameters($parameters);

        self::assertEquals($parameters, $query->getParameters());
    }

    public function testFree() : void
    {
        $query = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
        $query->setParameter(2, 84, ParameterType::INTEGER);

        $query->free();

        self::assertCount(0, $query->getParameters());
    }

    public function testClone() : void
    {
        $dql = 'select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1';

        $query = $this->em->createQuery($dql);
        $query->setParameter(2, 84, ParameterType::INTEGER);
        $query->setHint('foo', 'bar');

        $cloned = clone $query;

        self::assertEquals($dql, $cloned->getDQL());
        self::assertCount(0, $cloned->getParameters());
        self::assertFalse($cloned->getHint('foo'));
    }

    public function testFluentQueryInterface() : void
    {
        $q  = $this->em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q2 = $q->expireQueryCache(true)
          ->setQueryCacheLifetime(3600)
          ->setQueryCacheDriver(null)
          ->expireResultCache(true)
          ->setHint('foo', 'bar')
          ->setHint('bar', 'baz')
          ->setParameter(1, 'bar')
          ->setParameters(new ArrayCollection([new Parameter(2, 'baz')]))
          ->setResultCacheDriver(null)
          ->setResultCacheId('foo')
          ->setDQL('foo')
          ->setFirstResult(10)
          ->setMaxResults(10);

        self::assertSame($q2, $q);
    }

    /**
     * @group DDC-968
     */
    public function testHints() : void
    {
        $q = $this->em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q->setHint('foo', 'bar')->setHint('bar', 'baz');

        self::assertEquals('bar', $q->getHint('foo'));
        self::assertEquals('baz', $q->getHint('bar'));
        self::assertEquals(['foo' => 'bar', 'bar' => 'baz'], $q->getHints());
        self::assertTrue($q->hasHint('foo'));
        self::assertFalse($q->hasHint('barFooBaz'));
    }

    /**
     * @group DDC-1588
     */
    public function testQueryDefaultResultCache() : void
    {
        $this->em->getConfiguration()->setResultCacheImpl(new ArrayCache());
        $q = $this->em->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q->enableResultCache();
        self::assertSame($this->em->getConfiguration()->getResultCacheImpl(), $q->getQueryCacheProfile()->getResultCacheDriver());
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     */
    public function testIterateWithNoDistinctAndWrongSelectClause() : void
    {
        $q = $this->em->createQuery('select u, a from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->iterate();
    }

    /**
     * @expectedException Doctrine\ORM\Query\QueryException
     */
    public function testIterateWithNoDistinctAndWithValidSelectClause() : void
    {
        $q = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->iterate();
    }

    public function testIterateWithDistinct() : void
    {
        $q = $this->em->createQuery('SELECT DISTINCT u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');

        self::assertInstanceOf(IterableResult::class, $q->iterate());
    }

    /**
     * @group DDC-1697
     */
    public function testCollectionParameters() : void
    {
        $cities = [
            0 => 'Paris',
            3 => 'Canne',
            9 => 'St Julien',
        ];

        $query = $this->em
                ->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)')
                ->setParameter('cities', $cities);

        $parameters = $query->getParameters();
        $parameter  = $parameters->first();

        self::assertEquals('cities', $parameter->getName());
        self::assertEquals($cities, $parameter->getValue());
    }

    /**
     * @group DDC-2224
     */
    public function testProcessParameterValueClassMetadata() : void
    {
        $query = $this->em->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)');
        self::assertEquals(
            CmsAddress::class,
            $query->processParameterValue($this->em->getClassMetadata(CmsAddress::class))
        );
    }

    public function testProcessParameterValueObject() : void
    {
        $query    = $this->em->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.user = :user');
        $user     = new CmsUser();
        $user->id = 12345;

        self::assertSame(
            12345,
            $query->processParameterValue($user)
        );
    }

    public function testProcessParameterValueNull() : void
    {
        $query = $this->em->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.user = :user');

        self::assertNull($query->processParameterValue(null));
    }

    public function testDefaultQueryHints() : void
    {
        $config       = $this->em->getConfiguration();
        $defaultHints = [
            'hint_name_1' => 'hint_value_1',
            'hint_name_2' => 'hint_value_2',
            'hint_name_3' => 'hint_value_3',
        ];

        $config->setDefaultQueryHints($defaultHints);
        $query = $this->em->createQuery();
        self::assertSame($config->getDefaultQueryHints(), $query->getHints());
        $this->em->getConfiguration()->setDefaultQueryHint('hint_name_1', 'hint_another_value_1');
        self::assertNotSame($config->getDefaultQueryHints(), $query->getHints());
        $q2 = clone $query;
        self::assertSame($config->getDefaultQueryHints(), $q2->getHints());
    }

    /**
     * @group DDC-3714
     */
    public function testResultCacheCaching() : void
    {
        $this->em->getConfiguration()->setResultCacheImpl(new ArrayCache());
        $this->em->getConfiguration()->setQueryCacheImpl(new ArrayCache());
        /** @var DriverConnectionMock $driverConnectionMock */
        $driverConnectionMock = $this->em->getConnection()->getWrappedConnection();
        $stmt                 = new StatementArrayMock([
            ['c0' => 1],
        ]);
        $driverConnectionMock->setStatementMock($stmt);
        $res = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->useQueryCache(true)
            ->enableResultCache(60)
            //let it cache
            ->getResult();

        self::assertCount(1, $res);

        $driverConnectionMock->setStatementMock(null);

        $res = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->useQueryCache(true)
            ->disableResultCache()
            ->getResult();
        self::assertCount(0, $res);
    }

    /**
     * @group DDC-3741
     */
    public function testSetHydrationCacheProfileNull() : void
    {
        $query = $this->em->createQuery();
        $query->setHydrationCacheProfile(null);

        self::assertNull($query->getHydrationCacheProfile());
    }

    /**
     * @group 2947
     */
    public function testResultCacheEviction() : void
    {
        $this->em->getConfiguration()->setResultCacheImpl(new ArrayCache());

        $query = $this->em->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u')
                          ->enableResultCache();

        /** @var DriverConnectionMock $driverConnectionMock */
        $driverConnectionMock = $this->em->getConnection()
            ->getWrappedConnection();

        // Performs the query and sets up the initial cache
        self::assertCount(0, $query->getResult());

        $driverConnectionMock->setStatementMock(new StatementArrayMock([['c0' => 1]]));

        // Performs the query and sets up the initial cache
        self::assertCount(1, $query->expireResultCache(true)->getResult());

        $driverConnectionMock->setStatementMock(new StatementArrayMock([['c0' => 1], ['c0' => 2]]));

        // Retrieves cached data since expire flag is false and we have a cached result set
        self::assertCount(1, $query->expireResultCache(false)->getResult());

        // Performs the query and caches the result set since expire flag is true
        self::assertCount(2, $query->expireResultCache(true)->getResult());

        $driverConnectionMock->setStatementMock(new StatementArrayMock([['c0' => 1]]));

        // Retrieves cached data since expire flag is false and we have a cached result set
        self::assertCount(2, $query->expireResultCache(false)->getResult());
    }

    /**
     * @group #6162
     */
    public function testSelectJoinSubquery() : void
    {
        $query = $this->em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u JOIN (SELECT )');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Subquery');
        $query->getSQL();
    }

    /**
     * @group #6162
     */
    public function testSelectFromSubquery() : void
    {
        $query = $this->em->createQuery('select u from (select Doctrine\Tests\Models\CMS\CmsUser c) as u');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Subquery');
        $query->getSQL();
    }

    /**
     * @group 6699
     */
    public function testGetParameterTypeJuggling() : void
    {
        $query = $this->em->createQuery('select u from ' . CmsUser::class . ' u where u.id = ?0');

        $query->setParameter(0, 0);

        self::assertCount(1, $query->getParameters());
        self::assertSame(0, $query->getParameter(0)->getValue());
        self::assertSame(0, $query->getParameter('0')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithNameZeroIsNotOverridden() : void
    {
        $query = $this->em->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $query->setParameter(0, 0);
        $query->setParameter('name', 'Doctrine');

        self::assertCount(2, $query->getParameters());
        self::assertSame(0, $query->getParameter('0')->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithNameZeroDoesNotOverrideAnotherParameter() : void
    {
        $query = $this->em->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $query->setParameter('name', 'Doctrine');
        $query->setParameter(0, 0);

        self::assertCount(2, $query->getParameters());
        self::assertSame(0, $query->getParameter(0)->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithTypeJugglingWorks() : void
    {
        $query = $this->em->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $query->setParameter('0', 1);
        $query->setParameter('name', 'Doctrine');
        $query->setParameter(0, 2);
        $query->setParameter('0', 3);

        self::assertCount(2, $query->getParameters());
        self::assertSame(3, $query->getParameter(0)->getValue());
        self::assertSame(3, $query->getParameter('0')->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    /**
     * @group 6748
     */
    public function testResultCacheProfileCanBeRemovedViaSetter() : void
    {
        $this->em->getConfiguration()->setResultCacheImpl(new ArrayCache());

        $query = $this->em->createQuery('SELECT u FROM ' . CmsUser::class . ' u');
        $query->enableResultCache();
        $query->setResultCacheProfile();

        self::assertAttributeSame(null, 'queryCacheProfile', $query);
    }

    /** @group 7527 */
    public function testValuesAreNotBeingResolvedForSpecifiedParameterTypes() : void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);

        $this->em->setUnitOfWork($unitOfWork);

        $unitOfWork
            ->expects(self::never())
            ->method('getSingleIdentifierValue');

        $query = $this->em->createQuery('SELECT d FROM ' . DateTimeModel::class . ' d WHERE d.datetime = :value');

        $query->setParameter('value', new DateTime(), Type::DATETIME);

        self::assertEmpty($query->getResult());
    }
}
