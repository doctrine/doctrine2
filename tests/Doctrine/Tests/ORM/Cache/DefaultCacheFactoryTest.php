<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Persister\Collection\CachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\NonStrictReadWriteCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\ReadOnlyCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Collection\ReadWriteCachedCollectionPersister;
use Doctrine\ORM\Cache\Persister\Entity\CachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\NonStrictReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadOnlyCachedEntityPersister;
use Doctrine\ORM\Cache\Persister\Entity\ReadWriteCachedEntityPersister;
use Doctrine\ORM\Cache\Region\DefaultMultiGetRegion;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Cache\RegionsConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\CacheMetadata;
use Doctrine\ORM\Mapping\CacheUsage;
use Doctrine\ORM\Mapping\MetadataCollection;
use Doctrine\ORM\Persisters\Collection\OneToManyPersister;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\Tests\Mocks\ConcurrentRegionMock;
use Doctrine\Tests\Models\Cache\AttractionContactInfo;
use Doctrine\Tests\Models\Cache\AttractionLocationInfo;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\OrmTestCase;

/**
 * @group DDC-2183
 */
class DefaultCacheFactoryTest extends OrmTestCase
{
    /** @var CacheFactory */
    private $factory;

    /** @var EntityManagerInterface */
    private $em;

    /** @var RegionsConfiguration */
    private $regionsConfig;

    protected function setUp() : void
    {
        parent::setUp();

        $this->em            = $this->getTestEntityManager();
        /*
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->em            = $this->getTestEntityManager();
        $this->regionsConfig = new RegionsConfiguration();

        $arguments = [
            $this->regionsConfig,
            $this->getSharedSecondLevelCacheDriverImpl(),
        ];

        $this->factory = $this->getMockBuilder(DefaultCacheFactory::class)
            ->setMethods(['getRegion'])
            ->setConstructorArgs($arguments)
            ->getMock()
        ;
        */
    }

