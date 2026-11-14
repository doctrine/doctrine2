<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\DefaultQueryCache;
use Doctrine\ORM\Cache\EntityCacheEntry;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\Exception\CacheException;
use Doctrine\ORM\Cache\QueryCache;
use Doctrine\ORM\Cache\QueryCacheEntry;
use Doctrine\ORM\Cache\QueryCacheKey;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\Mocks\CacheEntryMock;
use Doctrine\Tests\Mocks\CacheRegionMock;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Mocks\TimestampRegionMock;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\Restaurant;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\Generic\BooleanModel;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;

use function microtime;
use function sprintf;

#[Group('DDC-2183')]
class DefaultQueryCacheTest extends OrmTestCase
{
    private DefaultQueryCache $queryCache;
    private EntityManagerMock $em;
    private CacheRegionMock $region;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableSecondLevelCache();

        $this->em         = $this->getTestEntityManager();
        $this->region     = new CacheRegionMock();
        $this->queryCache = new DefaultQueryCache($this->em, $this->region);
        $cacheFactory     = new CacheFactoryDefaultQueryCacheTest($this->queryCache, $this->region);

        $this->em->getConfiguration()
            ->getSecondLevelCacheConfiguration()
            ->setCacheFactory($cacheFactory);
    }

    public function testImplementQueryCache(): void
    {
        self::assertInstanceOf(QueryCache::class, $this->queryCache);
    }

    public function testGetRegion(): void
    {
        self::assertSame($this->region, $this->queryCache->getRegion());
    }

    public function testClearShouldEvictRegion(): void
    {
        $this->queryCache->clear();

        self::assertArrayHasKey('evictAll', $this->region->calls);
        self::assertCount(1, $this->region->calls['evictAll']);
    }

    public function testPutBasicQueryResult(): void
    {
        $result   = [];
        $key      = new QueryCacheKey('query.key1', 0);
        $rsm      = new ResultSetMappingBuilder($this->em);
        $metadata = $this->em->getClassMetadata(Country::class);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $name     = 'Country ' . $i;
            $entity   = new Country($name);
            $result[] = $entity;

            $metadata->setFieldValue($entity, 'id', $i);
            $this->em->getUnitOfWork()->registerManaged($entity, ['id' => $i], ['name' => $name]);
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

    public function testPutToOneAssociationQueryResult(): void
    {
        $result     = [];
        $uow        = $this->em->getUnitOfWork();
        $key        = new QueryCacheKey('query.key1', 0);
        $rsm        = new ResultSetMappingBuilder($this->em);
        $cityClass  = $this->em->getClassMetadata(City::class);
        $stateClass = $this->em->getClassMetadata(State::class);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);

        for ($i = 0; $i < 4; $i++) {
            $state    = new State(sprintf('State %d', $i));
            $city     = new City(sprintf('City %d', $i), $state);
            $result[] = $city;

            $cityClass->setFieldValue($city, 'id', $i);
            $stateClass->setFieldValue($state, 'id', $i * 2);

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

    public function testPutToOneAssociation2LevelsQueryResult(): void
    {
        $result       = [];
        $uow          = $this->em->getUnitOfWork();
        $key          = new QueryCacheKey('query.key1', 0);
        $rsm          = new ResultSetMappingBuilder($this->em);
        $cityClass    = $this->em->getClassMetadata(City::class);
        $stateClass   = $this->em->getClassMetadata(State::class);
        $countryClass = $this->em->getClassMetadata(Country::class);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);
        $rsm->addJoinedEntityFromClassMetadata(Country::class, 'co', 's', 'country', ['id' => 'country_id', 'name' => 'country_name']);

        for ($i = 0; $i < 4; $i++) {
            $country = new Country('Country ' . $i);
            $state   = new State('State ' . $i, $country);
            $city    = new City('City ' . $i, $state);

            $result[] = $city;

            $cityClass->setFieldValue($city, 'id', $i);
            $stateClass->setFieldValue($state, 'id', $i * 2);
            $countryClass->setFieldValue($country, 'id', $i * 3);

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

    public function testPutToOneAssociationNullQueryResult(): void
    {
        $result    = [];
        $uow       = $this->em->getUnitOfWork();
        $key       = new QueryCacheKey('query.key1', 0);
        $rsm       = new ResultSetMappingBuilder($this->em);
        $cityClass = $this->em->getClassMetadata(City::class);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);

        for ($i = 0; $i < 4; $i++) {
            $city     = new City(sprintf('City %d', $i), null);
            $result[] = $city;

            $cityClass->setFieldValue($city, 'id', $i);

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

    public function testPutToManyAssociationQueryResult(): void
    {
        $result     = [];
        $uow        = $this->em->getUnitOfWork();
        $key        = new QueryCacheKey('query.key1', 0);
        $rsm        = new ResultSetMappingBuilder($this->em);
        $cityClass  = $this->em->getClassMetadata(City::class);
        $stateClass = $this->em->getClassMetadata(State::class);

        $rsm->addRootEntityFromClassMetadata(State::class, 's');
        $rsm->addJoinedEntityFromClassMetadata(City::class, 'c', 's', 'cities', ['id' => 'c_id', 'name' => 'c_name']);

        for ($i = 0; $i < 4; $i++) {
            $state    = new State(sprintf('State %d', $i));
            $city1    = new City('City 1', $state);
            $city2    = new City('City 2', $state);
            $result[] = $state;

            $cityClass->setFieldValue($city1, 'id', $i + 11);
            $cityClass->setFieldValue($city2, 'id', $i + 22);
            $stateClass->setFieldValue($state, 'id', $i);

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

    public function testGetBasicQueryResult(): void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ],
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
            ],
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

    public function testGetWithAssociation(): void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ],
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
            ],
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

    public function testGetWithAssociationCacheMiss(): void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ],
        );

        $this->region->addReturn('get', $entry);

        $this->region->addReturn(
            'getMultiple',
            [
                new EntityCacheEntry(Country::class, ['id' => 1, 'name' => 'Foo']),
                false,
            ],
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        $result = $this->queryCache->get($key, $rsm);

        self::assertNull($result);
    }

    public function testCancelPutResultIfEntityPutFails(): void
    {
        $result   = [];
        $key      = new QueryCacheKey('query.key1', 0);
        $rsm      = new ResultSetMappingBuilder($this->em);
        $metadata = $this->em->getClassMetadata(Country::class);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $name     = 'Country ' . $i;
            $entity   = new Country($name);
            $result[] = $entity;

            $metadata->setFieldValue($entity, 'id', $i);
            $this->em->getUnitOfWork()->registerManaged($entity, ['id' => $i], ['name' => $name]);
        }

        $this->region->addReturn('put', false);

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
        self::assertCount(1, $this->region->calls['put']);
    }

    public function testCancelPutResultIfAssociationEntityPutFails(): void
    {
        $result     = [];
        $uow        = $this->em->getUnitOfWork();
        $key        = new QueryCacheKey('query.key1', 0);
        $rsm        = new ResultSetMappingBuilder($this->em);
        $cityClass  = $this->em->getClassMetadata(City::class);
        $stateClass = $this->em->getClassMetadata(State::class);

        $rsm->addRootEntityFromClassMetadata(City::class, 'c');
        $rsm->addJoinedEntityFromClassMetadata(State::class, 's', 'c', 'state', ['id' => 'state_id', 'name' => 'state_name']);

        $state    = new State('State 1');
        $city     = new City('City 2', $state);
        $result[] = $city;

        $cityClass->setFieldValue($city, 'id', 1);
        $stateClass->setFieldValue($state, 'id', 11);

        $uow->registerManaged($state, ['id' => $state->getId()], ['name' => $city->getName()]);
        $uow->registerManaged($city, ['id' => $city->getId()], ['name' => $city->getName(), 'state' => $state]);

        $this->region->addReturn('put', true);  // put root entity
        $this->region->addReturn('put', false); // association fails

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
    }

    public function testCancelPutToManyAssociationQueryResult(): void
    {
        $result     = [];
        $uow        = $this->em->getUnitOfWork();
        $key        = new QueryCacheKey('query.key1', 0);
        $rsm        = new ResultSetMappingBuilder($this->em);
        $cityClass  = $this->em->getClassMetadata(City::class);
        $stateClass = $this->em->getClassMetadata(State::class);

        $rsm->addRootEntityFromClassMetadata(State::class, 's');
        $rsm->addJoinedEntityFromClassMetadata(City::class, 'c', 's', 'cities', ['id' => 'c_id', 'name' => 'c_name']);

        $state    = new State('State');
        $city1    = new City('City 1', $state);
        $city2    = new City('City 2', $state);
        $result[] = $state;

        $stateClass->setFieldValue($state, 'id', 1);
        $cityClass->setFieldValue($city1, 'id', 11);
        $cityClass->setFieldValue($city2, 'id', 22);

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

    public function testIgnoreCacheNonGetMode(): void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0, Cache::MODE_PUT);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ],
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        $this->region->addReturn('get', $entry);

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testIgnoreCacheNonPutMode(): void
    {
        $result   = [];
        $rsm      = new ResultSetMappingBuilder($this->em);
        $metadata = $this->em->getClassMetadata(Country::class);
        $key      = new QueryCacheKey('query.key1', 0, Cache::MODE_GET);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 4; $i++) {
            $name     = 'Country ' . $i;
            $entity   = new Country($name);
            $result[] = $entity;

            $metadata->setFieldValue($entity, 'id', $i);
            $this->em->getUnitOfWork()->registerManaged($entity, ['id' => $i], ['name' => $name]);
        }

        self::assertFalse($this->queryCache->put($key, $rsm, $result));
    }

    public function testRefreshModeWritesToCache(): void
    {
        $result   = [];
        $rsm      = new ResultSetMappingBuilder($this->em);
        $metadata = $this->em->getClassMetadata(Country::class);
        $key      = new QueryCacheKey('query.key1', 0, Cache::MODE_REFRESH);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        for ($i = 0; $i < 2; $i++) {
            $name     = 'Country ' . $i;
            $entity   = new Country($name);
            $result[] = $entity;

            $metadata->setFieldValue($entity, 'id', $i);
            $this->em->getUnitOfWork()->registerManaged($entity, ['id' => $i], ['name' => $name]);
        }

        self::assertTrue($this->queryCache->put($key, $rsm, $result));
        self::assertArrayHasKey('put', $this->region->calls);
    }

    public function testGetShouldIgnoreOldQueryCacheEntryResult(): void
    {
        $rsm      = new ResultSetMappingBuilder($this->em);
        $key      = new QueryCacheKey('query.key1', 50);
        $entry    = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ],
            microtime(true) - 100,
        );
        $entities = [
            ['id' => 1, 'name' => 'Foo'],
            ['id' => 2, 'name' => 'Bar'],
        ];

        $this->region->addReturn('get', $entry);

        $this->region->addReturn(
            'getMultiple',
            [
                new EntityCacheEntry(Country::class, $entities[0]),
                new EntityCacheEntry(Country::class, $entities[1]),
            ],
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testGetShouldIgnoreNonQueryCacheEntryResult(): void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new CacheEntryMock([
            ['identifier' => ['id' => 1]],
            ['identifier' => ['id' => 2]],
        ]);

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
            ],
        );

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testGetShouldIgnoreMissingEntityQueryCacheEntry(): void
    {
        $rsm   = new ResultSetMappingBuilder($this->em);
        $key   = new QueryCacheKey('query.key1', 0);
        $entry = new QueryCacheEntry(
            [
                ['identifier' => ['id' => 1]],
                ['identifier' => ['id' => 2]],
            ],
        );

        $this->region->addReturn('get', $entry);
        $this->region->addReturn('getMultiple', [null]);

        $rsm->addRootEntityFromClassMetadata(Country::class, 'c');

        self::assertNull($this->queryCache->get($key, $rsm));
    }

    public function testGetAssociationValue(): void
    {
        $reflection = new ReflectionMethod($this->queryCache, 'getAssociationValue');
        $rsm        = new ResultSetMappingBuilder($this->em);
        $key        = new QueryCacheKey('query.key1', 0);

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

    public function testScalarResultException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second level cache does not support scalar results.');
        $result = [];
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addScalarResult('id', 'u', 'integer');

        $this->queryCache->put($key, $rsm, $result);
    }

    public function testSupportMultipleRootEntitiesException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second level cache does not support multiple root entities.');
        $result = [];
        $key    = new QueryCacheKey('query.key1', 0);
        $rsm    = new ResultSetMappingBuilder($this->em);

        $rsm->addEntityResult(City::class, 'e1');
        $rsm->addEntityResult(State::class, 'e2');

        $this->queryCache->put($key, $rsm, $result);
    }

    public function testNotCacheableEntityException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Entity "Doctrine\Tests\Models\Generic\BooleanModel" not configured as part of the second-level cache.');
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

        $this->queryCache->put($key, $rsm, $result);
    }
}

class CacheFactoryDefaultQueryCacheTest extends DefaultCacheFactory
{
    public function __construct(
        private DefaultQueryCache $queryCache,
        private CacheRegionMock $region,
    ) {
    }

    public function buildQueryCache(EntityManagerInterface $em, string|null $regionName = null): DefaultQueryCache
    {
        return $this->queryCache;
    }

    public function getRegion(array $cache): CacheRegionMock
    {
        return $this->region;
    }

    public function getTimestampRegion(): TimestampRegionMock
    {
        return new TimestampRegionMock();
    }
}
