<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\EntityCacheEntry;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\Exception\CacheException;
use Doctrine\ORM\Cache\QueryCacheEntry;
use Doctrine\ORM\Cache\QueryCacheKey;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\DbalExtensions\QueryLog;
use Doctrine\Tests\Models\Cache\Attraction;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\Cache\TravelerProfileInfo;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;

use function count;

#[Group('DDC-2183')]
class SecondLevelCacheQueryCacheTest extends SecondLevelCacheFunctionalTestCase
{
    public function testBasicQueryCache(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1 = $this->_em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[1]->getId(), $result1[1]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());
        self::assertEquals($this->countries[1]->getName(), $result1[1]->getName());

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        $this->assertQueryCount(1);
        self::assertCount(2, $result2);

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        self::assertInstanceOf(Country::class, $result2[0]);
        self::assertInstanceOf(Country::class, $result2[1]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[1]->getId(), $result2[1]->getId());

        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
        self::assertEquals($result1[1]->getName(), $result2[1]->getName());

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));
    }

    public function testQueryCacheModeGet(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->evictRegions();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->getQueryLog()->reset()->enable();
        $dql      = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $queryGet = $this->_em->createQuery($dql)
            ->setCacheMode(Cache::MODE_GET)
            ->setCacheable(true);

        // MODE_GET should never add items to the cache.
        self::assertCount(2, $queryGet->getResult());
        $this->assertQueryCount(1);

        self::assertCount(2, $queryGet->getResult());
        $this->assertQueryCount(2);

        $result = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result);
        $this->assertQueryCount(3);

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        // MODE_GET should read items if exists.
        self::assertCount(2, $queryGet->getResult());
        $this->assertQueryCount(3);
    }

    public function testQueryCacheModePut(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->evictRegions();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->getQueryLog()->reset()->enable();
        $dql    = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertCount(2, $result);
        $this->assertQueryCount(1);

        $queryPut = $this->_em->createQuery($dql)
            ->setCacheMode(Cache::MODE_PUT)
            ->setCacheable(true);

        // MODE_PUT should never read itens from cache.
        self::assertCount(2, $queryPut->getResult());
        $this->assertQueryCount(2);
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertCount(2, $queryPut->getResult());
        $this->assertQueryCount(3);
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));
    }

    public function testQueryCacheModeRefresh(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->evictRegions();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $region = $this->cache->getEntityCacheRegion(Country::class);
        $this->getQueryLog()->reset()->enable();
        $dql    = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertCount(2, $result);
        $this->assertQueryCount(1);

        $countryId1   = $this->countries[0]->getId();
        $countryId2   = $this->countries[1]->getId();
        $countryName1 = $this->countries[0]->getName();
        $countryName2 = $this->countries[1]->getName();

        $key1   = new EntityCacheKey(Country::class, ['id' => $countryId1]);
        $key2   = new EntityCacheKey(Country::class, ['id' => $countryId2]);
        $entry1 = new EntityCacheEntry(Country::class, ['id' => $countryId1, 'name' => 'outdated']);
        $entry2 = new EntityCacheEntry(Country::class, ['id' => $countryId2, 'name' => 'outdated']);

        $region->put($key1, $entry1);
        $region->put($key2, $entry2);
        $this->_em->clear();

        $queryRefresh = $this->_em->createQuery($dql)
            ->setCacheMode(Cache::MODE_REFRESH)
            ->setCacheable(true);

        // MODE_REFRESH should never read itens from cache.
        $result1 = $queryRefresh->getResult();
        self::assertCount(2, $result1);
        self::assertEquals($countryName1, $result1[0]->getName());
        self::assertEquals($countryName2, $result1[1]->getName());
        $this->assertQueryCount(2);

        $this->_em->clear();

        $result2 = $queryRefresh->getResult();
        self::assertCount(2, $result2);
        self::assertEquals($countryName1, $result2[0]->getName());
        self::assertEquals($countryName2, $result2[1]->getName());
        $this->assertQueryCount(3);
    }

    public function testBasicQueryCachePutEntityCache(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->evictRegions();
        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1 = $this->_em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[1]->getId(), $result1[1]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());
        self::assertEquals($this->countries[1]->getName(), $result1[1]->getName());

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertEquals(3, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionPutCount($this->getEntityRegion(Country::class)));

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        $this->assertQueryCount(1);
        self::assertCount(2, $result2);

        self::assertEquals(3, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        self::assertInstanceOf(Country::class, $result2[0]);
        self::assertInstanceOf(Country::class, $result2[1]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[1]->getId(), $result2[1]->getId());

        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
        self::assertEquals($result1[1]->getName(), $result2[1]->getName());

        self::assertEquals(3, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));
    }

    #[Group('5854')]
    public function testMultipleNestedDQLAliases(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->loadFixturesAttractions();

        $queryRegionName      = $this->getDefaultQueryRegionName();
        $cityRegionName       = $this->getEntityRegion(City::class);
        $stateRegionName      = $this->getEntityRegion(State::class);
        $attractionRegionName = $this->getEntityRegion(Attraction::class);

        $this->secondLevelCacheLogger->clearStats();
        $this->evictRegions();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT s, c, a FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c JOIN c.attractions a';
        $result1 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result1);
        $this->assertQueryCount(1);

        self::assertTrue($this->cache->containsEntity(State::class, $this->states[0]->getId()));
        self::assertTrue($this->cache->containsEntity(State::class, $this->states[1]->getId()));

        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[2]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[3]->getId()));

        self::assertTrue($this->cache->containsEntity(Attraction::class, $this->attractions[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Attraction::class, $this->attractions[1]->getId()));
        self::assertTrue($this->cache->containsEntity(Attraction::class, $this->attractions[2]->getId()));
        self::assertTrue($this->cache->containsEntity(Attraction::class, $this->attractions[3]->getId()));

        self::assertInstanceOf(State::class, $result1[0]);
        self::assertInstanceOf(State::class, $result1[1]);

        self::assertCount(2, $result1[0]->getCities());
        self::assertCount(2, $result1[1]->getCities());

        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(1));

        self::assertCount(2, $result1[0]->getCities()->get(0)->getAttractions());
        self::assertCount(2, $result1[0]->getCities()->get(1)->getAttractions());
        self::assertCount(2, $result1[1]->getCities()->get(0)->getAttractions());
        self::assertCount(1, $result1[1]->getCities()->get(1)->getAttractions());

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result2);
        $this->assertQueryCount(1);

        self::assertInstanceOf(State::class, $result2[0]);
        self::assertInstanceOf(State::class, $result2[1]);

        self::assertCount(2, $result2[0]->getCities());
        self::assertCount(2, $result2[1]->getCities());

        self::assertInstanceOf(City::class, $result2[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result2[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result2[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result2[1]->getCities()->get(1));

        self::assertCount(2, $result2[0]->getCities()->get(0)->getAttractions());
        self::assertCount(2, $result2[0]->getCities()->get(1)->getAttractions());
        self::assertCount(2, $result2[1]->getCities()->get(0)->getAttractions());
        self::assertCount(1, $result2[1]->getCities()->get(1)->getAttractions());
    }

    public function testBasicQueryParams(): void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->_em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->getQueryLog()->reset()->enable();
        $name    = $this->countries[0]->getName();
        $dql     = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c WHERE c.name = :name';
        $result1 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->setParameter('name', $name)
                ->getResult();

        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)->setCacheable(true)
                ->setParameter('name', $name)
                ->getResult();

        $this->assertQueryCount(1);
        self::assertCount(1, $result2);

        self::assertInstanceOf(Country::class, $result2[0]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
    }

    public function testLoadFromDatabaseWhenEntityMissing(): void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->_em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1 = $this->_em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[1]->getId(), $result1[1]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());
        self::assertEquals($this->countries[1]->getName(), $result1[1]->getName());

        self::assertEquals(3, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        $this->cache->evictEntity(Country::class, $result1[0]->getId());
        self::assertFalse($this->cache->containsEntity(Country::class, $result1[0]->getId()));

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        $this->assertQueryCount(2);
        self::assertCount(2, $result2);

        self::assertEquals(5, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        self::assertInstanceOf(Country::class, $result2[0]);
        self::assertInstanceOf(Country::class, $result2[1]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[1]->getId(), $result2[1]->getId());

        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
        self::assertEquals($result1[1]->getName(), $result2[1]->getName());

        $this->assertQueryCount(2);
    }

    public function testBasicQueryFetchJoinsOneToMany(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->evictRegions();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT s, c FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c';
        $result1 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        $this->assertQueryCount(1);
        self::assertInstanceOf(State::class, $result1[0]);
        self::assertInstanceOf(State::class, $result1[1]);
        self::assertCount(2, $result1[0]->getCities());
        self::assertCount(2, $result1[1]->getCities());

        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(1));

        self::assertNotNull($result1[0]->getCities()->get(0)->getId());
        self::assertNotNull($result1[0]->getCities()->get(1)->getId());
        self::assertNotNull($result1[1]->getCities()->get(0)->getId());
        self::assertNotNull($result1[1]->getCities()->get(1)->getId());

        self::assertNotNull($result1[0]->getCities()->get(0)->getName());
        self::assertNotNull($result1[0]->getCities()->get(1)->getName());
        self::assertNotNull($result1[1]->getCities()->get(0)->getName());
        self::assertNotNull($result1[1]->getCities()->get(1)->getName());

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertInstanceOf(State::class, $result2[0]);
        self::assertInstanceOf(State::class, $result2[1]);
        self::assertCount(2, $result2[0]->getCities());
        self::assertCount(2, $result2[1]->getCities());

        self::assertInstanceOf(City::class, $result2[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result2[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result2[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result2[1]->getCities()->get(1));

        self::assertNotNull($result2[0]->getCities()->get(0)->getId());
        self::assertNotNull($result2[0]->getCities()->get(1)->getId());
        self::assertNotNull($result2[1]->getCities()->get(0)->getId());
        self::assertNotNull($result2[1]->getCities()->get(1)->getId());

        self::assertNotNull($result2[0]->getCities()->get(0)->getName());
        self::assertNotNull($result2[0]->getCities()->get(1)->getName());
        self::assertNotNull($result2[1]->getCities()->get(0)->getName());
        self::assertNotNull($result2[1]->getCities()->get(1)->getName());

        $this->assertQueryCount(1);
    }

    public function testBasicQueryFetchJoinsManyToOne(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->_em->clear();

        $this->evictRegions();
        $this->secondLevelCacheLogger->clearStats();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c, s FROM Doctrine\Tests\Models\Cache\City c JOIN c.state s';
        $result1 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertCount(4, $result1);
        self::assertInstanceOf(City::class, $result1[0]);
        self::assertInstanceOf(City::class, $result1[1]);
        self::assertInstanceOf(State::class, $result1[0]->getState());
        self::assertInstanceOf(State::class, $result1[1]->getState());

        self::assertTrue($this->cache->containsEntity(City::class, $result1[0]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $result1[1]->getId()));
        self::assertTrue($this->cache->containsEntity(State::class, $result1[0]->getState()->getId()));
        self::assertTrue($this->cache->containsEntity(State::class, $result1[1]->getState()->getId()));

        self::assertEquals(7, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionPutCount($this->getEntityRegion(State::class)));
        self::assertEquals(4, $this->secondLevelCacheLogger->getRegionPutCount($this->getEntityRegion(City::class)));

        $this->assertQueryCount(1);

        $this->_em->clear();
        $this->secondLevelCacheLogger->clearStats();

        $result2 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertCount(4, $result1);
        self::assertInstanceOf(City::class, $result2[0]);
        self::assertInstanceOf(City::class, $result2[1]);
        self::assertInstanceOf(State::class, $result2[0]->getState());
        self::assertInstanceOf(State::class, $result2[1]->getState());

        self::assertNotNull($result2[0]->getId());
        self::assertNotNull($result2[0]->getId());
        self::assertNotNull($result2[1]->getState()->getId());
        self::assertNotNull($result2[1]->getState()->getId());

        self::assertNotNull($result2[0]->getName());
        self::assertNotNull($result2[0]->getName());
        self::assertNotNull($result2[1]->getState()->getName());
        self::assertNotNull($result2[1]->getState()->getName());

        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
        self::assertEquals($result1[1]->getName(), $result2[1]->getName());
        self::assertEquals($result1[0]->getState()->getName(), $result2[0]->getState()->getName());
        self::assertEquals($result1[1]->getState()->getName(), $result2[1]->getState()->getName());

        $this->assertQueryCount(1);
    }

    public function testReloadQueryIfToOneIsNotFound(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->_em->clear();

        $this->evictRegions();
        $this->secondLevelCacheLogger->clearStats();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c, s FROM Doctrine\Tests\Models\Cache\City c JOIN c.state s';
        $result1 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertCount(4, $result1);
        self::assertInstanceOf(City::class, $result1[0]);
        self::assertInstanceOf(City::class, $result1[1]);
        self::assertInstanceOf(State::class, $result1[0]->getState());
        self::assertInstanceOf(State::class, $result1[1]->getState());

        self::assertTrue($this->cache->containsEntity(City::class, $result1[0]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $result1[1]->getId()));
        self::assertTrue($this->cache->containsEntity(State::class, $result1[0]->getState()->getId()));
        self::assertTrue($this->cache->containsEntity(State::class, $result1[1]->getState()->getId()));
        $this->assertQueryCount(1);

        $this->_em->clear();

        $this->cache->evictEntityRegion(State::class);

        $result2 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertCount(4, $result1);
        self::assertInstanceOf(City::class, $result2[0]);
        self::assertInstanceOf(City::class, $result2[1]);
        self::assertInstanceOf(State::class, $result2[0]->getState());
        self::assertInstanceOf(State::class, $result2[1]->getState());

        $this->assertQueryCount(2);
    }

    public function testReloadQueryIfToManyAssociationItemIsNotFound(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->evictRegions();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT s, c FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c';
        $result1 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        $this->assertQueryCount(1);
        self::assertInstanceOf(State::class, $result1[0]);
        self::assertInstanceOf(State::class, $result1[1]);
        self::assertCount(2, $result1[0]->getCities());
        self::assertCount(2, $result1[1]->getCities());

        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(1));

        $this->_em->clear();

        $this->cache->evictEntityRegion(City::class);

        $result2 = $this->_em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertInstanceOf(State::class, $result2[0]);
        self::assertInstanceOf(State::class, $result2[1]);
        self::assertCount(2, $result2[0]->getCities());
        self::assertCount(2, $result2[1]->getCities());

        self::assertInstanceOf(City::class, $result2[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result2[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result2[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result2[1]->getCities()->get(1));

        $this->assertQueryCount(2);
    }

    public function testPostLoadFiresAfterAllEntitiesOfCachedResultAreHydrated(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->loadFixturesTravelersWithProfile();
        $this->loadFixturesTravelersProfileInfo();

        $dql = 'SELECT p FROM Doctrine\Tests\Models\Cache\TravelerProfile p ORDER BY p.id';

        $this->_em->clear();
        $this->_em->createQuery($dql)->setCacheable(true)->getResult();
        $this->_em->clear();
        $this->cache->evictEntityRegion(TravelerProfileInfo::class);

        $queriesAtPostLoad = [];
        $listener          = new class ($this->getQueryLog(), $queriesAtPostLoad) {
            /** @param list<int> $queriesAtPostLoad */
            public function __construct(
                private readonly QueryLog $queryLog,
                private array &$queriesAtPostLoad,
            ) {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($args->getObject() instanceof TravelerProfileInfo) {
                    $this->queriesAtPostLoad[] = count($this->queryLog->queries);
                }
            }
        };

        $this->_em->getEventManager()->addEventListener([Events::postLoad], $listener);
        $this->getQueryLog()->reset()->enable();

        $result = $this->_em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result);
        self::assertSame([2, 2], $queriesAtPostLoad, 'Both nested loads should be finished before the first postLoad');
    }

    public function testBasicNativeQueryCache(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $rsm = new ResultSetMapping();
        $rsm->addEntityResult(Country::class, 'c');
        $rsm->addFieldResult('c', 'name', 'name');
        $rsm->addFieldResult('c', 'id', 'id');

        $this->getQueryLog()->reset()->enable();
        $sql     = 'SELECT id, name FROM cache_country';
        $result1 = $this->_em->createNativeQuery($sql, $rsm)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[1]->getId(), $result1[1]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());
        self::assertEquals($this->countries[1]->getName(), $result1[1]->getName());

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        $this->_em->clear();

        $result2 = $this->_em->createNativeQuery($sql, $rsm)
            ->setCacheable(true)
            ->getResult();

        $this->assertQueryCount(1);
        self::assertCount(2, $result2);

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        self::assertInstanceOf(Country::class, $result2[0]);
        self::assertInstanceOf(Country::class, $result2[1]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[1]->getId(), $result2[1]->getId());

        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
        self::assertEquals($result1[1]->getName(), $result2[1]->getName());

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));
    }

    public function testQueryDependsOnFirstAndMaxResultResult(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->setFirstResult(1)
            ->setMaxResults(1)
            ->getResult();

        $this->assertQueryCount(1);
        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        $this->_em->clear();

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->setFirstResult(2)
            ->setMaxResults(1)
            ->getResult();

        $this->assertQueryCount(2);
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());

        $this->_em->clear();

        $result3 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        $this->assertQueryCount(3);
        self::assertEquals(3, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getMissCount());
    }

    public function testQueryCacheLifetime(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        $getHash = static function (AbstractQuery $query) {
            $method = new ReflectionMethod($query, 'getHash');

            return $method->invoke($query);
        };

        $this->getQueryLog()->reset()->enable();
        $dql     = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $query   = $this->_em->createQuery($dql);
        $result1 = $query->setCacheable(true)
            ->setLifetime(3600)
            ->getResult();

        self::assertNotEmpty($result1);
        $this->assertQueryCount(1);
        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        $this->_em->clear();

        $key   = new QueryCacheKey($getHash($query), 3600);
        $entry = $this->cache->getQueryCache()
            ->getRegion()
            ->get($key);

        self::assertInstanceOf(QueryCacheEntry::class, $entry);
        $entry = new QueryCacheEntry($entry->result, $entry->time / 2);

        $this->cache->getQueryCache()
            ->getRegion()
            ->put($key, $entry);

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->setLifetime(3600)
            ->getResult();

        self::assertNotEmpty($result2);
        $this->assertQueryCount(2);
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
    }

    public function testQueryCacheRegion(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql   = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $query = $this->_em->createQuery($dql);

        $query1  = clone $query;
        $result1 = $query1->setCacheable(true)
            ->setCacheRegion('foo_region')
            ->getResult();

        self::assertNotEmpty($result1);
        $this->assertQueryCount(1);
        self::assertEquals(0, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('foo_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('foo_region'));

        $query2  = clone $query;
        $result2 = $query2->setCacheable(true)
            ->setCacheRegion('bar_region')
            ->getResult();

        self::assertNotEmpty($result2);
        $this->assertQueryCount(2);
        self::assertEquals(0, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('bar_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('bar_region'));

        $query3  = clone $query;
        $result3 = $query3->setCacheable(true)
            ->setCacheRegion('foo_region')
            ->getResult();

        self::assertNotEmpty($result3);
        $this->assertQueryCount(2);
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount('foo_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('foo_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('foo_region'));

        $query4  = clone $query;
        $result4 = $query4->setCacheable(true)
            ->setCacheRegion('bar_region')
            ->getResult();

        self::assertNotEmpty($result3);
        $this->assertQueryCount(2);
        self::assertEquals(6, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount('bar_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('bar_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('bar_region'));
    }

    public function testResolveAssociationCacheEntry(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->loadFixturesStates();

        $this->_em->clear();

        $stateId     = $this->states[0]->getId();
        $countryName = $this->states[0]->getCountry()->getName();
        $dql         = 'SELECT s FROM Doctrine\Tests\Models\Cache\State s WHERE s.id = :id';
        $query       = $this->_em->createQuery($dql);
        $this->getQueryLog()->reset()->enable();

        $query1 = clone $query;
        $state1 = $query1
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertNotNull($state1);
        self::assertNotNull($state1->getCountry());
        $this->assertQueryCount(1);
        self::assertInstanceOf(State::class, $state1);
        self::assertEquals($countryName, $state1->getCountry()->getName());
        self::assertEquals($stateId, $state1->getId());

        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $query2 = clone $query;
        $state2 = $query2
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertNotNull($state2);
        self::assertNotNull($state2->getCountry());
        $this->assertQueryCount(0);
        self::assertInstanceOf(State::class, $state2);
        self::assertEquals($countryName, $state2->getCountry()->getName());
        self::assertEquals($stateId, $state2->getId());
    }

    public function testResolveToOneAssociationCacheEntry(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->evictRegions();

        $this->_em->clear();

        $cityId = $this->cities[0]->getId();
        $dql    = 'SELECT c, s FROM Doctrine\Tests\Models\Cache\City c JOIN c.state s WHERE c.id = :id';
        $query  = $this->_em->createQuery($dql);
        $this->getQueryLog()->reset()->enable();

        $query1 = clone $query;
        $city1  = $query1
            ->setParameter('id', $cityId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        $this->assertQueryCount(1);
        self::assertInstanceOf(City::class, $city1);
        self::assertInstanceOf(State::class, $city1->getState());
        self::assertInstanceOf(City::class, $city1->getState()->getCities()->get(0));
        self::assertInstanceOf(State::class, $city1->getState()->getCities()->get(0)->getState());

        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $query2 = clone $query;
        $city2  = $query2
            ->setParameter('id', $cityId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        $this->assertQueryCount(0);
        self::assertInstanceOf(City::class, $city2);
        self::assertInstanceOf(State::class, $city2->getState());
        self::assertInstanceOf(City::class, $city2->getState()->getCities()->get(0));
        self::assertInstanceOf(State::class, $city2->getState()->getCities()->get(0)->getState());
    }

    public function testResolveToManyAssociationCacheEntry(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->evictRegions();

        $this->_em->clear();

        $stateId = $this->states[0]->getId();
        $dql     = 'SELECT s, c FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c WHERE s.id = :id';
        $query   = $this->_em->createQuery($dql);
        $this->getQueryLog()->reset()->enable();

        $query1 = clone $query;
        $state1 = $query1
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        $this->assertQueryCount(1);
        self::assertInstanceOf(State::class, $state1);
        self::assertInstanceOf(City::class, $state1->getCities()->get(0));
        self::assertInstanceOf(State::class, $state1->getCities()->get(0)->getState());
        self::assertSame($state1, $state1->getCities()->get(0)->getState());

        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $query2 = clone $query;
        $state2 = $query2
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        $this->assertQueryCount(0);
        self::assertInstanceOf(State::class, $state2);
        self::assertInstanceOf(City::class, $state2->getCities()->get(0));
        self::assertInstanceOf(State::class, $state2->getCities()->get(0)->getState());
        self::assertSame($state2, $state2->getCities()->get(0)->getState());
    }

    public function testHintClearEntityRegionUpdateStatement(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->_em->createQuery('DELETE Doctrine\Tests\Models\Cache\Country u WHERE u.id = 4')
            ->setHint(Query::HINT_CACHE_EVICT, true)
            ->execute();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));
    }

    public function testHintClearEntityRegionDeleteStatement(): void
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->_em->createQuery("UPDATE Doctrine\Tests\Models\Cache\Country u SET u.name = 'foo' WHERE u.id = 1")
            ->setHint(Query::HINT_CACHE_EVICT, true)
            ->execute();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));
    }

    public function testCacheablePartialQueryException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second level cache does not support partial entities.');
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->_em->createQuery('SELECT PARTIAL c.{id} FROM Doctrine\Tests\Models\Cache\Country c')
            ->setCacheable(true)
            ->getResult();
    }

    public function testCacheableForcePartialLoadHintQueryException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second level cache does not support partial entities.');
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->_em->createQuery('SELECT c FROM Doctrine\Tests\Models\Cache\Country c')
            ->setCacheable(true)
            ->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true)
            ->getResult();
    }

    public function testNonCacheableQueryDeleteStatementException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second-level cache query supports only select statements.');
        $this->_em->createQuery('DELETE Doctrine\Tests\Models\Cache\Country u WHERE u.id = 4')
            ->setCacheable(true)
            ->getResult();
    }

    public function testNonCacheableQueryUpdateStatementException(): void
    {
        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Second-level cache query supports only select statements.');
        $this->_em->createQuery("UPDATE Doctrine\Tests\Models\Cache\Country u SET u.name = 'foo' WHERE u.id = 4")
            ->setCacheable(true)
            ->getResult();
    }

    public function testQueryCacheShouldBeEvictedOnTimestampUpdate(): void
    {
        $this->loadFixturesCountries();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();
        $dql = 'SELECT country FROM Doctrine\Tests\Models\Cache\Country country';

        $result1 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result1);
        $this->assertQueryCount(1);

        $this->_em->persist(new Country('France'));
        $this->_em->flush();
        $this->_em->clear();

        $this->getQueryLog()->reset()->enable();

        $result2 = $this->_em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(3, $result2);
        $this->assertQueryCount(1);

        foreach ($result2 as $entity) {
            self::assertInstanceOf(Country::class, $entity);
        }
    }
}
