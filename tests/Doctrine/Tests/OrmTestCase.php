<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\Common\Annotations;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Logging\StatisticsCacheLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\MetadataCollection;
use Doctrine\ORM\Proxy\Factory\ProxyFactory;
use function is_array;
use function realpath;

/**
 * Base testcase class for all ORM testcases.
 */
abstract class OrmTestCase extends DoctrineTestCase
{
    /**
     * The metadata cache that is shared between all ORM tests (except functional tests).
     *
     * @var Cache|null
     */
    private static $metadataCacheImpl = null;

    /**
     * The query cache that is shared between all ORM tests (except functional tests).
     *
     * @var Cache|null
     */
    private static $queryCacheImpl = null;

    /** @var bool */
    protected $isSecondLevelCacheEnabled = false;

    /** @var bool */
    protected $isSecondLevelCacheLogEnabled = false;

    /** @var CacheFactory */
    protected $secondLevelCacheFactory;

    /** @var StatisticsCacheLogger */
    protected $secondLevelCacheLogger;

    /** @var Cache|null */
    protected $secondLevelCacheDriverImpl;

    /**
     * @param array $paths
     *
     * @return AnnotationDriver
     */
    protected function createAnnotationDriver($paths = [])
    {
        $reader = new Annotations\CachedReader(new Annotations\AnnotationReader(), new ArrayCache());

        Annotations\AnnotationRegistry::registerFile(__DIR__ . '/../../../lib/Doctrine/ORM/Annotation/DoctrineAnnotations.php');

        return new AnnotationDriver($reader, (array) $paths);
    }

    /**
     * Creates an EntityManager for testing purposes.
     *
     * NOTE: The created EntityManager will have its dependant DBAL parts completely
     * mocked out using a DriverMock, ConnectionMock, etc. These mocks can then
     * be configured in the tests to simulate the DBAL behavior that is desired
     * for a particular test,
     *
     * @param Connection|array  $conn
     * @param EventManager|null $eventManager
     * @param bool              $withSharedMetadata
     *
     * @return EntityManagerInterface
     */
    protected function getTestEntityManager(
        $conn = null,
        $conf = null,
        $eventManager = null,
        $withSharedMetadata = true,
        MetadataCollection $metadatas = null
    ) {
        $metadataCache = $withSharedMetadata
            ? self::getSharedMetadataCacheImpl()
            : new ArrayCache();

        $config = new Configuration();

        $config->setMetadataCacheImpl($metadataCache);
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([]));
        $config->setQueryCacheImpl(self::getSharedQueryCacheImpl());
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        $config->setMetadataDriverImpl(
            $config->newDefaultAnnotationDriver([
                realpath(__DIR__ . '/Models/Cache'),
                realpath(__DIR__ . '/Models/DDC1476'),
                realpath(__DIR__ . '/Models/DDC3231'),
                realpath(__DIR__ . '/Models/DDC3711'),
                realpath(__DIR__ . '/Models/DDC869'),
                realpath(__DIR__ . '/Models/FriendObject'),
                realpath(__DIR__ . '/Models/Issue5989'),
                realpath(__DIR__ . '/Models/NonPublicSchemaJoins'),
                realpath(__DIR__ . '/Models/Quote'),
                //realpath(__DIR__ . '/Models/ValueConversionType'),
                realpath(__DIR__ . '/Models/CMS'),
                realpath(__DIR__ . '/Models/DDC1590'),
                realpath(__DIR__ . '/Models/DDC3293'),
                realpath(__DIR__ . '/Models/DDC3899'),
                //realpath(__DIR__ . '/Models/DDC889'),
                realpath(__DIR__ . '/Models/Generic'),
                realpath(__DIR__ . '/Models/JoinedInheritanceType'),
                realpath(__DIR__ . '/Models/NullDefault'),
                realpath(__DIR__ . '/Models/Reflection'),
                realpath(__DIR__ . '/Models/ValueObjects'),
                realpath(__DIR__ . '/Models/Company'),
                realpath(__DIR__ . '/Models/DDC1872'),
                realpath(__DIR__ . '/Models/DDC3346'),
                //realpath(__DIR__ . '/Models/DDC4006'),
                realpath(__DIR__ . '/Models/DDC964'),
                realpath(__DIR__ . '/Models/GeoNames'),
                realpath(__DIR__ . '/Models/Legacy'),
                realpath(__DIR__ . '/Models/OneToOneInverseSideLoad'),
                realpath(__DIR__ . '/Models/Routing'),
                realpath(__DIR__ . '/Models/VersionedManyToOne'),
                realpath(__DIR__ . '/Models/CompositeKeyInheritance'),
                //realpath(__DIR__ . '/Models/DDC2372'),
                realpath(__DIR__ . '/Models/DDC3579'),
                //realpath(__DIR__ . '/Models/DDC5934'),
                realpath(__DIR__ . '/Models/DirectoryTree'),
                realpath(__DIR__ . '/Models/Global'),
                realpath(__DIR__ . '/Models/ManyToManyPersister'),
                realpath(__DIR__ . '/Models/OneToOneSingleTableInheritance'),
                realpath(__DIR__ . '/Models/StockExchange'),
                realpath(__DIR__ . '/Models/VersionedOneToOne'),
                //realpath(__DIR__ . '/Models/CustomType'),
                realpath(__DIR__ . '/Models/DDC2504'),
                realpath(__DIR__ . '/Models/DDC3597'),
                //realpath(__DIR__ . '/Models/DDC6412'),
                realpath(__DIR__ . '/Models/ECommerce'),
                realpath(__DIR__ . '/Models/Hydration'),
                //realpath(__DIR__ . '/Models/MixedToOneIdentity'),
                realpath(__DIR__ . '/Models/Pagination'),
                realpath(__DIR__ . '/Models/Taxi'),
                realpath(__DIR__ . '/Models/DDC117'),
                realpath(__DIR__ . '/Models/DDC2825'),
                realpath(__DIR__ . '/Models/DDC3699'),
                realpath(__DIR__ . '/Models/DDC753'),
                realpath(__DIR__ . '/Models/Forum'),
                realpath(__DIR__ . '/Models/IdentityIsAssociation'),
                realpath(__DIR__ . '/Models/Navigation'),
                realpath(__DIR__ . '/Models/ProxySpecifics'),
                realpath(__DIR__ . '/Models/Tweet'),
            ])
        );

