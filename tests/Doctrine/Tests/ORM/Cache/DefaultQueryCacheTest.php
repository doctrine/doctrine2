<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\DefaultQueryCache;
use Doctrine\ORM\Cache\EntityCacheEntry;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\QueryCache;
use Doctrine\ORM\Cache\QueryCacheEntry;
use Doctrine\ORM\Cache\QueryCacheKey;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\CacheMetadata;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\Mocks\CacheRegionMock;
use Doctrine\Tests\Mocks\TimestampRegionMock;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\Restaurant;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\Generic\BooleanModel;
use Doctrine\Tests\OrmTestCase;
use function microtime;
use function sprintf;

/**
 * @group DDC-2183
 */
class DefaultQueryCacheTest extends OrmTestCase
{
    /** @var DefaultQueryCache */
    private $queryCache;

    /** @var EntityManagerInterface */
    private $em;

    /** @var CacheRegionMock */
    private $region;

    /** @var CacheFactoryDefaultQueryCacheTest */
    private $cacheFactory;

    protected function setUp() : void
    {
        parent::setUp();

        $this->enableSecondLevelCache();

        $this->em           = $this->getTestEntityManager();
        $this->region       = new CacheRegionMock();
        $this->queryCache   = new DefaultQueryCache($this->em, $this->region);
        $this->cacheFactory = new CacheFactoryDefaultQueryCacheTest($this->queryCache, $this->region);

        $this->em->getConfiguration()
            ->getSecondLevelCacheConfiguration()
            ->setCacheFactory($this->cacheFactory);
    }

    public function testImplementQueryCache() : void
    {
        self::assertInstanceOf(QueryCache::class, $this->queryCache);
    }

    public function testGetRegion() : void
    {
        self::assertSame($this->region, $this->queryCache->getRegion());
    }

    public function testClearShouldEvictRegion() : void
    {
        $this->queryCache->clear();

        self::assertArrayHasKey('evictAll', $this->region->calls);
        self::assertCount(1, $this->region->calls['evictAll']);
    }

    public function testPutBasicQueryResult() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $name   = sprintf('Country %d', $i);
            $entity = new Country($name);

            $entity->setId($i);

            $result[] = $entity;

