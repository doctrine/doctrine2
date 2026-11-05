<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\Lock;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Tests\Mocks\ConcurrentRegionMock;
use Doctrine\Tests\Mocks\OverridableMetadataFactory;
use Doctrine\Tests\Mocks\TimestampRegionMock;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\Models\Cache\State;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;

use function assert;

#[Group('DDC-2183')]
class SecondLevelCacheConcurrentTest extends SecondLevelCacheFunctionalTestCase
{
    private CacheFactorySecondLevelCacheConcurrentTest $cacheFactory;

    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $countryMetadata                 = clone $this->_em->getClassMetadata(Country::class);
        $countryMetadata->cache['usage'] = ClassMetadata::CACHE_USAGE_NONSTRICT_READ_WRITE;

        $this->_em = $this->getEntityManager(metadataFactoryClassName: OverridableMetadataFactory::class);

        $this->cacheFactory = new CacheFactorySecondLevelCacheConcurrentTest($this->getSharedSecondLevelCache());

        $this->_em->getConfiguration()
            ->getSecondLevelCacheConfiguration()
            ->setCacheFactory($this->cacheFactory);

        $this->cache = $this->_em->getCache();

        $metadataFactory = $this->_em->getMetadataFactory();
        assert($metadataFactory instanceof OverridableMetadataFactory);
        $metadataFactory->setMetadataFor(Country::class, $countryMetadata);
    }

    public function testBasicConcurrentEntityReadLock(): void
    {
        $this->loadFixturesCountries();
        $this->_em->clear();

        $countryId = $this->countries[0]->getId();
        $cacheId   = new EntityCacheKey(Country::class, ['id' => $countryId]);
        $region    = $this->_em->getCache()->getEntityCacheRegion(Country::class);
        assert($region instanceof ConcurrentRegionMock);

        self::assertTrue($this->cache->containsEntity(Country::class, $countryId));

        $region->setLock($cacheId, Lock::createLockRead()); // another proc lock the entity cache

        self::assertFalse($this->cache->containsEntity(Country::class, $countryId));

        $this->getQueryLog()->reset()->enable();
        $country = $this->_em->find(Country::class, $countryId);

        self::assertInstanceOf(Country::class, $country);
        $this->assertQueryCount(1);
        self::assertFalse($this->cache->containsEntity(Country::class, $countryId));
    }

    public function testBasicConcurrentCollectionReadLock(): void
    {
        $this->loadFixturesCountries();
        $this->loadFixturesStates();
        $this->loadFixturesCities();

        $this->_em->clear();
        $this->evictRegions();

        $stateId = $this->states[0]->getId();
        $state   = $this->_em->find(State::class, $stateId);

        self::assertInstanceOf(State::class, $state);
        self::assertInstanceOf(Country::class, $state->getCountry());
        self::assertNotNull($state->getCountry()->getName());
        self::assertCount(2, $state->getCities());

        $this->_em->clear();
        $this->secondLevelCacheLogger->clearStats();

        $stateId = $this->states[0]->getId();
        $cacheId = new CollectionCacheKey(State::class, 'cities', ['id' => $stateId]);
        $region  = $this->_em->getCache()->getCollectionCacheRegion(State::class, 'cities');
        assert($region instanceof ConcurrentRegionMock);

        self::assertTrue($this->cache->containsCollection(State::class, 'cities', $stateId));

        $region->setLock($cacheId, Lock::createLockRead()); // another proc lock the entity cache

        self::assertFalse($this->cache->containsCollection(State::class, 'cities', $stateId));

        $this->getQueryLog()->reset()->enable();
        $state = $this->_em->find(State::class, $stateId);

        self::assertEquals(0, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getHitCount());

        self::assertEquals(0, $this->secondLevelCacheLogger->getRegionMissCount($this->getEntityRegion(State::class)));
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionHitCount($this->getEntityRegion(State::class)));

        self::assertInstanceOf(State::class, $state);
        self::assertCount(2, $state->getCities());

        self::assertEquals(1, $this->secondLevelCacheLogger->getMissCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getHitCount());
        self::assertEquals(1, $this->secondLevelCacheLogger->getRegionMissCount($this->getCollectionRegion(State::class, 'cities')));

        $this->assertQueryCount(1);
        self::assertFalse($this->cache->containsCollection(State::class, 'cities', $stateId));
    }
}

class CacheFactorySecondLevelCacheConcurrentTest extends DefaultCacheFactory
{
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function getRegion(array $cache): ConcurrentRegionMock
    {
        $region = new DefaultRegion($cache['region'], $this->cache);

        return new ConcurrentRegionMock($region);
    }

    public function getTimestampRegion(): TimestampRegionMock
    {
        return new TimestampRegionMock();
    }
}
