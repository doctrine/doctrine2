<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\State;
use PHPUnit\Framework\Attributes\Group;

#[Group('DDC-2183')]
class SecondLevelCacheCriteriaTest extends SecondLevelCacheFunctionalTestCase
{
    public function testMatchingPut(): void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->evictRegions();
        $this->_em->clear();

        self::assertFalse($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));

        $repository = $this->_em->getRepository(Country::class);
        $this->getQueryLog()->reset()->enable();
        $name    = $this->countries[0]->getName();
        $result1 = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $name),
        ));

        // Because matching returns lazy collection, we force initialization
        $result1->toArray();

        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));

        $this->_em->clear();

        $result2 = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $name),
        ));

        $this->assertQueryCount(1);
        self::assertCount(1, $result2);

        self::assertInstanceOf(Country::class, $result2[0]);

        self::assertEquals($result1[0]->getId(), $result2[0]->getId());
        self::assertEquals($result1[0]->getName(), $result2[0]->getName());
    }

    public function testRepositoryMatching(): void
    {
        $this->evictRegions();

        $this->loadFixturesCountries();
        $this->_em->clear();

        self::assertTrue($this->cache->containsEntity(Country::class, $this->countries[0]->getId()));

        $repository = $this->_em->getRepository(Country::class);
        $this->getQueryLog()->reset()->enable();
        $result1 = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $this->countries[0]->getName()),
        ));

        // Because matching returns lazy collection, we force initialization
        $result1->toArray();

        self::assertCount(1, $result1);
        $this->assertQueryCount(1);
        self::assertEquals($this->countries[0]->getId(), $result1[0]->getId());
        self::assertEquals($this->countries[0]->getName(), $result1[0]->getName());

        $this->_em->clear();

        $result2 = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $this->countries[0]->getName()),
        ));

        // Because matching returns lazy collection, we force initialization
        $result2->toArray();

        $this->assertQueryCount(1);
        self::assertCount(1, $result2);

        self::assertInstanceOf(Country::class, $result2[0]);

        self::assertEquals($this->countries[0]->getId(), $result2[0]->getId());
        self::assertEquals($this->countries[0]->getName(), $result2[0]->getName());

        $result3 = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $this->countries[1]->getName()),
        ));

        // Because matching returns lazy collection, we force initialization
        $result3->toArray();

        $this->assertQueryCount(2);
        self::assertCount(1, $result3);

        self::assertInstanceOf(Country::class, $result3[0]);

        self::assertEquals($this->countries[1]->getId(), $result3[0]->getId());
        self::assertEquals($this->countries[1]->getName(), $result3[0]->getName());

        $result4 = $repository->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $this->countries[1]->getName()),
        ));

        $this->assertQueryCount(2);
        self::assertCount(1, $result4);

        self::assertInstanceOf(Country::class, $result4[0]);

        self::assertEquals($this->countries[1]->getId(), $result4[0]->getId());
        self::assertEquals($this->countries[1]->getName(), $result4[0]->getName());
    }

    public function testCollectionMatching(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();
        $this->_em->clear();
        $this->secondLevelCacheLogger->clearStats();

        $entity   = $this->_em->find(State::class, $this->states[0]->getId());
        $itemName = $this->states[0]->getCities()->get(0)->getName();
        $this->getQueryLog()->reset()->enable();
        $collection = $entity->getCities();
        $matching   = $collection->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $itemName),
        ));

        $this->assertQueryCount(1);
        self::assertInstanceOf(Collection::class, $matching);
        self::assertCount(1, $matching);

        $this->_em->clear();

        $entity = $this->_em->find(State::class, $this->states[0]->getId());
        $this->getQueryLog()->reset()->enable();
        $collection = $entity->getCities();
        $matching   = $collection->matching(Criteria::create()->where(
            Criteria::expr()->eq('name', $itemName),
        ));

        $this->assertQueryCount(0);
        self::assertInstanceOf(Collection::class, $matching);
        self::assertCount(1, $matching);
    }
}
