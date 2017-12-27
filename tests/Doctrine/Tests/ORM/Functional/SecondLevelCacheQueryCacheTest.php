<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\EntityCacheEntry;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\QueryCacheKey;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\Models\Cache\Attraction;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\State;
use ProxyManager\Proxy\GhostObjectInterface;

/**
 * @group DDC-2183
 */
class SecondLevelCacheQueryCacheTest extends SecondLevelCacheAbstractTest
{
    public function testBasicQueryCache()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1    = $this->em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[1]->getId(), $result1[1]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());
        self::assertEquals($this->countries[1]->getName(), $result1[1]->getName());

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
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

    public function testQueryCacheModeGet()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->evictRegions();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $queryGet   = $this->em->createQuery($dql)
            ->setCacheMode(Cache::MODE_GET)
            ->setCacheable(true);

        // MODE_GET should never add items to the cache.
        self::assertCount(2, $queryGet->getResult());
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        self::assertCount(2, $queryGet->getResult());
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());

        $result = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result);
        self::assertEquals($queryCount + 3, $this->getCurrentQueryCount());

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        // MODE_GET should read items if exists.
        self::assertCount(2, $queryGet->getResult());
        self::assertEquals($queryCount + 3, $this->getCurrentQueryCount());
    }

    public function testQueryCacheModePut()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->evictRegions();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result     = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertCount(2, $result);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $queryPut = $this->em->createQuery($dql)
            ->setCacheMode(Cache::MODE_PUT)
            ->setCacheable(true);

        // MODE_PUT should never read itens from cache.
        self::assertCount(2, $queryPut->getResult());
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertCount(2, $queryPut->getResult());
        self::assertEquals($queryCount + 3, $this->getCurrentQueryCount());
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));
    }

    public function testQueryCacheModeRefresh()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->evictRegions();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $region     = $this->cache->getEntityCacheRegion(Country::class);
        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result     = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        self::assertCount(2, $result);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $countryId1     = $this->countries[0]->getId();
        $countryId2     = $this->countries[1]->getId();
        $countryName1   = $this->countries[0]->getName();
        $countryName2   = $this->countries[1]->getName();

        $key1           = new EntityCacheKey(Country::class, ['id'=>$countryId1]);
        $key2           = new EntityCacheKey(Country::class, ['id'=>$countryId2]);
        $entry1         = new EntityCacheEntry(Country::class, ['id'=>$countryId1, 'name'=>'outdated']);
        $entry2         = new EntityCacheEntry(Country::class, ['id'=>$countryId2, 'name'=>'outdated']);

        $region->put($key1, $entry1);
        $region->put($key2, $entry2);
        $this->em->clear();

        $queryRefresh = $this->em->createQuery($dql)
            ->setCacheMode(Cache::MODE_REFRESH)
            ->setCacheable(true);

        // MODE_REFRESH should never read itens from cache.
        $result1 = $queryRefresh->getResult();
        self::assertCount(2, $result1);
        self::assertEquals($countryName1, $result1[0]->getName());
        self::assertEquals($countryName2, $result1[1]->getName());
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());

        $this->em->clear();

        $result2 = $queryRefresh->getResult();
        self::assertCount(2, $result2);
        self::assertEquals($countryName1, $result2[0]->getName());
        self::assertEquals($countryName2, $result2[1]->getName());
        self::assertEquals($queryCount + 3, $this->getCurrentQueryCount());
    }

    public function testBasicQueryCachePutEntityCache()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->evictRegions();
        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1    = $this->em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
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

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
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

    /**
     * @group 5854
     */
    public function testMultipleNestedDQLAliases()
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
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT s, c, a FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c JOIN c.attractions a';
        $result1    = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

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

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result2);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

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

    public function testBasicQueryParams()
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $queryCount = $this->getCurrentQueryCount();
        $name       = $this->countries[0]->getName();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c WHERE c.name = :name';
        $result1    = $this->em->createQuery($dql)
                ->setCacheable(true)
                ->setParameter('name', $name)
                ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)->setCacheable(true)
                ->setParameter('name', $name)
                ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertCount(1, $result2);

        self::assertInstanceOf(Country::class, $result2[0]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
    }

    public function testLoadFromDatabaseWhenEntityMissing()
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1    = $this->em->createQuery($dql)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        self::assertEquals($queryCount + 1 , $this->getCurrentQueryCount());
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

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertEquals($queryCount + 2 , $this->getCurrentQueryCount());
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

        self::assertEquals($queryCount + 2 , $this->getCurrentQueryCount());
    }

    public function testBasicQueryFetchJoinsOneToMany()
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->evictRegions();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT s, c FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c';
        $result1    = $this->em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
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

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)
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

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount(), 'No other queries performed');
    }

    public function testBasicQueryFetchJoinsManyToOne()
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->em->clear();

        $this->evictRegions();
        $this->secondLevelCacheLogger->clearStats();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c, s FROM Doctrine\Tests\Models\Cache\City c JOIN c.state s';
        $result1    = $this->em->createQuery($dql)
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

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $this->em->clear();
        $this->secondLevelCacheLogger->clearStats();

        $result2  = $this->em->createQuery($dql)
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

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
    }

    public function testReloadQueryIfToOneIsNotFound()
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->em->clear();

        $this->evictRegions();
        $this->secondLevelCacheLogger->clearStats();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c, s FROM Doctrine\Tests\Models\Cache\City c JOIN c.state s';
        $result1    = $this->em->createQuery($dql)
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
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $this->em->clear();

        $this->cache->evictEntityRegion(State::class);

        $result2  = $this->em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertCount(4, $result1);
        self::assertInstanceOf(City::class, $result2[0]);
        self::assertInstanceOf(City::class, $result2[1]);
        self::assertInstanceOf(State::class, $result2[0]->getState());
        self::assertInstanceOf(State::class, $result2[1]->getState());

        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
    }

    public function testReloadQueryIfToManyAssociationItemIsNotFound()
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->evictRegions();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT s, c FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c';
        $result1    = $this->em->createQuery($dql)
                ->setCacheable(true)
                ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertInstanceOf(State::class, $result1[0]);
        self::assertInstanceOf(State::class, $result1[1]);
        self::assertCount(2, $result1[0]->getCities());
        self::assertCount(2, $result1[1]->getCities());

        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[0]->getCities()->get(1));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(0));
        self::assertInstanceOf(City::class, $result1[1]->getCities()->get(1));

        $this->em->clear();

        $this->cache->evictEntityRegion(City::class);

        $result2  = $this->em->createQuery($dql)
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

        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
    }

    public function testBasicNativeQueryCache()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $rsm = new ResultSetMapping;
        $rsm->addEntityResult(Country::class, 'c');
        $rsm->addFieldResult('c', 'name', 'name');
        $rsm->addFieldResult('c', 'id', 'id');

        $queryCount = $this->getCurrentQueryCount();
        $sql        = 'SELECT id, name FROM cache_country';
        $result1    = $this->em->createNativeQuery($sql, $rsm)->setCacheable(true)->getResult();

        self::assertCount(2, $result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[1]->getId(), $result1[1]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());
        self::assertEquals($this->countries[1]->getName(), $result1[1]->getName());

        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount($this->getDefaultQueryRegionName()));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getDefaultQueryRegionName()));

        $this->em->clear();

        $result2  = $this->em->createNativeQuery($sql, $rsm)
            ->setCacheable(true)
            ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
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

    public function testQueryDependsOnFirstAndMaxResultResult()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $result1    = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->setFirstResult(1)
            ->setMaxResults(1)
            ->getResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        $this->em->clear();

        $result2  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->setFirstResult(2)
            ->setMaxResults(1)
            ->getResult();

        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());

        $this->em->clear();

        $result3  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertEquals($queryCount + 3, $this->getCurrentQueryCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getMissCount());
    }

    public function testQueryCacheLifetime()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        $getHash = function(AbstractQuery $query){
            $method = new \ReflectionMethod($query, 'getHash');
            $method->setAccessible(true);

            return $method->invoke($query);
        };

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $query      = $this->em->createQuery($dql);
        $result1    = $query->setCacheable(true)
            ->setLifetime(3600)
            ->getResult();

        self::assertNotEmpty($result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());

        $this->em->clear();

        $key   = new QueryCacheKey($getHash($query), 3600);
        $entry = $this->cache->getQueryCache()
            ->getRegion()
            ->get($key);

        self::assertInstanceOf(Cache\QueryCacheEntry::class, $entry);
        $entry->time = $entry->time / 2;

        $this->cache->getQueryCache()
            ->getRegion()
            ->put($key, $entry);

        $result2  = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->setLifetime(3600)
            ->getResult();

        self::assertNotEmpty($result2);
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
    }

    public function testQueryCacheRegion()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->secondLevelCacheLogger->clearStats();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT c FROM Doctrine\Tests\Models\Cache\Country c';
        $query      = $this->em->createQuery($dql);

        $query1     = clone $query;
        $result1    = $query1->setCacheable(true)
            ->setCacheRegion('foo_region')
            ->getResult();

        self::assertNotEmpty($result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertEquals(0, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('foo_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('foo_region'));

        $query2     = clone $query;
        $result2    = $query2->setCacheable(true)
            ->setCacheRegion('bar_region')
            ->getResult();

        self::assertNotEmpty($result2);
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
        self::assertEquals(0, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('bar_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('bar_region'));

        $query3     = clone $query;
        $result3    = $query3->setCacheable(true)
            ->setCacheRegion('foo_region')
            ->getResult();

        self::assertNotEmpty($result3);
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
        self::assertEquals(3, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount('foo_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('foo_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('foo_region'));

        $query4     = clone $query;
        $result4    = $query4->setCacheable(true)
            ->setCacheRegion('bar_region')
            ->getResult();

        self::assertNotEmpty($result3);
        self::assertEquals($queryCount + 2, $this->getCurrentQueryCount());
        self::assertEquals(6, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount('bar_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionPutCount('bar_region'));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount('bar_region'));
    }

    public function testResolveAssociationCacheEntry()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->loadFixturesStates();

        $this->em->clear();

        $stateId     = $this->states[0]->getId();
        $countryName = $this->states[0]->getCountry()->getName();
        $dql         = 'SELECT s FROM Doctrine\Tests\Models\Cache\State s WHERE s.id = :id';
        $query       = $this->em->createQuery($dql);
        $queryCount  = $this->getCurrentQueryCount();

        $query1 = clone $query;
        $state1 = $query1
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertNotNull($state1);
        self::assertNotNull($state1->getCountry());
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertInstanceOf(State::class, $state1);
        self::assertInstanceOf(GhostObjectInterface::class, $state1->getCountry());
        self::assertEquals($countryName, $state1->getCountry()->getName());
        self::assertEquals($stateId, $state1->getId());

        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $query2     = clone $query;
        $state2     = $query2
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertNotNull($state2);
        self::assertNotNull($state2->getCountry());
        self::assertEquals($queryCount, $this->getCurrentQueryCount());
        self::assertInstanceOf(State::class, $state2);
        self::assertInstanceOf(GhostObjectInterface::class, $state2->getCountry());
        self::assertEquals($countryName, $state2->getCountry()->getName());
        self::assertEquals($stateId, $state2->getId());
    }

    public function testResolveToOneAssociationCacheEntry()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->evictRegions();

        $this->em->clear();

        $cityId      = $this->cities[0]->getId();
        $dql         = 'SELECT c, s FROM Doctrine\Tests\Models\Cache\City c JOIN c.state s WHERE c.id = :id';
        $query       = $this->em->createQuery($dql);
        $queryCount  = $this->getCurrentQueryCount();

        $query1 = clone $query;
        $city1 = $query1
            ->setParameter('id', $cityId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertInstanceOf(City::class, $city1);
        self::assertInstanceOf(State::class, $city1->getState());
        self::assertInstanceOf(City::class, $city1->getState()->getCities()->get(0));
        self::assertInstanceOf(State::class, $city1->getState()->getCities()->get(0)->getState());

        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $query2     = clone $query;
        $city2      = $query2
            ->setParameter('id', $cityId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertEquals($queryCount, $this->getCurrentQueryCount());
        self::assertInstanceOf(City::class, $city2);
        self::assertInstanceOf(State::class, $city2->getState());
        self::assertInstanceOf(City::class, $city2->getState()->getCities()->get(0));
        self::assertInstanceOf(State::class, $city2->getState()->getCities()->get(0)->getState());
    }

    public function testResolveToManyAssociationCacheEntry()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->evictRegions();

        $this->em->clear();

        $stateId     = $this->states[0]->getId();
        $dql         = 'SELECT s, c FROM Doctrine\Tests\Models\Cache\State s JOIN s.cities c WHERE s.id = :id';
        $query       = $this->em->createQuery($dql);
        $queryCount  = $this->getCurrentQueryCount();

        $query1 = clone $query;
        $state1 = $query1
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());
        self::assertInstanceOf(State::class, $state1);
        self::assertInstanceOf(GhostObjectInterface::class, $state1->getCountry());
        self::assertInstanceOf(City::class, $state1->getCities()->get(0));
        self::assertInstanceOf(State::class, $state1->getCities()->get(0)->getState());
        self::assertSame($state1, $state1->getCities()->get(0)->getState());

        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $query2     = clone $query;
        $state2     = $query2
            ->setParameter('id', $stateId)
            ->setCacheable(true)
            ->setMaxResults(1)
            ->getSingleResult();

        self::assertEquals($queryCount, $this->getCurrentQueryCount());
        self::assertInstanceOf(State::class, $state2);
        self::assertInstanceOf(GhostObjectInterface::class, $state2->getCountry());
        self::assertInstanceOf(City::class, $state2->getCities()->get(0));
        self::assertInstanceOf(State::class, $state2->getCities()->get(0)->getState());
        self::assertSame($state2, $state2->getCities()->get(0)->getState());
    }

    public function testHintClearEntityRegionUpdateStatement()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->em->createQuery('DELETE Doctrine\Tests\Models\Cache\Country u WHERE u.id = 4')
            ->setHint(Query::HINT_CACHE_EVICT, true)
            ->execute();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));
    }

    public function testHintClearEntityRegionDeleteStatement()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));

        $this->em->createQuery("UPDATE Doctrine\Tests\Models\Cache\Country u SET u.name = 'foo' WHERE u.id = 1")
            ->setHint(Query::HINT_CACHE_EVICT, true)
            ->execute();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[1]->getId()));
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Second level cache does not support partial entities.
     */
    public function testCacheablePartialQueryException()
    {
        $this->evictRegions();
        $this->loadFixturesCountries();

        $this->em->createQuery("SELECT PARTIAL c.{id} FROM Doctrine\Tests\Models\Cache\Country c")
            ->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true)
            ->setCacheable(true)
            ->getResult();
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Second-level cache query supports only select statements.
     */
    public function testNonCacheableQueryDeleteStatementException()
    {
        $this->em->createQuery("DELETE Doctrine\Tests\Models\Cache\Country u WHERE u.id = 4")
            ->setCacheable(true)
            ->getResult();
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Second-level cache query supports only select statements.
     */
    public function testNonCacheableQueryUpdateStatementException()
    {
        $this->em->createQuery("UPDATE Doctrine\Tests\Models\Cache\Country u SET u.name = 'foo' WHERE u.id = 4")
            ->setCacheable(true)
            ->getResult();
    }

    public function testQueryCacheShouldBeEvictedOnTimestampUpdate()
    {
        $this->loadFixturesCountries();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();
        $dql        = 'SELECT country FROM Doctrine\Tests\Models\Cache\Country country';

        $result1    = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(2, $result1);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        $this->em->persist(new Country('France'));
        $this->em->flush();
        $this->em->clear();

        $queryCount = $this->getCurrentQueryCount();

        $result2 = $this->em->createQuery($dql)
            ->setCacheable(true)
            ->getResult();

        self::assertCount(3, $result2);
        self::assertEquals($queryCount + 1, $this->getCurrentQueryCount());

        foreach ($result2 as $entity) {
            self::assertInstanceOf(Country::class, $entity);
        }
    }
}
