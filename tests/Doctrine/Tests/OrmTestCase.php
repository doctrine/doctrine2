<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\Common\Annotations;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Logging\StatisticsCacheLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function class_exists;
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
     * @var CacheItemPoolInterface|null
     */
    private static $_metadataCache = null;

    /**
     * The query cache that is shared between all ORM tests (except functional tests).
     *
     * @var Cache|null
     */
    private static $_queryCacheImpl = null;

    /** @var bool */
    protected $isSecondLevelCacheEnabled = false;

    /** @var bool */
    protected $isSecondLevelCacheLogEnabled = false;

    /** @var CacheFactory */
    protected $secondLevelCacheFactory;

    /** @var StatisticsCacheLogger */
    protected $secondLevelCacheLogger;

    /** @var Cache|null */
    protected $secondLevelCacheDriverImpl = null;

    /**
     * @param mixed $alias
     */
    protected function createAnnotationDriver(array $paths = [], $alias = null): AnnotationDriver
    {
        // Register the ORM Annotations in the AnnotationRegistry
        $reader = new Annotations\AnnotationReader();

        if (class_exists(Annotations\PsrCachedReader::class)) {
            $reader = new Annotations\PsrCachedReader($reader, new ArrayAdapter());
        } else {
            $reader = new Annotations\CachedReader($reader, DoctrineProvider::wrap(new ArrayAdapter()));
        }

        Annotations\AnnotationRegistry::registerFile(__DIR__ . '/../../../lib/Doctrine/ORM/Mapping/Driver/DoctrineAnnotations.php');

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
     * @param Connection|array $conn
     * @param mixed            $conf
     */
    protected function getTestEntityManager(
        $conn = null,
        $conf = null,
        ?EventManager $eventManager = null,
        bool $withSharedMetadata = true
    ): EntityManagerMock {
        $metadataCache = $withSharedMetadata
            ? self::getSharedMetadataCacheImpl()
            : new ArrayAdapter();

        $config = new Configuration();

        $config->setMetadataCache($metadataCache);
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([], true));
        $config->setQueryCacheImpl(self::getSharedQueryCacheImpl());
        $config->setProxyDir(__DIR__ . '/Proxies');
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver(
            [
                realpath(__DIR__ . '/Models/Cache'),
            ],
            true
        ));

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

        return EntityManagerMock::create($conn, $config, $eventManager);
    }

    protected function enableSecondLevelCache($log = true): void
    {
        $this->isSecondLevelCacheEnabled    = true;
        $this->isSecondLevelCacheLogEnabled = $log;
    }

    private static function getSharedMetadataCacheImpl(): ?CacheItemPoolInterface
    {
        if (self::$_metadataCache === null) {
            self::$_metadataCache = new ArrayAdapter();
        }

        return self::$_metadataCache;
    }

    private static function getSharedQueryCacheImpl(): Cache
    {
        if (self::$_queryCacheImpl === null) {
            self::$_queryCacheImpl = DoctrineProvider::wrap(new ArrayAdapter());
        }

        return self::$_queryCacheImpl;
    }

    protected function getSharedSecondLevelCacheDriverImpl(): Cache
    {
        if ($this->secondLevelCacheDriverImpl === null) {
            $this->secondLevelCacheDriverImpl = DoctrineProvider::wrap(new ArrayAdapter());
        }

        return $this->secondLevelCacheDriverImpl;
    }
}