    public function testImplementsCacheFactory() : void
    {
        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $this->getSharedSecondLevelCacheDriverImpl());
        self::assertInstanceOf(CacheFactory::class, $factory);
    }

    public function testBuildCachedEntityPersisterReadOnly() : void
    {
        $em        = $this->em;
        $metadata  = clone $em->getClassMetadata(State::class);
        $persister = new BasicEntityPersister($em, $metadata);
        $region    = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $metadata->setCache(
            new CacheMetadata(CacheUsage::READ_ONLY, 'doctrine_tests_models_cache_state')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($metadata->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedEntityPersister($em, $persister, $metadata);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadOnlyCachedEntityPersister::class, $cachedPersister);
    }

    public function testBuildCachedEntityPersisterReadWrite() : void
    {
        $em        = $this->em;
        $metadata  = clone $em->getClassMetadata(State::class);
        $persister = new BasicEntityPersister($em, $metadata);
        $region    = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $metadata->setCache(
            new CacheMetadata(CacheUsage::READ_WRITE, 'doctrine_tests_models_cache_state')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($metadata->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedEntityPersister($em, $persister, $metadata);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadWriteCachedEntityPersister::class, $cachedPersister);
    }

    public function testBuildCachedEntityPersisterNonStrictReadWrite() : void
    {
        $em        = $this->em;
        $metadata  = clone $em->getClassMetadata(State::class);
        $persister = new BasicEntityPersister($em, $metadata);
        $region    = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $metadata->setCache(
            new CacheMetadata(CacheUsage::NONSTRICT_READ_WRITE, 'doctrine_tests_models_cache_state')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($metadata->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedEntityPersister($em, $persister, $metadata);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister);
        self::assertInstanceOf(NonStrictReadWriteCachedEntityPersister::class, $cachedPersister);
    }

    public function testBuildCachedCollectionPersisterReadOnly() : void
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->getProperty('cities');
        $persister   = new OneToManyPersister($em);
        $region      = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $association->setCache(
            new CacheMetadata(CacheUsage::READ_ONLY, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($association->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedCollectionPersister($em, $persister, $association);

        self::assertInstanceOf(CachedCollectionPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadOnlyCachedCollectionPersister::class, $cachedPersister);
    }

    public function testBuildCachedCollectionPersisterReadWrite() : void
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->getProperty('cities');
        $persister   = new OneToManyPersister($em);
        $region      = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $association->setCache(
            new CacheMetadata(CacheUsage::READ_WRITE, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($association->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedCollectionPersister($em, $persister, $association);

        self::assertInstanceOf(CachedCollectionPersister::class, $cachedPersister);
        self::assertInstanceOf(ReadWriteCachedCollectionPersister::class, $cachedPersister);
    }

    public function testBuildCachedCollectionPersisterNonStrictReadWrite() : void
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->getProperty('cities');
        $persister   = new OneToManyPersister($em);
        $region      = new ConcurrentRegionMock(
            new DefaultRegion('regionName', $this->getSharedSecondLevelCacheDriverImpl())
        );

        $association->setCache(
            new CacheMetadata(CacheUsage::NONSTRICT_READ_WRITE, 'doctrine_tests_models_cache_state__cities')
        );

        $this->factory->expects($this->once())
            ->method('getRegion')
            ->with($this->equalTo($association->getCache()))
            ->will($this->returnValue($region));

        $cachedPersister = $this->factory->buildCachedCollectionPersister($em, $persister, $association);

        self::assertInstanceOf(CachedCollectionPersister::class, $cachedPersister);
        self::assertInstanceOf(NonStrictReadWriteCachedCollectionPersister::class, $cachedPersister);
    }

    public function testInheritedEntityCacheRegion() : void
    {
        $em         = $this->em;
        $metadata1  = clone $em->getClassMetadata(AttractionContactInfo::class);
        $metadata2  = clone $em->getClassMetadata(AttractionLocationInfo::class);
        $persister1 = new BasicEntityPersister($em, $metadata1);
        $persister2 = new BasicEntityPersister($em, $metadata2);
        $factory    = new DefaultCacheFactory($this->regionsConfig, $this->getSharedSecondLevelCacheDriverImpl());

        $cachedPersister1 = $factory->buildCachedEntityPersister($em, $persister1, $metadata1);
        $cachedPersister2 = $factory->buildCachedEntityPersister($em, $persister2, $metadata2);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister1);
        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister2);

        self::assertNotSame($cachedPersister1, $cachedPersister2);
        self::assertSame($cachedPersister1->getCacheRegion(), $cachedPersister2->getCacheRegion());
    }

    public function testCreateNewCacheDriver() : void
    {
        $em         = $this->em;
        $metadata1  = clone $em->getClassMetadata(State::class);
        $metadata2  = clone $em->getClassMetadata(City::class);
        $persister1 = new BasicEntityPersister($em, $metadata1);
        $persister2 = new BasicEntityPersister($em, $metadata2);
        $factory    = new DefaultCacheFactory($this->regionsConfig, $this->getSharedSecondLevelCacheDriverImpl());

        $cachedPersister1 = $factory->buildCachedEntityPersister($em, $persister1, $metadata1);
        $cachedPersister2 = $factory->buildCachedEntityPersister($em, $persister2, $metadata2);

        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister1);
        self::assertInstanceOf(CachedEntityPersister::class, $cachedPersister2);

        self::assertNotSame($cachedPersister1, $cachedPersister2);
        self::assertNotSame($cachedPersister1->getCacheRegion(), $cachedPersister2->getCacheRegion());
    }

    public function testBuildCachedEntityPersisterNonStrictException() : void
    {
        $em        = $this->em;
        $metadata  = clone $em->getClassMetadata(State::class);
        $persister = new BasicEntityPersister($em, $metadata);

        $metadata->setCache(
            new CacheMetadata('-1', 'doctrine_tests_models_cache_state')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unrecognized access strategy type [-1]');

        $this->factory->buildCachedEntityPersister($em, $persister, $metadata);
    }

    public function testBuildCachedCollectionPersisterException() : void
    {
        $em          = $this->em;
        $metadata    = clone $em->getClassMetadata(State::class);
        $association = $metadata->getProperty('cities');
        $persister   = new OneToManyPersister($em);

        $association->setCache(
            new CacheMetadata('-1', 'doctrine_tests_models_cache_state__cities')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unrecognized access strategy type [-1]');

        $this->factory->buildCachedCollectionPersister($em, $persister, $association);
    }

    public function testInvalidFileLockRegionDirectoryException() : void
    {
        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $this->getSharedSecondLevelCacheDriverImpl());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'If you want to use a "READ_WRITE" cache an implementation of "Doctrine\ORM\Cache\ConcurrentRegion" '
            . 'is required, The default implementation provided by doctrine is '
            . '"Doctrine\ORM\Cache\Region\FileLockRegion" if you want to use it please provide a valid directory'
        );

        $fooCache = new CacheMetadata(CacheUsage::READ_WRITE, 'foo');
        $factory->getRegion($fooCache);
    }

    public function testInvalidFileLockRegionDirectoryExceptionWithEmptyString() : void
    {
        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $this->getSharedSecondLevelCacheDriverImpl());

        $factory->setFileLockRegionDirectory('');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'If you want to use a "READ_WRITE" cache an implementation of "Doctrine\ORM\Cache\ConcurrentRegion" '
            . 'is required, The default implementation provided by doctrine is '
            . '"Doctrine\ORM\Cache\Region\FileLockRegion" if you want to use it please provide a valid directory'
        );

        $fooCache = new CacheMetadata(CacheUsage::READ_WRITE, 'foo');
        $factory->getRegion($fooCache);
    }

    public function testBuildsNewNamespacedCacheInstancePerRegionInstance() : void
    {
        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $this->getSharedSecondLevelCacheDriverImpl());

        $fooCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'foo');
        $fooRegion = $factory->getRegion($fooCache);

        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        self::assertSame('foo', $fooRegion->getCache()->getNamespace());
        self::assertSame('bar', $barRegion->getCache()->getNamespace());
    }

    public function testAppendsNamespacedCacheInstancePerRegionInstanceWhenItsAlreadySet() : void
    {
        $cache = clone $this->getSharedSecondLevelCacheDriverImpl();
        $cache->setNamespace('testing');

        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $cache);

        $fooCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'foo');
        $fooRegion = $factory->getRegion($fooCache);

        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        self::assertSame('testing:foo', $fooRegion->getCache()->getNamespace());
        self::assertSame('testing:bar', $barRegion->getCache()->getNamespace());
    }

    public function testBuildsDefaultCacheRegionFromGenericCacheRegion() : void
    {
        /** @var Cache $cache */
        $cache   = $this->createMock(Cache::class);
        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $cache);

        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        self::assertInstanceOf(DefaultRegion::class, $barRegion);
    }

    public function testBuildsMultiGetCacheRegionFromGenericCacheRegion() : void
    {
        /** @var CacheProvider $cache */
        $cache   = $this->getMockForAbstractClass(CacheProvider::class);
        $factory = new DefaultCacheFactory(new RegionsConfiguration(), $cache);

        $barCache  = new CacheMetadata(CacheUsage::READ_ONLY, 'bar');
        $barRegion = $factory->getRegion($barCache);

        self::assertInstanceOf(DefaultMultiGetRegion::class, $barRegion);
    }
}