        if ($this->isSecondLevelCacheEnabled) {
            $cacheConfig = new CacheConfiguration();
            $cache       = $this->getSharedSecondLevelCacheDriverImpl();
            $factory     = new DefaultCacheFactory($cacheConfig->getRegionsConfiguration(), $cache);

            $this->secondLevelCacheFactory = $factory;

            $cacheConfig->setCacheFactory($factory);
            $config->setSecondLevelCacheEnabled(true);
            $config->setSecondLevelCacheConfiguration($cacheConfig);
        }

        if ($conn === null) {
            $conn = [
                'driverClass'  => Mocks\DriverMock::class,
                'wrapperClass' => Mocks\ConnectionMock::class,
                'user'         => 'john',
                'password'     => 'wayne',
            ];
        }

        if (is_array($conn)) {
            $conn = DriverManager::getConnection($conn, $config, $eventManager);
        }

        if ($eventManager === null) {
            $eventManager = new EventManager();
        }

        if (! ($metadatas instanceof MetadataCollection)){
            $metadataFactory = new ClassMetadataFactory($config, $conn, $eventManager);
            $metadatas = MetadataCollection::fromClassMetadatas(...$metadataFactory->getAllMetadata());
        }

        return Mocks\EntityManagerMock::create($conn, $config, $eventManager, $metadatas)->getWrappedEntityManager();
    }

    protected function enableSecondLevelCache($log = true)
    {
        $this->isSecondLevelCacheEnabled    = true;
        $this->isSecondLevelCacheLogEnabled = $log;
    }

    /**
     * @return Cache
     */
    private static function getSharedMetadataCacheImpl()
    {
        if (self::$metadataCacheImpl === null) {
            self::$metadataCacheImpl = new ArrayCache();
        }

        return self::$metadataCacheImpl;
    }

    /**
     * @return Cache
     */
    private static function getSharedQueryCacheImpl()
    {
        if (self::$queryCacheImpl === null) {
            self::$queryCacheImpl = new ArrayCache();
        }

        return self::$queryCacheImpl;
    }

    /**
     * @return Cache
     */
    protected function getSharedSecondLevelCacheDriverImpl()
    {
        if ($this->secondLevelCacheDriverImpl === null) {
            $this->secondLevelCacheDriverImpl = new ArrayCache();
        }

        return $this->secondLevelCacheDriverImpl;
    }
}
