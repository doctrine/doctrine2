<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\Persistence\Mapping\Driver\ClassLocator;
use Psr\Cache\CacheItemPoolInterface;
use Redis;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

use function apcu_enabled;
use function class_exists;
use function extension_loaded;
use function md5;

final class ORMSetup
{
    /**
     * Creates a configuration with an attribute metadata driver.
     *
     * @param string[]|ClassLocator $paths
     */
    public static function createAttributeMetadataConfig(
        array|ClassLocator $paths,
        bool $isDevMode = false,
        string|null $cacheNamespaceSeed = null,
        CacheItemPoolInterface|null $cache = null,
    ): Configuration {
        $config = self::createConfig($isDevMode, $cacheNamespaceSeed, $cache);
        $config->setMetadataDriverImpl(new AttributeDriver($paths));

        return $config;
    }

    /**
     * Creates a configuration with an XML metadata driver.
     *
     * @param string[] $paths
     */
    public static function createXMLMetadataConfig(
        array $paths,
        bool $isDevMode = false,
        string|null $cacheNamespaceSeed = null,
        CacheItemPoolInterface|null $cache = null,
        bool $isXsdValidationEnabled = true,
    ): Configuration {
        $config = self::createConfig($isDevMode, $cacheNamespaceSeed, $cache);
        $config->setMetadataDriverImpl(new XmlDriver(
            $paths,
            XmlDriver::DEFAULT_FILE_EXTENSION,
            $isXsdValidationEnabled,
        ));

        return $config;
    }

    public static function createConfig(
        bool $isDevMode = false,
        string|null $cacheNamespaceSeed = null,
        CacheItemPoolInterface|null $cache = null,
    ): Configuration {
        $cache  = self::createCacheInstance($isDevMode, $cacheNamespaceSeed, $cache);
        $config = new Configuration();
        $config->setMetadataCache($cache);
        $config->setQueryCache($cache);
        $config->setResultCache($cache);

        return $config;
    }

    private static function createCacheInstance(
        bool $isDevMode,
        string|null $cacheNamespaceSeed,
        CacheItemPoolInterface|null $cache,
    ): CacheItemPoolInterface {
        if ($cache !== null) {
            return $cache;
        }

        if (! class_exists(ArrayAdapter::class)) {
            throw new RuntimeException(
                'The Doctrine setup tool cannot configure caches without symfony/cache.'
                . ' Please add symfony/cache as explicit dependency or pass your own cache implementation.',
            );
        }

        if ($isDevMode) {
            return new ArrayAdapter();
        }

        $namespace = 'dc2_' . md5($cacheNamespaceSeed ?? 'default');

        if (extension_loaded('apcu') && apcu_enabled()) {
            return new ApcuAdapter($namespace);
        }

        if (MemcachedAdapter::isSupported()) {
            return new MemcachedAdapter(MemcachedAdapter::createConnection('memcached://127.0.0.1'), $namespace);
        }

        if (extension_loaded('redis')) {
            $redis = new Redis();
            $redis->connect('127.0.0.1');

            return new RedisAdapter($redis, $namespace);
        }

        return new ArrayAdapter();
    }

    private function __construct()
    {
    }
}
