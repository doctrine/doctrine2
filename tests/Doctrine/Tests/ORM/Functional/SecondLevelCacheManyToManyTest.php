<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\Models\Cache\Travel;
use Doctrine\Tests\Models\Cache\Traveler;

/**
 * @group DDC-2183
 */
class SecondLevelCacheManyToManyTest extends SecondLevelCacheAbstractTest
{
    public function testShouldPutManyToManyCollectionOwningSideOnPersist() : void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->loadFixturesTraveler();
        $this->loadFixturesTravels();
        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Travel::class, $this->travels[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Travel::class, $this->travels[1]->getId()));

        self::assertTrue($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[0]->getId()));
        self::assertTrue($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[1]->getId()));

        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[2]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[3]->getId()));
    }

    public function testPutAndLoadManyToManyRelation() : void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->loadFixturesTraveler();
        $this->loadFixturesTravels();

        $this->em->clear();
        $this->cache->evictEntityRegion(City::class);
        $this->cache->evictEntityRegion(Travel::class);
        $this->cache->evictCollectionRegion(Travel::class, 'visitedCities');

        $this->secondLevelCacheLogger->clearStats();

        self::assertFalse($this->cache->containsEntity(Travel::class, $this->travels[0]->getId()));
        self::assertFalse($this->cache->containsEntity(Travel::class, $this->travels[1]->getId()));

        self::assertFalse($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[0]->getId()));
        self::assertFalse($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[1]->getId()));

        self::assertFalse($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        self::assertFalse($this->cache->containsEntity(City::class, $this->cities[1]->getId()));
        self::assertFalse($this->cache->containsEntity(City::class, $this->cities[2]->getId()));
        self::assertFalse($this->cache->containsEntity(City::class, $this->cities[3]->getId()));

        $t1 = $this->em->find(Travel::class, $this->travels[0]->getId());
        $t2 = $this->em->find(Travel::class, $this->travels[1]->getId());

        self::assertEquals(2, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionPutCount($this->getEntityRegion(Travel::class)));
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionMissCount($this->getEntityRegion(Travel::class)));

        //trigger lazy load
        self::assertCount(3, $t1->getVisitedCities());
        self::assertCount(2, $t2->getVisitedCities());

        self::assertEquals(4, $this->secondLevelCacheLogger->getPutCount());
        self::assertEquals(4, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionPutCount($this->getCollectionRegion(Travel::class, 'visitedCities')));
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionMissCount($this->getCollectionRegion(Travel::class, 'visitedCities')));

        self::assertInstanceOf(City::class, $t1->getVisitedCities()->get(0));
        self::assertInstanceOf(City::class, $t1->getVisitedCities()->get(1));
        self::assertInstanceOf(City::class, $t1->getVisitedCities()->get(2));

        self::assertInstanceOf(City::class, $t2->getVisitedCities()->get(0));
        self::assertInstanceOf(City::class, $t2->getVisitedCities()->get(1));

        self::assertTrue($this->cache->containsEntity(Travel::class, $this->travels[0]->getId()));
        self::assertTrue($this->cache->containsEntity(Travel::class, $this->travels[1]->getId()));

        self::assertTrue($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[0]->getId()));
        self::assertTrue($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[1]->getId()));

        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[2]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[3]->getId()));

        $this->em->clear();
        $this->secondLevelCacheLogger->clearStats();

        $queryCount = $this->getCurrentQueryCount();

        $t3 = $this->em->find(Travel::class, $this->travels[0]->getId());
        $t4 = $this->em->find(Travel::class, $this->travels[1]->getId());

        //trigger lazy load from cache
        self::assertCount(3, $t3->getVisitedCities());
        self::assertCount(2, $t4->getVisitedCities());

        self::assertInstanceOf(City::class, $t3->getVisitedCities()->get(0));
        self::assertInstanceOf(City::class, $t3->getVisitedCities()->get(1));
        self::assertInstanceOf(City::class, $t3->getVisitedCities()->get(2));

        self::assertInstanceOf(City::class, $t4->getVisitedCities()->get(0));
        self::assertInstanceOf(City::class, $t4->getVisitedCities()->get(1));

        self::assertEquals(4, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionHitCount($this->getEntityRegion(Travel::class)));
        self::assertEquals(2, $this->secondLevelCacheLogger->getRegionHitCount($this->getCollectionRegion(Travel::class, 'visitedCities')));

        self::assertNotSame($t1->getVisitedCities()->get(0), $t3->getVisitedCities()->get(0));
        self::assertEquals($t1->getVisitedCities()->get(0)->getId(), $t3->getVisitedCities()->get(0)->getId());
        self::assertEquals($t1->getVisitedCities()->get(0)->getName(), $t3->getVisitedCities()->get(0)->getName());

        self::assertNotSame($t1->getVisitedCities()->get(1), $t3->getVisitedCities()->get(1));
        self::assertEquals($t1->getVisitedCities()->get(1)->getId(), $t3->getVisitedCities()->get(1)->getId());
        self::assertEquals($t1->getVisitedCities()->get(1)->getName(), $t3->getVisitedCities()->get(1)->getName());

        self::assertNotSame($t1->getVisitedCities()->get(2), $t3->getVisitedCities()->get(2));
        self::assertEquals($t1->getVisitedCities()->get(2)->getId(), $t3->getVisitedCities()->get(2)->getId());
        self::assertEquals($t1->getVisitedCities()->get(2)->getName(), $t3->getVisitedCities()->get(2)->getName());

        self::assertNotSame($t2->getVisitedCities()->get(0), $t4->getVisitedCities()->get(0));
        self::assertEquals($t2->getVisitedCities()->get(0)->getId(), $t4->getVisitedCities()->get(0)->getId());
        self::assertEquals($t2->getVisitedCities()->get(0)->getName(), $t4->getVisitedCities()->get(0)->getName());

        self::assertNotSame($t2->getVisitedCities()->get(1), $t4->getVisitedCities()->get(1));
        self::assertEquals($t2->getVisitedCities()->get(1)->getId(), $t4->getVisitedCities()->get(1)->getId());
        self::assertEquals($t2->getVisitedCities()->get(1)->getName(), $t4->getVisitedCities()->get(1)->getName());

        self::assertEquals(4, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals($queryCount, $this->getCurrentQueryCount());
    }

    public function testStoreManyToManyAssociationWhitCascade() : void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->cache->evictEntityRegion(City::class);
        $this->cache->evictEntityRegion(Traveler::class);
        $this->cache->evictEntityRegion(Travel::class);
        $this->cache->evictCollectionRegion(State::class, 'cities');
        $this->cache->evictCollectionRegion(Traveler::class, 'travels');

        $traveler = new Traveler('Doctrine Bot');
        $travel   = new Travel($traveler);

        $travel->addVisitedCity($this->cities[0]);
        $travel->addVisitedCity($this->cities[1]);
        $travel->addVisitedCity($this->cities[3]);

        $this->em->persist($traveler);
        $this->em->persist($travel);
        $this->em->flush();
        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Travel::class, $travel->getId()));
        self::assertTrue($this->cache->containsEntity(Traveler::class, $traveler->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));
        self::assertTrue($this->cache->containsEntity(City::class, $this->cities[3]->getId()));
        self::assertTrue($this->cache->containsCollection(Travel::class, 'visitedCities', $travel->getId()));

        $queryCount1 = $this->getCurrentQueryCount();
        $t1          = $this->em->find(Travel::class, $travel->getId());

        self::assertInstanceOf(Travel::class, $t1);
        self::assertCount(3, $t1->getVisitedCities());
        self::assertEquals($queryCount1, $this->getCurrentQueryCount());
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Cannot update a readonly collection "Doctrine\Tests\Models\Cache\Travel#visitedCities
     */
    public function testReadOnlyCollection() : void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->loadFixturesTraveler();
        $this->loadFixturesTravels();

        $this->em->clear();

        self::assertTrue($this->cache->containsEntity(Travel::class, $this->travels[0]->getId()));
        self::assertTrue($this->cache->containsCollection(Travel::class, 'visitedCities', $this->travels[0]->getId()));

        $travel = $this->em->find(Travel::class, $this->travels[0]->getId());

        self::assertCount(3, $travel->getVisitedCities());

        $travel->getVisitedCities()->remove(0);

        $this->em->persist($travel);
        $this->em->flush();
    }

    public function testManyToManyWithEmptyRelation() : void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->loadFixturesTraveler();
        $this->loadFixturesTravels();
        $this->em->clear();

        $this->evictRegions();

        $queryCount = $this->getCurrentQueryCount();

        $entitiId = $this->travels[2]->getId(); //empty travel
        $entity   = $this->em->find(Travel::class, $entitiId);

        self::assertEquals(0, $entity->getVisitedCities()->count());
        self::assertEquals($queryCount+2, $this->getCurrentQueryCount());

        $this->em->clear();

        $entity = $this->em->find(Travel::class, $entitiId);

        $queryCount = $this->getCurrentQueryCount();
        self::assertEquals(0, $entity->getVisitedCities()->count());
        self::assertEquals($queryCount, $this->getCurrentQueryCount());
    }
}