            $uow->registerManaged($entity, ['id' => $entity->getId()], ['name' => $entity->getName()]);
        }

        self::assertTrue($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(5, $this->region->calls['put']);

        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][0]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][1]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][2]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][3]['key']);
        self::assertInstanceOf(QueryCacheKey::class, $this->region->calls['put'][4]['key']);

        self::assertInstanceOf(EntityCacheEntry::class, $this->region->calls['put'][0]['entry']);
        self::assertInstanceOf(EntityCacheEntry::class, $this->region->calls['put'][1]['entry']);
        self::assertInstanceOf(EntityCacheEntry::class, $this->region->calls['put'][2]['entry']);
        self::assertInstanceOf(EntityCacheEntry::class, $this->region->calls['put'][3]['entry']);
        self::assertInstanceOf(QueryCacheEntry::class, $this->region->calls['put'][4]['entry']);
    }

    public function testPutToOneAssociationQueryResult() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);

        for ($i = 0; $i < 4; $i++) {
            $state = new State(sprintf('State %d', $i));
            $city  = new City(sprintf('City %d', $i), $state);

            $city->setId($i);
            $state->setId($i * 2);

            $result[] = $city;

            $uow->registerManaged($state, ['id' => $state->getId()], ['name' => $city->getName()]);
            $uow->registerManaged($city, ['id' => $city->getId()], ['name' => $city->getName(), 'state' => $state]);
        }

        self::assertTrue($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(9, $this->region->calls['put']);

        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][0]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][1]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][2]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][3]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][4]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][5]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][6]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][7]['key']);
        self::assertInstanceOf(QueryCacheKey::class, $this->region->calls['put'][8]['key']);
    }

    public function testPutToOneAssociation2LevelsQueryResult() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);
        $rsm->addJoinedEntityFromClassMetadata(Country::class, 'co', 's', 'country', ['id' => 'country_id', 'name' => 'country_name']);

        for ($i = 0; $i < 4; $i++) {
            $country = new Country(sprintf('Country %d', $i));
            $state   = new State(sprintf('State %d', $i), $country);
            $city    = new City(sprintf('City %d', $i), $state);

            $city->setId($i);
            $state->setId($i * 2);
            $country->setId($i * 3);

            $result[] = $city;

            $uow->registerManaged($country, ['id' => $country->getId()], ['name' => $country->getName()]);
            $uow->registerManaged($state, ['id' => $state->getId()], ['name' => $state->getName(), 'country' => $country]);
            $uow->registerManaged($city, ['id' => $city->getId()], ['name' => $city->getName(), 'state' => $state]);
        }

        self::assertTrue($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(13, $this->region->calls['put']);

        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][0]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][1]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][2]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][3]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][4]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][5]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][6]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][7]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][8]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][9]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][10]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][11]['key']);
        self::assertInstanceOf(QueryCacheKey::class, $this->region->calls['put'][12]['key']);
    }

    public function testPutToOneAssociationNullQueryResult() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);

        for ($i = 0; $i < 4; $i++) {
            $city = new City(sprintf('City %d', $i), null);

            $city->setId($i);

            $result[] = $city;

            $uow->registerManaged($city, ['id' => $city->getId()], ['name' => $city->getName(), 'state' => null]);
        }

        self::assertTrue($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(5, $this->region->calls['put']);

        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][0]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][1]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][2]['key']);
        self::assertInstanceOf(EntityCacheKey::class, $this->region->calls['put'][3]['key']);
        self::assertInstanceOf(QueryCacheKey::class, $this->region->calls['put'][4]['key']);
    }

    public function testPutToManyAssociationQueryResult() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(State::class, 's');
        $rsm->addJoinedEntityFromClassMetadata(City::class, 'c', 's', 'cities', ['id' => 'c_id', 'name' => 'c_name']);

        for ($i = 0; $i < 4; $i++) {
            $state = new State(sprintf('State %d', $i));
            $city1 = new City('City 1', $state);
            $city2 = new City('City 2', $state);

            $state->setId($i);
            $city1->setId($i + 11);
            $city2->setId($i + 22);

            $result[] = $state;

            $state->addCity($city1);
            $state->addCity($city2);

            $uow->registerManaged($city1, ['id' => $city1->getId()], ['name' => $city1->getName(), 'state' => $state]);
            $uow->registerManaged($city2, ['id' => $city2->getId()], ['name' => $city2->getName(), 'state' => $state]);
            $uow->registerManaged($state, ['id' => $state->getId()], ['name' => $state->getName(), 'cities' => $state->getCities()]);
        }

        self::assertTrue($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(13, $this->region->calls['put']);
    }

    public function testGetBasicQueryResult() : void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ]
        );

        $data = [
            ['id' => 1, 'name' => 'Foo'],
            ['id' => 2, 'name' => 'Bar'],
        ];

        $this->region->addReturn('get', $entry);

        $this->region->addReturn(
            'getMultiple',
            [
                new EntityCacheEntry(Country::class, $data[0]),
                new EntityCacheEntry(Country::class, $data[1]),
            ]
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        $result = $this->queryCache->get($key, $rsm);

        self::assertCount(2, $result);
        self::assertInstanceOf(Country::class, $result[0]);
        self::assertInstanceOf(Country::class, $result[1]);
        self::assertEquals(1, $result[0]->getId());
        self::assertEquals(2, $result[1]->getId());
        self::assertEquals('Foo', $result[0]->getName());
        self::assertEquals('Bar', $result[1]->getName());
    }

    public function testGetWithAssociation() : void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ]
        );

        $data = [
            ['id' => 1, 'name' => 'Foo'],
            ['id' => 2, 'name' => 'Bar'],
        ];

        $this->region->addReturn('get', $entry);

        $this->region->addReturn(
            'getMultiple',
            [
                new EntityCacheEntry(Country::class, $data[0]),
                new EntityCacheEntry(Country::class, $data[1]),
            ]
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        $result = $this->queryCache->get($key, $rsm);

        self::assertCount(2, $result);
        self::assertInstanceOf(Country::class, $result[0]);
        self::assertInstanceOf(Country::class, $result[1]);
        self::assertEquals(1, $result[0]->getId());
        self::assertEquals(2, $result[1]->getId());
        self::assertEquals('Foo', $result[0]->getName());
        self::assertEquals('Bar', $result[1]->getName());
    }

    public function testCancelPutResultIfEntityPutFails() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $name   = sprintf('Country %d', $i);
            $entity = new Country($name);

            $entity->setId($i);

            $result[] = $entity;

            $uow->registerManaged($entity, ['id' => $entity->getId()], ['name' => $entity->getName()]);
        }

        $this->region->addReturn('put', false);

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(1, $this->region->calls['put']);
    }

    public function testCancelPutResultIfAssociationEntityPutFails() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);

        $state = new State('State 1');
        $city  = new City('City 2', $state);

        $state->setId(1);
        $city->setId(11);

        $result[] = $city;

        $uow->registerManaged($state, ['id' => $state->getId()], ['name' => $city->getName()]);
        $uow->registerManaged($city, ['id' => $city->getId()], ['name' => $city->getName(), 'state' => $state]);

        $this->region->addReturn('put', true);  // put root entity
        $this->region->addReturn('put', false); // association fails

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
    }

    public function testCancelPutToManyAssociationQueryResult() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(State::class, 's');
        $rsm->addJoinedEntityFromClassMetadata(City::class, 'c', 's', 'cities', ['id' => 'c_id', 'name' => 'c_name']);

        $state = new State('State');
        $city1 = new City('City 1', $state);
        $city2 = new City('City 2', $state);

        $state->setId(1);
        $city1->setId(11);
        $city2->setId(22);

        $result[] = $state;

        $state->addCity($city1);
        $state->addCity($city2);

        $uow->registerManaged($city1, ['id' => $city1->getId()], ['name' => $city1->getName(), 'state' => $state]);
        $uow->registerManaged($city2, ['id' => $city2->getId()], ['name' => $city2->getName(), 'state' => $state]);
        $uow->registerManaged($state, ['id' => $state->getId()], ['name' => $state->getName(), 'cities' => $state->getCities()]);

        $this->region->addReturn('put', true);  // put root entity
        $this->region->addReturn('put', false); // collection association fails

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(2, $this->region->calls['put']);
    }

    public function testIgnoreCacheNonGetMode() : void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0, Cache::MODE_PUT);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ]
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        $this->region->addReturn('get', $entry);

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testIgnoreCacheNonPutMode() : void
    {
        $result = [];
        $uow    = $this->em->getUnitOfWork();
        $key    = new QueryCacheKey('query.key1', 0, Cache::MODE_GET);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $name   = sprintf('Country %d', $i);
            $entity = new Country($name);

            $entity->setId($i);

            $result[] = $entity;

            $uow->registerManaged($entity, ['id' => $entity->getId()], ['name' => $entity->getName()]);
        }

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
    }

    public function testGetShouldIgnoreOldQueryCacheEntryResult() : void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 50);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ]
        );

        $data = [
            ['id' => 1, 'name' => 'Foo'],
            ['id' => 2, 'name' => 'Bar'],
        ];

        $entry->time = microtime(true) - 100;

        $this->region->addReturn('get', $entry);

        $this->region->addReturn(
            'getMultiple',
            [
                new EntityCacheEntry(Country::class, $data[0]),
                new EntityCacheEntry(Country::class, $data[1]),
            ]
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testGetShouldIgnoreNonQueryCacheEntryResult() : void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new \ArrayObject(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ]
        );

        $data = [
            ['id' => 1, 'name' => 'Foo'],
            ['id' => 2, 'name' => 'Bar'],
        ];

        $this->region->addReturn('get', $entry);

        $this->region->addReturn(
            'getMultiple',
            [
                new EntityCacheEntry(Country::class, $data[0]),
                new EntityCacheEntry(Country::class, $data[1]),
            ]
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testGetShouldIgnoreMissingEntityQueryCacheEntry() : void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ]
        );

        $this->region->addReturn('get', $entry);
        $this->region->addReturn('getMultiple', [null]);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testGetAssociationValue() : void
    {
        $reflection = new \ReflectionMethod($this->queryCache, 'getAssociationValue');
        $rsm        = new ResultSetMappingBuilder($this->em);
        $key        = new QueryCacheKey('query.key1', 0);

        $reflection->setAccessible(true);

        $germany  = new Country('Germany');
        $bavaria  = new State('Bavaria', $germany);
        $wurzburg = new City('Würzburg', $bavaria);
        $munich   = new City('Munich', $bavaria);

        $bavaria->addCity($munich);
        $bavaria->addCity($wurzburg);

        $munich->addAttraction(new Restaurant('Reinstoff', $munich));
        $munich->addAttraction(new Restaurant('Schneider Weisse', $munich));
        $wurzburg->addAttraction(new Restaurant('Fischers Fritz', $wurzburg));

        $rsm->addRootEntityFromClassMetadata(State::class, 's');
        $rsm->addJoinedEntityFromClassMetadata(City::class, 'c', 's', 'cities', [
            'id'   => 'c_id',
            'name' => 'c_name',
        ]);
        $rsm->addJoinedEntityFromClassMetadata(Restaurant::class, 'a', 'c', 'attractions', [
            'id'   => 'a_id',
            'name' => 'a_name',
        ]);

        $cities      = $reflection->invoke($this->queryCache, $rsm, 'c', $bavaria);
        $attractions = $reflection->invoke($this->queryCache, $rsm, 'a', $bavaria);

        self::assertCount(2, $cities);
        self::assertCount(2, $attractions);

        self::assertInstanceOf(Collection::class, $cities);
        self::assertInstanceOf(Collection::class, $attractions[0]);
        self::assertInstanceOf(Collection::class, $attractions[1]);

        self::assertCount(2, $attractions[0]);
        self::assertCount(1, $attractions[1]);
    }

    /**
     * @expectedException Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Second level cache does not support scalar results.
     */
    public function testScalarResultException() : void
    {
        $result = [];
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addScalarResult('id', 'u', Type::getType('integer'));

        $this->queryCache->put($key, $rsm, $result);
    }

    /**
     * @expectedException Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Second level cache does not support multiple root entities.
     */
    public function testSupportMultipleRootEntitiesException() : void
    {
        $result = [];
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addEntityResult(City::class, 'e1');
        $rsm->addEntityResult(State::class, 'e2');

        $this->queryCache->put($key, $rsm, $result);
    }

    /**
     * @expectedException Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Entity "Doctrine\Tests\Models\Generic\BooleanModel" not configured as part of the second-level cache.
     */
    public function testNotCacheableEntityException() : void
    {
        $result = [];
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(BooleanModel::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $entity  = new BooleanModel();
            $boolean = ($i % 2 === 0);

            $entity->id           = $i;
            $entity->booleanField = $boolean;
            $result[]             = $entity;

            $this->em->getUnitOfWork()->registerManaged($entity, ['id' => $i], ['booleanField' => $boolean]);
        }

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
    }
}

class CacheFactoryDefaultQueryCacheTest extends Cache\DefaultCacheFactory
{
    private $queryCache;
    private $region;

    public function __construct(DefaultQueryCache $queryCache, CacheRegionMock $region)
    {
        $this->queryCache = $queryCache;
        $this->region     = $region;
    }

    public function buildQueryCache(EntityManagerInterface $em, $regionName = null)
    {
        return $this->queryCache;
    }

    public function getRegion(CacheMetadata $cache)
    {
        return $this->region;
    }

    public function getTimestampRegion()
    {
        return new TimestampRegionMock();
    }
}
