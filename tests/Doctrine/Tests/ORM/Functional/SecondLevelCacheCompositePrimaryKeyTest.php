<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use DateTime;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\Flight;

/**
 * @group DDC-2183
 */
class SecondLevelCacheCompositePrimaryKeyTest extends SecondLevelCacheAbstractTest
{
    public function testPutAndLoadCompositPrimaryKeyEntities(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->_em->clear();
        $this->evictRegions();

        $leavingFromId = $this->cities[0]->getId();
        $goingToId     = $this->cities[1]->getId();
        $leavingFrom   = $this->_em->find(City::class, $leavingFromId);
        $goingTo       = $this->_em->find(City::class, $goingToId);
        $flight        = new Flight($leavingFrom, $goingTo);
        $id            = [
            'leavingFrom'   => $leavingFromId,
            'goingTo'       => $goingToId,
        ];

        $flight->setDeparture(new DateTime('tomorrow'));

        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $this->_em->persist($flight);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertTrue($this->cache->containsEntity(Flight::class, $id));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $queryCount  = $this->getCurrentQueryCount();
        $flight      = $this->_em->find(Flight::class, $id);
        $leavingFrom = $flight->getLeavingFrom();
        $goingTo     = $flight->getGoingTo();

        $this->assertInstanceOf(Flight::class, $flight);
        $this->assertInstanceOf(City::class, $goingTo);
        $this->assertInstanceOf(City::class, $leavingFrom);

        $this->assertEquals($goingTo->getId(), $goingToId);
        $this->assertEquals($leavingFrom->getId(), $leavingFromId);
        $this->assertEquals($queryCount, $this->getCurrentQueryCount());
    }

    public function testRemoveCompositPrimaryKeyEntities(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->_em->clear();
        $this->evictRegions();

        $leavingFromId = $this->cities[0]->getId();
        $goingToId     = $this->cities[1]->getId();
        $leavingFrom   = $this->_em->find(City::class, $leavingFromId);
        $goingTo       = $this->_em->find(City::class, $goingToId);
        $flight        = new Flight($leavingFrom, $goingTo);
        $id            = [
            'leavingFrom'   => $leavingFromId,
            'goingTo'       => $goingToId,
        ];

        $flight->setDeparture(new DateTime('tomorrow'));

        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $this->_em->persist($flight);
        $this->_em->flush();

        $this->assertTrue($this->cache->containsEntity(Flight::class, $id));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $this->_em->remove($flight);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertFalse($this->cache->containsEntity(Flight::class, $id));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $this->assertNull($this->_em->find(Flight::class, $id));
    }

    public function testUpdateCompositPrimaryKeyEntities(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->_em->clear();
        $this->evictRegions();

        $now           = new DateTime('now');
        $tomorrow      = new DateTime('tomorrow');
        $leavingFromId = $this->cities[0]->getId();
        $goingToId     = $this->cities[1]->getId();
        $leavingFrom   = $this->_em->find(City::class, $leavingFromId);
        $goingTo       = $this->_em->find(City::class, $goingToId);
        $flight        = new Flight($leavingFrom, $goingTo);
        $id            = [
            'leavingFrom'   => $leavingFromId,
            'goingTo'       => $goingToId,
        ];

        $flight->setDeparture($now);

        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $this->_em->persist($flight);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertTrue($this->cache->containsEntity(Flight::class, $id));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $queryCount  = $this->getCurrentQueryCount();
        $flight      = $this->_em->find(Flight::class, $id);
        $leavingFrom = $flight->getLeavingFrom();
        $goingTo     = $flight->getGoingTo();

        $this->assertInstanceOf(Flight::class, $flight);
        $this->assertInstanceOf(City::class, $goingTo);
        $this->assertInstanceOf(City::class, $leavingFrom);

        $this->assertEquals($goingTo->getId(), $goingToId);
        $this->assertEquals($flight->getDeparture(), $now);
        $this->assertEquals($leavingFrom->getId(), $leavingFromId);
        $this->assertEquals($leavingFrom->getId(), $leavingFromId);
        $this->assertEquals($queryCount, $this->getCurrentQueryCount());

        $flight->setDeparture($tomorrow);

        $this->_em->persist($flight);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertTrue($this->cache->containsEntity(Flight::class, $id));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[0]->getId()));
        $this->assertTrue($this->cache->containsEntity(City::class, $this->cities[1]->getId()));

        $queryCount  = $this->getCurrentQueryCount();
        $flight      = $this->_em->find(Flight::class, $id);
        $leavingFrom = $flight->getLeavingFrom();
        $goingTo     = $flight->getGoingTo();

        $this->assertInstanceOf(Flight::class, $flight);
        $this->assertInstanceOf(City::class, $goingTo);
        $this->assertInstanceOf(City::class, $leavingFrom);

        $this->assertEquals($goingTo->getId(), $goingToId);
        $this->assertEquals($flight->getDeparture(), $tomorrow);
        $this->assertEquals($leavingFrom->getId(), $leavingFromId);
        $this->assertEquals($leavingFrom->getId(), $leavingFromId);
        $this->assertEquals($queryCount, $this->getCurrentQueryCount());
    }
}
