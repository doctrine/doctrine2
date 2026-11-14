<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Tests\Mocks\ArrayResultFactory;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Enums\AccessLevel;
use Doctrine\Tests\Models\Enums\City;
use Doctrine\Tests\Models\Enums\UserStatus;
use Doctrine\Tests\Models\Generic\DateTimeModel;
use Doctrine\Tests\OrmTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function array_map;
use function enum_exists;
use function method_exists;

class QueryTest extends OrmTestCase
{
    private EntityManagerMock $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
    }

    public function testGetParameters(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $parameters = new ArrayCollection();

        self::assertEquals($parameters, $query->getParameters());
    }

    public function testGetParametersHasSomeAlready(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
        $query->setParameter(2, 84);

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(2, 84));

        self::assertEquals($parameters, $query->getParameters());
    }

    public function testSetParameters(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');

        $parameters = new ArrayCollection();
        $parameters->add(new Parameter(1, 'foo'));
        $parameters->add(new Parameter(2, 'bar'));

        $query->setParameters($parameters);

        self::assertEquals($parameters, $query->getParameters());
    }

    /** @phpstan-param LockMode::* $lockMode */
    #[DataProvider('provideLockModes')]
    public function testSetLockMode(LockMode|int $lockMode): void
    {
        $query = $this->entityManager->wrapInTransaction(static function (EntityManagerInterface $em) use ($lockMode): Query {
            $query = $em->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
            $query->setLockMode($lockMode);

            return $query;
        });

        self::assertSame($lockMode, $query->getLockMode());
        self::assertSame($lockMode, $query->getHint(Query::HINT_LOCK_MODE));
    }

    /** @phpstan-return list<array{LockMode::*}> */
    public static function provideLockModes(): array
    {
        return [
            [LockMode::PESSIMISTIC_READ],
            [LockMode::PESSIMISTIC_WRITE],
        ];
    }

    public function testSetLockModeNoneDoesNotRequireTransaction(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
        $query->setLockMode(LockMode::NONE);

        self::assertSame(LockMode::NONE, $query->getLockMode());
        self::assertSame(LockMode::NONE, $query->getHint(Query::HINT_LOCK_MODE));
    }

    public function testFree(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1');
        $query->setParameter(2, 84, ParameterType::INTEGER);

        $query->free();

        self::assertCount(0, $query->getParameters());
    }

    public function testClone(): void
    {
        $dql = 'select u from Doctrine\Tests\Models\CMS\CmsUser u where u.username = ?1';

        $query = $this->entityManager->createQuery($dql);
        $query->setParameter(2, 84, ParameterType::INTEGER);
        $query->setHint('foo', 'bar');

        $cloned = clone $query;

        self::assertEquals($dql, $cloned->getDQL());
        self::assertCount(0, $cloned->getParameters());
        self::assertFalse($cloned->getHint('foo'));
    }

    public function testFluentQueryInterface(): void
    {
        $q  = $this->entityManager->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q2 = $q->expireQueryCache(true)
          ->setQueryCacheLifetime(3600)
          ->setQueryCache(null)
          ->expireResultCache(true)
          ->setHint('foo', 'bar')
          ->setHint('bar', 'baz')
          ->setParameter(1, 'bar')
          ->setParameters(new ArrayCollection([new Parameter(2, 'baz')]))
          ->setResultCache(null)
          ->setResultCacheId('foo')
          ->setDQL('foo')
          ->setFirstResult(10)
          ->setMaxResults(10);

        self::assertSame($q2, $q);
    }

    #[Group('DDC-968')]
    public function testHints(): void
    {
        $q = $this->entityManager->createQuery('select a from Doctrine\Tests\Models\CMS\CmsArticle a');
        $q->setHint('foo', 'bar')->setHint('bar', 'baz');

        self::assertEquals('bar', $q->getHint('foo'));
        self::assertEquals('baz', $q->getHint('bar'));
        self::assertEquals(['foo' => 'bar', 'bar' => 'baz'], $q->getHints());
        self::assertTrue($q->hasHint('foo'));
        self::assertFalse($q->hasHint('barFooBaz'));
    }

    public function testToIterableWithNoDistinctAndWrongSelectClause(): void
    {
        $this->expectException(QueryException::class);

        $q = $this->entityManager->createQuery('select u, a from Doctrine\Tests\Models\CMS\CmsUser u LEFT JOIN u.articles a');
        $q->toIterable();
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

        self::assertInstanceOf(Generator::class, $q->toIterable());
    }

    public function testIterateEmptyResult(): void
    {
        $q = $this->entityManager->createQuery('SELECT u from Doctrine\Tests\Models\CMS\CmsUser u');

        // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedForeach
        foreach ($q->toIterable() as $item) {
        }

        self::assertTrue(true);
    }

    #[Group('DDC-1697')]
    public function testCollectionParameters(): void
    {
        $cities = [
            0 => 'Paris',
            3 => 'Cannes',
            9 => 'St Julien',
        ];

        $query = $this->entityManager
                ->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)')
                ->setParameter('cities', $cities);

        $parameters = $query->getParameters();
        $parameter  = $parameters->first();

        self::assertEquals('cities', $parameter->getName());
        self::assertEquals($cities, $parameter->getValue());
    }

    #[Group('DDC-1697')]
    public function testExplicitCollectionParameters(): void
    {
        $cities = [
            0 => 'Paris',
            3 => 'Cannes',
            9 => 'St Julien',
        ];

        $query = $this->entityManager
                ->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)')
                ->setParameter('cities', $cities, ArrayParameterType::STRING);

        $parameters = $query->getParameters();
        $parameter  = $parameters->first();

        self::assertEquals('cities', $parameter->getName());
        self::assertEquals($cities, $parameter->getValue());
    }

    /** @phpstan-return Generator<string, array{iterable}> */
    public static function provideProcessParameterValueIterable(): Generator
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
        yield 'array_of_enum' => [array_map([City::class, 'from'], $baseArray)];
    }

    #[DataProvider('provideProcessParameterValueIterable')]
    public function testProcessParameterValueIterable(iterable $cities): void
    {
        $query = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)');
        self::assertEquals(
            [
                0 => 'Paris',
                3 => 'Cannes',
                9 => 'St Julien',
            ],
            $query->processParameterValue($cities),
        );
    }

    public function testProcessParameterValueWithIterableEntityShouldNotBeTreatedAsIterable(): void
    {
        $group     = new CmsGroup();
        $group->id = 1;

        $query = $this->entityManager->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.group IN (:group)');
        self::assertEquals(1, $query->processParameterValue($group));
    }

    #[Group('DDC-2224')]
    public function testProcessParameterValueClassMetadata(): void
    {
        $query = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.city IN (:cities)');
        self::assertEquals(
            CmsAddress::class,
            $query->processParameterValue($this->entityManager->getClassMetadata(CmsAddress::class)),
        );
    }

    public function testProcessParameterValueObject(): void
    {
        $query    = $this->entityManager->createQuery('SELECT a FROM Doctrine\Tests\Models\CMS\CmsAddress a WHERE a.user = :user');
        $user     = new CmsUser();
        $user->id = 12345;

        self::assertSame(
            12345,
            $query->processParameterValue($user),
        );
    }

    public function testProcessParameterValueValueObjectWithDriverChain(): void
    {
        $driverChain = new MappingDriverChain();
        $driverChain->addDriver($this->createAttributeDriver(), 'Foo');
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

    public function testProcessParameterValueBackedEnum(): void
    {
        $query = $this->entityManager->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.status = :status');

        self::assertSame(['active'], $query->processParameterValue([UserStatus::Active]));
        self::assertSame([2], $query->processParameterValue([AccessLevel::User]));
    }

    public function testProcessParameterValueBackedEnumArray(): void
    {
        $query = $this->entityManager->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.status IN (:status)');

        self::assertSame(['active'], $query->processParameterValue([UserStatus::Active]));
        self::assertSame([2], $query->processParameterValue([AccessLevel::User]));
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
        self::assertSame($config->getDefaultQueryHints(), $query->getHints());
        $this->entityManager->getConfiguration()->setDefaultQueryHint('hint_name_1', 'hint_another_value_1');
        self::assertNotSame($config->getDefaultQueryHints(), $query->getHints());
        $q2 = clone $query;
        self::assertSame($config->getDefaultQueryHints(), $q2->getHints());
    }

    #[Group('DDC-3714')]
    public function testResultCacheCaching(): void
    {
        $entityManager = $this->createTestEntityManagerWithConnection(
            $this->createConnection(
                ArrayResultFactory::createDriverResultFromArray([
                    ['id_0' => 1],
                ]),
                ArrayResultFactory::createDriverResultFromArray([]),
            ),
        );

        $entityManager->getConfiguration()->setResultCache(new ArrayAdapter());
        $entityManager->getConfiguration()->setQueryCache(new ArrayAdapter());

        $res = $entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->useQueryCache(true)
            ->enableResultCache(60)
            //let it cache
            ->getResult();

        self::assertCount(1, $res);

        $res = $entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u')
            ->useQueryCache(true)
            ->disableResultCache()
            ->getResult();
        self::assertCount(0, $res);
    }

    #[Group('DDC-3741')]
    public function testSetHydrationCacheProfileNull(): void
    {
        $query = $this->entityManager->createQuery();
        $query->setHydrationCacheProfile(null);
        self::assertNull($query->getHydrationCacheProfile());
    }

    #[Group('2947')]
    public function testResultCacheEviction(): void
    {
        $entityManager = $this->createTestEntityManagerWithConnection(
            $this->createConnection(
                ArrayResultFactory::createDriverResultFromArray([
                    ['id_0' => 1],
                ]),
                ArrayResultFactory::createDriverResultFromArray([
                    ['id_0' => 1],
                    ['id_0' => 2],
                ]),
                ArrayResultFactory::createDriverResultFromArray([
                    ['id_0' => 1],
                ]),
            ),
        );

        $entityManager->getConfiguration()->setResultCache(new ArrayAdapter());

        $query = $entityManager->createQuery('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u')
            ->enableResultCache();

        // Performs the query and sets up the initial cache
        self::assertCount(1, $query->getResult());

        // Retrieves cached data since expire flag is false and we have a cached result set
        self::assertCount(1, $query->getResult());

        // Performs the query and caches the result set since expire flag is true
        self::assertCount(2, $query->expireResultCache()->getResult());

        // Retrieves cached data since expire flag is false and we have a cached result set
        self::assertCount(2, $query->expireResultCache(false)->getResult());
    }

    #[Group('#6162')]
    public function testSelectJoinSubquery(): void
    {
        $query = $this->entityManager->createQuery('select u from Doctrine\Tests\Models\CMS\CmsUser u JOIN (SELECT )');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Subquery');
        $query->getSQL();
    }

    #[Group('#6162')]
    public function testSelectFromSubquery(): void
    {
        $query = $this->entityManager->createQuery('select u from (select Doctrine\Tests\Models\CMS\CmsUser c) as u');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Subquery');
        $query->getSQL();
    }

    #[Group('6699')]
    public function testGetParameterTypeJuggling(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id = ?0');

        $query->setParameter(0, 0);

        self::assertCount(1, $query->getParameters());
        self::assertSame(0, $query->getParameter(0)->getValue());
        self::assertSame(0, $query->getParameter('0')->getValue());
    }

    #[Group('6699')]
    public function testSetParameterWithNameZeroIsNotOverridden(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $query->setParameter(0, 0);
        $query->setParameter('name', 'Doctrine');

        self::assertCount(2, $query->getParameters());
        self::assertSame(0, $query->getParameter('0')->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    #[Group('6699')]
    public function testSetParameterWithNameZeroDoesNotOverrideAnotherParameter(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $query->setParameter('name', 'Doctrine');
        $query->setParameter(0, 0);

        self::assertCount(2, $query->getParameters());
        self::assertSame(0, $query->getParameter(0)->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    #[Group('6699')]
    public function testSetParameterWithTypeJugglingWorks(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.id != ?0 and u.username = :name');

        $query->setParameter('0', 1);
        $query->setParameter('name', 'Doctrine');
        $query->setParameter(0, 2);
        $query->setParameter('0', 3);

        self::assertCount(2, $query->getParameters());
        self::assertSame(3, $query->getParameter(0)->getValue());
        self::assertSame(3, $query->getParameter('0')->getValue());
        self::assertSame('Doctrine', $query->getParameter('name')->getValue());
    }

    #[Group('6748')]
    public function testResultCacheProfileCanBeRemovedViaSetter(): void
    {
        $this->entityManager->getConfiguration()->setResultCache(new ArrayAdapter());

        $query = $this->entityManager->createQuery('SELECT u FROM ' . CmsUser::class . ' u');
        $query->enableResultCache();
        $query->setResultCacheProfile(null);

        self::assertNull($query->getQueryCacheProfile());
    }

    #[Group('7527')]
    public function testValuesAreNotBeingResolvedForSpecifiedParameterTypes(): void
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);

        $this->entityManager->setUnitOfWork($unitOfWork);

        $unitOfWork
            ->expects(self::never())
            ->method('getSingleIdentifierValue');

        $query = $this->entityManager->createQuery('SELECT d FROM ' . DateTimeModel::class . ' d WHERE d.datetime = :value');

        $query->setParameter('value', new DateTime(), Types::DATETIME_MUTABLE);

        self::assertEmpty($query->getResult());
    }

    #[Group('7982')]
    public function testNonExistentExecutor(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('[Syntax Error] line 0, col -1: Error: Expected SELECT, UPDATE or DELETE, got end of string.');

        $this->entityManager->createQuery('0')->execute();
    }

    #[Group('8106')]
    public function testGetParameterColonNormalize(): void
    {
        $query = $this->entityManager->createQuery('select u from ' . CmsUser::class . ' u where u.name = :name');

        $query->setParameter(':name', 'Benjamin');
        $query->setParameter('name', 'Benjamin');

        self::assertCount(1, $query->getParameters());
        self::assertSame('Benjamin', $query->getParameter(':name')->getValue());
        self::assertSame('Benjamin', $query->getParameter('name')->getValue());
    }

    public function testGetQueryCacheDriverWithDefaults(): void
    {
        $cache         = $this->createMock(CacheItemPoolInterface::class);
        $cacheItemMock = $this->createStub(CacheItemInterface::class);
        $cacheItemMock->method('set')->willReturnSelf();
        $cacheItemMock->method('expiresAfter')->willReturnSelf();
        $cache
            ->expects(self::atLeastOnce())
            ->method('getItem')
            ->willReturn($cacheItemMock);

        $this->entityManager->getConfiguration()->setQueryCache($cache);
        $this->entityManager
            ->createQuery('select u from ' . CmsUser::class . ' u')
            ->getSQL();
    }

    public function testGetQueryCacheDriverWithCacheExplicitlySet(): void
    {
        $cache         = $this->createMock(CacheItemPoolInterface::class);
        $cacheItemMock = $this->createStub(CacheItemInterface::class);
        $cacheItemMock->method('set')->willReturnSelf();
        $cacheItemMock->method('expiresAfter')->willReturnSelf();
        $cache
            ->expects(self::atLeastOnce())
            ->method('getItem')
            ->willReturn($cacheItemMock);

        $this->entityManager
            ->createQuery('select u from ' . CmsUser::class . ' u')
            ->setQueryCache($cache)
            ->getSQL();
    }

    private function createConnection(Result ...$results): Connection
    {
        $driverConnection = $this->createStub(Driver\Connection::class);
        $driverConnection->method('query')
            ->willReturnOnConsecutiveCalls(...$results);

        $platform = $this->getMockBuilder(AbstractPlatform::class)
            ->setConstructorArgs(enum_exists(UnquotedIdentifierFolding::class) ? [UnquotedIdentifierFolding::UPPER] : [])
            ->getMock();
        if (method_exists($platform, 'getUnquotedIdentifierFolding')) {
            $platform->method('getUnquotedIdentifierFolding')->willReturn(UnquotedIdentifierFolding::NONE);
        }

        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $driver = $this->createStub(Driver::class);
        $driver->method('connect')
            ->willReturn($driverConnection);
        $driver->method('getDatabasePlatform')
            ->willReturn($platform);

        return new Connection([], $driver);
    }
}
