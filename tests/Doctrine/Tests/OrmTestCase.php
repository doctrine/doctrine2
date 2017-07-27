<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\Common\Annotations;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Tests\Mocks;

/**
 * Base testcase class for all ORM testcases.
 */
abstract class OrmTestCase extends DoctrineTestCase
{
    /**
     * The metadata cache that is shared between all ORM tests (except functional tests).
     *
     * @var \Doctrine\Common\Cache\Cache|null
     */
    private static $metadataCacheImpl = null;

    /**
     * The query cache that is shared between all ORM tests (except functional tests).
     *
     * @var \Doctrine\Common\Cache\Cache|null
     */
    private static $queryCacheImpl = null;

    /**
     * @var bool
     */
    protected $isSecondLevelCacheEnabled = false;

    /**
     * @var bool
     */
    protected $isSecondLevelCacheLogEnabled = false;

    /**
     * @var \Doctrine\ORM\Cache\CacheFactory
     */
    protected $secondLevelCacheFactory;

    /**
     * @var \Doctrine\ORM\Cache\Logging\StatisticsCacheLogger
     */
    protected $secondLevelCacheLogger;

    /**
     * @var \Doctrine\Common\Cache\Cache|null
     */
    protected $secondLevelCacheDriverImpl = null;

    /**
     * @param array $paths
     *
     * @return \Doctrine\ORM\Mapping\Driver\AnnotationDriver
     */
    protected function createAnnotationDriver($paths = [])
    {
        $reader = new Annotations\CachedReader(new Annotations\AnnotationReader(), new ArrayCache());

        Annotations\AnnotationRegistry::registerFile(__DIR__ . "/../../../lib/Doctrine/ORM/Annotation/DoctrineAnnotations.php");

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
     * @param \Doctrine\DBAL\Connection|array    $conn
     * @param mixed                              $conf
     * @param \Doctrine\Common\EventManager|null $eventManager
     * @param bool                               $withSharedMetadata
     *
     * @return \Doctrine\ORM\EntityManager
     */
    protected function getTestEntityManager($conn = null, $conf = null, $eventManager = null, $withSharedMetadata = true)
    {
        $metadataCache = $withSharedMetadata
            ? self::getSharedMetadataCacheImpl()
            : new ArrayCache();

        $config = new Configuration();

        $config->setMetadataCacheImpl($metadataCache);
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([]));
        $config->setQueryCacheImpl(self::getSharedQueryCacheImpl());
        $config->setProxyDir(__DIR__ . '/Proxies');
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $config->setMetadataDriverImpl(
            $config->newDefaultAnnotationDriver([
                realpath(__DIR__ . '/Models/Cache')
            ])
        );

        if ($this->isSecondLevelCacheEnabled) {

            $cacheConfig    = new CacheConfiguration();
            $cache          = $this->getSharedSecondLevelCacheDriverImpl();
            $factory        = new DefaultCacheFactory($cacheConfig->getRegionsConfiguration(), $cache);

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
                'password'     => 'wayne'
            ];
        }

        if (is_array($conn)) {
            $conn = DriverManager::getConnection($conn, $config, $eventManager);
        }

        return Mocks\EntityManagerMock::create($conn, $config, $eventManager);
    }

    protected function enableSecondLevelCache($log = true)
    {
        $this->isSecondLevelCacheEnabled    = true;
        $this->isSecondLevelCacheLogEnabled = $log;
    }

    /**
     * @return \Doctrine\Common\Cache\Cache
     */
    private static function getSharedMetadataCacheImpl()
    {
        if (self::$metadataCacheImpl === null) {
            self::$metadataCacheImpl = new ArrayCache();
        }

        return self::$metadataCacheImpl;
    }

    /**
     * @return \Doctrine\Common\Cache\Cache
     */
    private static function getSharedQueryCacheImpl()
    {
        if (self::$queryCacheImpl === null) {
            self::$queryCacheImpl = new ArrayCache();
        }

        return self::$queryCacheImpl;
    }

    /**
     * @return \Doctrine\Common\Cache\Cache
     */
    protected function getSharedSecondLevelCacheDriverImpl()
    {
        if ($this->secondLevelCacheDriverImpl === null) {
            $this->secondLevelCacheDriverImpl = new ArrayCache();
        }

        return $this->secondLevelCacheDriverImpl;
    }
}
