<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Tests\Mocks\DriverConnectionMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\StatementArrayMock;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Generic\DateTimeModel;
use Doctrine\Tests\OrmTestCase;
use Generator;
use PDO;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function assert;
use function count;

class QueryTest extends OrmTestCase
{
    use VerifyDeprecations;

    /** @var EntityManagerMock */
    protected $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
    }

    public function testGetParameters(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $parameters = new ArrayCollection();

        $this->assertEquals($parameters, $query->getParameters());
    }

    public function testGetParametersHasSomeAlready(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

        $query->setParameter(2, 84);

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(2, 84));

        $this->assertEquals($parameters, $query->getParameters());
    }

    public function testSetParameters(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(1, 'foo'));
        $parameters->add(new Parameter(2, 'bar'));

        $query->setParameters($parameters);

        $this->assertEquals($parameters, $query->getParameters());
    }

    public function testFree(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
        $query->setParameter(2, 84, PDO::PARAM_INT);

        $query->free();

        $this->assertEquals(0, count($query->getParameters()));
    }

    public function testClone(): void
    {
        $dql = 'select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1';

        $query = $this->entityManager->createQuery($dql);
        $query->setParameter(2, 84, PDO::PARAM_INT);
        $query->setHint('foo', 'bar');

        $cloned = clone $query;

        $this->assertEquals($dql, $cloned->getDQL());
        $this->assertEquals(0, count($cloned->getParameters()));
        $this->assertFalse($cloned->getHint('foo'));
    }

    public function testFluentQueryInterface(): void
    {
        $q  = $this->entityManager->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
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

        $this->assertSame($q2, $q);
    }

    /**
     * @group DDC-968
     */
    public function testHints(): void
    {
        $q = $this->entityManager->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q->setHint('foo', 'bar')->setHint('bar', 'baz');

        $this->assertEquals('bar', $q->getHint('foo'));
        $this->assertEquals('baz', $q->getHint('bar'));
        $this->assertEquals(['foo' => 'bar', 'bar' => 'baz'], $q->getHints());
        $this->assertTrue($q->hasHint('foo'));
        $this->assertFalse($q->hasHint('barFooBaz'));
    }

    /**
     * @group DDC-1588
     */
    public function testQueryDefaultResultCache(): void
    {
        $this->entityManager->getConfiguration()->setResultCacheImpl(DoctrineProvider::wrap(new ArrayAdapter()));
        $q = $this->entityManager->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q->enableResultCache();
        $this->assertSame($this->entityManager->getConfiguration()->getResultCacheImpl(), $q->getQueryCacheProfile()->getResultCacheDriver());
    }

    public function testIterateWithNoDistinctAndWrongSelectClause(): void
    {
        $this->expectException(QueryException::class);

        $q = $this->entityManager->createQuery('select u, a from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->iterate();
    }

    public function testToIterableWithNoDistinctAndWrongSelectClause(): void
    {
        $this->expectException(QueryException::class);

        $q = $this->entityManager->createQuery('select u, a from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->toIterable();
    }

    public function testIterateWithNoDistinctAndWithValidSelectClause(): void
    {
        $this->expectException(QueryException::class);

        $q = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->iterate();
    }

    public function testToIterableWithNoDistinctAndWithValidSelectClause(): void
    {
        $this->expectException(QueryException::class);

        $q = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->toIterable();
    }

    public function testIterateWithDistinct(): void
    {
        $q = $this->entityManager->createQuery('SELECT DISTINCT u from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');

        self::assertInstanceOf(IterableResult::class, $q->iterate());
    }

    public function testIterateEmptyResult(): void
    {
        $q = $this->entityManager->createQuery('SELECT u from Doctrine\Tests\Models\CMS\CmsUser u');

        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedForeach
        foreach ($q->toIterable() as $item) {
        }

        self::assertTrue(true);
    }

    /**
     * @group DDC-1697
     */
    public function testCollectionParameters(): void
    {
        $cities = [
            0 => 'Paris',
            3 => 'Cannes',
            9 => 'St Julien',
        ];

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

        $query = $this->entityManager
                ->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)')
                ->setParameter('cities', $cities);

        $parameters = $query->getParameters();
        $parameter  = $parameters->first();

        $this->assertEquals('cities', $parameter->getName());
        $this->assertEquals($cities, $parameter->getValue());
    }

    public function provideProcessParameterValueIterable(): Generator
    {
        $baseArray = [
            0 => 'Paris',
            3 => 'Cannes',
            9 => 'St Julien',
        ];

        $gen = static function () use ($baseArray) {
            yield from $baseArray;
        };

        yield 'simple_array' => [$baseArray];
        yield 'doctrine_collection' => [new ArrayCollection($baseArray)];
        yield 'generator' => [$gen()];
    }

    /**
     * @dataProvider provideProcessParameterValueIterable
     */
    public function testProcessParameterValueIterable(iterable $cities): void
    {
        $query = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)');
        self::assertEquals(
            [
                0 => 'Paris',
                3 => 'Cannes',
                9 => 'St Julien',
            ],
            $query->processParameterValue($cities)
        );
    }

    public function testProcessParameterValueWithIterableEntityShouldNotBeTreatedAsIterable(): void
    {
        $group     = new CmsGroup();
        $group->id = 1;

        $query = $this->entityManager->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.group IN (:group)');
        self::assertEquals(1, $query->processParameterValue($group));
    }

    /**
     * @group DDC-2224
     */
    public function testProcessParameterValueClassMetadata(): void
    {
        $query = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)');
        $this->assertEquals(
            CmsAddress::class,
            $query->processParameterValue($this->entityManager->getClassMetadata(CmsAddress::class))
        );
    }

    public function testProcessParameterValueObject(): void
    {
        $query    = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.user = :user');
        $user     = new CmsUser();
        $user->id = 12345;

        self::assertSame(
            12345,
            $query->processParameterValue($user)
        );
    }

    public function testProcessParameterValueValueObjectWithDriverChain(): void
    {
        $driverChain = new MappingDriverChain();
        $driverChain->addDriver($this->createAnnotationDriver(), 'Foo');
        $this->entityManager->getConfiguration()->setMetadataDriverImpl($driverChain);

        $query = $this->entityManager->createQuery();

        $vo = new DateTimeImmutable('2020-09-01 00:00:00');

        self::assertSame($vo, $query->processParameterValue($vo));
    }

    public function testProcessParameterValueNull(): void
    {
        $query = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.user = :user');

        self::assertNull($query->processParameterValue(null));
    }

    public function testDefaultQueryHints(): void
    {
        $config       = $this->entityManager->getConfiguration();
        $defaultHints = [
            'hint_name_1' => 'hint_value_1',
            'hint_name_2' => 'hint_value_2',
            'hint_name_3' => 'hint_value_3',
        ];

        $config->setDefaultQueryHints($defaultHints);
        $query = $this->entityManager->createQuery();
        $this->assertSame($config->getDefaultQueryHints(), $query->getHints());
        $this->entityManager->getConfiguration()->setDefaultQueryHint('hint_name_1', 'hint_another_value_1');
        $this->assertNotSame($config->getDefaultQueryHints(), $query->getHints());
        $q2 = clone $query;
        $this->assertSame($config->getDefaultQueryHints(), $q2->getHints());
    }

    /**
     * @group DDC-3714
     */
    public function testResultCacheCaching(): void
    {
        $this->entityManager->getConfiguration()->setResultCacheImpl(DoctrineProvider::wrap(new ArrayAdapter()));
        $this->entityManager->getConfiguration()->setQueryCacheImpl(DoctrineProvider::wrap(new ArrayAdapter()));
        $driverConnectionMock = $this->entityManager->getConnection()->getWrappedConnection();
        assert($driverConnectionMock instanceof DriverConnectionMock);
        $stmt = new StatementArrayMock([
            ['id_0' => 1],
        ]);
        $driverConnectionMock->setStatementMock($stmt);
        $res = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->useQueryCache(true)
            ->enableResultCache(60)
            //let it cache
            ->getResult();

        $this->assertCount(1, $res);

        $driverConnectionMock->setStatementMock(null);

        $res = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->useQueryCache(true)
            ->disableResultCache()
            ->getResult();
        $this->assertCount(0, $res);
    }

    /**
     * @group DDC-3741
     */
    public function testSetHydrationCacheProfileNull(): void
    {
        $query = $this->entityManager->createQuery();
        $query->setHydrationCacheProfile(null);
        $this->assertNull($query->getHydrationCacheProfile());
    }

    /**
     * @group 2947
     */
    public function testResultCacheEviction(): void
    {
        $this->entityManager->getConfiguration()->setResultCacheImpl(DoctrineProvider::wrap(new ArrayAdapter()));

        $query = $this->entityManager->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u')
                           ->enableResultCache();

        $driverConnectionMock = $this->entityManager->getConnection()
                                          ->getWrappedConnection();
        assert($driverConnectionMock instanceof DriverConnectionMock);

        $driverConnectionMock->setStatementMock(new StatementArrayMock([['id_0' => 1]]));

        // Performs the query and sets up the initial cache
        self::assertCount(1, $query->getResult());

        $driverConnectionMock->setStatementMock(new StatementArrayMock([['id_0' => 1], ['id_0' => 2]]));

        // Retrieves cached data since expire flag is false and we have a cached result set
        self::assertCount(1, $query->getResult());

        // Performs the query and caches the result set since expire flag is true
        self::assertCount(2, $query->expireResultCache(true)->getResult());

        $driverConnectionMock->setStatementMock(new StatementArrayMock([['id_0' => 1]]));

        // Retrieves cached data since expire flag is false and we have a cached result set
        self::assertCount(2, $query->expireResultCache(false)->getResult());
    }

    /**
     * @group #6162
     */
    public function testSelectJoinSubquery(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u JOIN (SELECT )');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Subquery');
        $query->getSQL();
    }

    /**
     * @group #6162
     */
    public function testSelectFromSubquery(): void
    {
        $query = $this->entityManager->createQuery('select u from (select Doctrine\Tests\Models\CMS\CmsUser c) as u');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Subquery');
        $query->getSQL();
    }

    /**
     * @group 6699
     */
    public function testGetParameterTypeJuggling(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id = ?0');

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

        $query->setParameter(0, 0);

        self::assertCount(1, $query->getParameters());
        self::assertSame(0, $query->getParameter(0)->getValue());
        self::assertSame(0, $query->getParameter('0')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithNameZeroIsNotOverridden(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

        $query->setParameter(0, 0);
        $query->setParameter('name', 'Doctrine');

        self::assertCount(2, $query->getParameters());
        self::assertSame(0, $query->getParameter('0')->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithNameZeroDoesNotOverrideAnotherParameter(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

        $query->setParameter('name', 'Doctrine');
        $query->setParameter(0, 0);

        self::assertCount(2, $query->getParameters());
        self::assertSame(0, $query->getParameter(0)->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    /**
     * @group 6699
     */
    public function testSetParameterWithTypeJugglingWorks(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

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
    public function testResultCacheProfileCanBeRemovedViaSetter(): void
    {
        $this->entityManager->getConfiguration()->setResultCacheImpl(DoctrineProvider::wrap(new ArrayAdapter()));

        $query = $this->entityManager->createQuery('SELECT u FROM ' . CmsUser::class . ' u');
        $query->enableResultCache();
        $query->setResultCacheProfile();

        self::assertNull($query->getQueryCacheProfile());
    }

    /** @group 7527 */
    public function testValuesAreNotBeingResolvedForSpecifiedParameterTypes(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);

        $this->entityManager->setUnitOfWork($unitOfWork);

        $unitOfWork
            ->expects(self::never())
            ->method('getSingleIdentifierValue');

        $query = $this->entityManager->createQuery('SELECT d FROM ' . DateTimeModel::class . ' d WHERE d.datetime = :value');

        $query->setParameter('value', new DateTime(), Type::DATETIME);

        self::assertEmpty($query->getResult());
    }

    /** @group 7982 */
    public function testNonExistentExecutor(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('[Syntax Error] line 0, col -1: Error: Expected SELECT, UPDATE or DELETE, got end of string.');

        $query = $this->entityManager->createQuery('0')->execute();
    }

    /**
     * @group 8106
     */
    public function testGetParameterColonNormalize(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.name = :name');

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/8379');

        $query->setParameter(':name', 'Benjamin');
        $query->setParameter('name', 'Benjamin');

        self::assertCount(1, $query->getParameters());
        self::assertSame('Benjamin', $query->getParameter(':name')->getValue());
        self::assertSame('Benjamin', $query->getParameter('name')->getValue());
    }
}
