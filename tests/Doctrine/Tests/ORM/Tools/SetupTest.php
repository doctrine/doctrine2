<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Tests\OrmTestCase;

class SetupTest extends OrmTestCase
{
    private $originalAutoloaderCount;
    private $originalIncludePath;

    public function setUp()
    {
        $this->originalAutoloaderCount = count(spl_autoload_functions());
        $this->originalIncludePath = get_include_path();
    }

    public function tearDown()
    {
        if ( ! $this->originalIncludePath) {
            return;
        }

        set_include_path($this->originalIncludePath);

        foreach (spl_autoload_functions() as $i => $loader) {
            if ($i > $this->originalAutoloaderCount + 1) {
                spl_autoload_unregister($loader);
            }
        }
    }

    public function testDirectoryAutoload()
    {
        Setup::registerAutoloadDirectory(__DIR__ . "/../../../../../vendor/doctrine/common/lib");

        self::assertCount($this->originalAutoloaderCount + 2, spl_autoload_functions());
    }

    public function testAnnotationConfiguration()
    {
        $config = Setup::createAnnotationMetadataConfiguration([], true);

        self::assertInstanceOf(Configuration::class, $config);
        self::assertEquals(sys_get_temp_dir(), $config->getProxyManagerConfiguration()->getProxiesTargetDir());
        self::assertEquals('DoctrineProxies', $config->getProxyManagerConfiguration()->getProxiesNamespace());
        self::assertInstanceOf(AnnotationDriver::class, $config->getMetadataDriverImpl());
    }

    public function testXMLConfiguration()
    {
        $config = Setup::createXMLMetadataConfiguration([], true);

        self::assertInstanceOf(Configuration::class, $config);
        self::assertInstanceOf(XmlDriver::class, $config->getMetadataDriverImpl());
    }

    /**
     * @group 5904
     */
    public function testCacheNamespaceShouldBeGeneratedWhenCacheIsNotGiven() : void
    {
        $config = Setup::createConfiguration(false, '/foo');
        $cache  = $config->getMetadataCacheImpl();

        self::assertSame('dc2_1effb2475fcfba4f9e8b8a1dbc8f3caf_', $cache->getNamespace());
    }

    /**
     * @group 5904
     */
    public function testCacheNamespaceShouldBeGeneratedWhenCacheIsGivenButHasNoNamespace() : void
    {
        $config = Setup::createConfiguration(false, '/foo', new ArrayCache());
        $cache  = $config->getMetadataCacheImpl();

        self::assertSame('dc2_1effb2475fcfba4f9e8b8a1dbc8f3caf_', $cache->getNamespace());
    }

    /**
     * @group 5904
     */
    public function testConfiguredCacheNamespaceShouldBeUsedAsPrefixOfGeneratedNamespace() : void
    {
        $originalCache = new ArrayCache();
        $originalCache->setNamespace('foo');

        $config = Setup::createConfiguration(false, '/foo', $originalCache);
        $cache  = $config->getMetadataCacheImpl();

        self::assertSame($originalCache, $cache);
        self::assertSame('foo:dc2_1effb2475fcfba4f9e8b8a1dbc8f3caf_', $cache->getNamespace());
    }

    /**
     * @group DDC-1350
     */
    public function testConfigureProxyDir()
    {
        $path   = $this->makeTemporaryDirectory();
        $config = Setup::createAnnotationMetadataConfiguration([], true, $path);
        self::assertSame($path, $config->getProxyManagerConfiguration()->getProxiesTargetDir());
    }

    /**
     * @group DDC-1350
     */
    public function testConfigureCache()
    {
        $cache = new ArrayCache();
        $config = Setup::createAnnotationMetadataConfiguration([], true, null, $cache);

        self::assertSame($cache, $config->getResultCacheImpl());
        self::assertSame($cache, $config->getMetadataCacheImpl());
        self::assertSame($cache, $config->getQueryCacheImpl());
    }

    /**
     * @group DDC-3190
     */
    public function testConfigureCacheCustomInstance()
    {
        $cache  = $this->createMock(Cache::class);
        $config = Setup::createConfiguration([], null, $cache);

        self::assertSame($cache, $config->getResultCacheImpl());
        self::assertSame($cache, $config->getMetadataCacheImpl());
        self::assertSame($cache, $config->getQueryCacheImpl());
    }

    private function makeTemporaryDirectory() : string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'foo');

        \unlink($path);
        \mkdir($path);

        return $path;
    }
}
