<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\Exception\MetadataCacheNotConfigured;
use Doctrine\ORM\Cache\Exception\MetadataCacheUsesNonPersistentCache;
use Doctrine\ORM\Cache\Exception\QueryCacheNotConfigured;
use Doctrine\ORM\Cache\Exception\QueryCacheUsesNonPersistentCache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\ProxyClassesAlwaysRegenerating;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;
use Doctrine\ORM\Mapping\Driver\MappingDriver;
use Doctrine\ORM\Mapping\EntityListenerResolver;
use Doctrine\ORM\Mapping\Factory\NamingStrategy;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Proxy\Factory\StaticProxyFactory;
use Doctrine\Tests\DoctrineTestCase;
use Doctrine\Tests\Models\DDC753\DDC753CustomRepository;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use ReflectionClass;
use function mkdir;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;
use function unlink;

/**
 * Tests for the Configuration object
 */
class ConfigurationTest extends DoctrineTestCase
{
    /** @var Configuration */
    private $configuration;

    protected function setUp() : void
    {
        parent::setUp();
        $this->configuration = new Configuration();
    }

    public function testSetGetMetadataDriverImpl() : void
    {
        self::assertNull($this->configuration->getMetadataDriverImpl()); // defaults

        $metadataDriver = $this->createMock(MappingDriver::class);
        $this->configuration->setMetadataDriverImpl($metadataDriver);
        self::assertSame($metadataDriver, $this->configuration->getMetadataDriverImpl());
    }

    public function testNewDefaultAnnotationDriver() : void
    {
        $paths           = [__DIR__];
        $reflectionClass = new ReflectionClass(ConfigurationTestAnnotationReaderChecker::class);

        $annotationDriver = $this->configuration->newDefaultAnnotationDriver($paths);
        $reader           = $annotationDriver->getReader();
        $annotation       = $reader->getMethodAnnotation(
            $reflectionClass->getMethod('annotatedMethod'),
            ORM\PrePersist::class
        );

        self::assertInstanceOf(ORM\PrePersist::class, $annotation);
    }

    public function testSetGetQueryCacheImpl() : void
    {
        self::assertNull($this->configuration->getQueryCacheImpl()); // defaults
        $queryCacheImpl = $this->createMock(Cache::class);
        $this->configuration->setQueryCacheImpl($queryCacheImpl);
        self::assertSame($queryCacheImpl, $this->configuration->getQueryCacheImpl());
    }

    public function testSetGetHydrationCacheImpl() : void
    {
        self::assertNull($this->configuration->getHydrationCacheImpl()); // defaults
        $queryCacheImpl = $this->createMock(Cache::class);
        $this->configuration->setHydrationCacheImpl($queryCacheImpl);
        self::assertSame($queryCacheImpl, $this->configuration->getHydrationCacheImpl());
    }

    public function testSetGetMetadataCacheImpl() : void
    {
        self::assertNull($this->configuration->getMetadataCacheImpl()); // defaults
        $queryCacheImpl = $this->createMock(Cache::class);
        $this->configuration->setMetadataCacheImpl($queryCacheImpl);
        self::assertSame($queryCacheImpl, $this->configuration->getMetadataCacheImpl());
    }

    /**
     * Configures $this->configuration to use production settings.
     *
     * @param string $skipCache Do not configure a cache of this type, either "query" or "metadata".
     */
    protected function setProductionSettings($skipCache = false)
    {
        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);

        $cache = $this->createMock(Cache::class);

        if ($skipCache !== 'query') {
            $this->configuration->setQueryCacheImpl($cache);
        }

        if ($skipCache !== 'metadata') {
            $this->configuration->setMetadataCacheImpl($cache);
        }
    }

    public function testEnsureProductionSettings() : void
    {
        $this->setProductionSettings();
        $this->configuration->ensureProductionSettings();

        self::addToAssertionCount(1);
    }

    public function testEnsureProductionSettingsQueryCache() : void
    {
        $this->setProductionSettings('query');

        $this->expectException(QueryCacheNotConfigured::class);
        $this->expectExceptionMessage('Query Cache is not configured.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsMetadataCache() : void
    {
        $this->setProductionSettings('metadata');

        $this->expectException(MetadataCacheNotConfigured::class);
        $this->expectExceptionMessage('Metadata Cache is not configured.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsQueryArrayCache() : void
    {
        $this->setProductionSettings();
        $this->configuration->setQueryCacheImpl(new ArrayCache());

        $this->expectException(QueryCacheUsesNonPersistentCache::class);
        $this->expectExceptionMessage('Query Cache uses a non-persistent cache driver, Doctrine\Common\Cache\ArrayCache.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsMetadataArrayCache() : void
    {
        $this->setProductionSettings();
        $this->configuration->setMetadataCacheImpl(new ArrayCache());

        $this->expectException(MetadataCacheUsesNonPersistentCache::class);
        $this->expectExceptionMessage('Metadata Cache uses a non-persistent cache driver, Doctrine\Common\Cache\ArrayCache.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsAutoGenerateProxyClassesEval() : void
    {
        $this->setProductionSettings();
        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_EVAL);

        $this->expectException(ProxyClassesAlwaysRegenerating::class);
        $this->expectExceptionMessage('Proxy Classes are always regenerating.');

        $this->configuration->ensureProductionSettings();
    }

    public function testAddGetCustomStringFunction() : void
    {
        $this->configuration->addCustomStringFunction('FunctionName', __CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getCustomStringFunction('FunctionName'));
        self::assertNull($this->configuration->getCustomStringFunction('NonExistingFunction'));

        $this->configuration->setCustomStringFunctions(['OtherFunctionName' => __CLASS__]);

        self::assertSame(__CLASS__, $this->configuration->getCustomStringFunction('OtherFunctionName'));
    }

    public function testAddGetCustomNumericFunction() : void
    {
        $this->configuration->addCustomNumericFunction('FunctionName', __CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getCustomNumericFunction('FunctionName'));
        self::assertNull($this->configuration->getCustomNumericFunction('NonExistingFunction'));

        $this->configuration->setCustomNumericFunctions(['OtherFunctionName' => __CLASS__]);

        self::assertSame(__CLASS__, $this->configuration->getCustomNumericFunction('OtherFunctionName'));
    }

    public function testAddGetCustomDatetimeFunction() : void
    {
        $this->configuration->addCustomDatetimeFunction('FunctionName', __CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getCustomDatetimeFunction('FunctionName'));
        self::assertNull($this->configuration->getCustomDatetimeFunction('NonExistingFunction'));

        $this->configuration->setCustomDatetimeFunctions(['OtherFunctionName' => __CLASS__]);

        self::assertSame(__CLASS__, $this->configuration->getCustomDatetimeFunction('OtherFunctionName'));
    }

    public function testAddGetCustomHydrationMode() : void
    {
        self::assertNull($this->configuration->getCustomHydrationMode('NonExisting'));

        $this->configuration->addCustomHydrationMode('HydrationModeName', __CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getCustomHydrationMode('HydrationModeName'));
    }

    public function testSetCustomHydrationModes() : void
    {
        $this->configuration->addCustomHydrationMode('HydrationModeName', __CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getCustomHydrationMode('HydrationModeName'));

        $this->configuration->setCustomHydrationModes(
            ['AnotherHydrationModeName' => __CLASS__]
        );

        self::assertNull($this->configuration->getCustomHydrationMode('HydrationModeName'));
        self::assertSame(__CLASS__, $this->configuration->getCustomHydrationMode('AnotherHydrationModeName'));
    }

    public function testSetGetClassMetadataFactoryName() : void
    {
        self::assertSame(ClassMetadataFactory::class, $this->configuration->getClassMetadataFactoryName());

        $this->configuration->setClassMetadataFactoryName(__CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getClassMetadataFactoryName());
    }

    public function testAddGetFilters() : void
    {
        self::assertNull($this->configuration->getFilterClassName('NonExistingFilter'));

        $this->configuration->addFilter('FilterName', __CLASS__);

        self::assertSame(__CLASS__, $this->configuration->getFilterClassName('FilterName'));
    }

    public function setDefaultRepositoryClassName()
    {
        self::assertSame(EntityRepository::class, $this->configuration->getDefaultRepositoryClassName());

        $this->configuration->setDefaultRepositoryClassName(DDC753CustomRepository::class);

        self::assertSame(DDC753CustomRepository::class, $this->configuration->getDefaultRepositoryClassName());

        $this->expectException(ORMException::class);
        $this->configuration->setDefaultRepositoryClassName(__CLASS__);
    }

    public function testSetGetNamingStrategy() : void
    {
        self::assertInstanceOf(NamingStrategy::class, $this->configuration->getNamingStrategy());

        $namingStrategy = $this->createMock(NamingStrategy::class);

        $this->configuration->setNamingStrategy($namingStrategy);

        self::assertSame($namingStrategy, $this->configuration->getNamingStrategy());
    }

    /**
     * @group DDC-1955
     */
    public function testSetGetEntityListenerResolver() : void
    {
        self::assertInstanceOf(EntityListenerResolver::class, $this->configuration->getEntityListenerResolver());
        self::assertInstanceOf(DefaultEntityListenerResolver::class, $this->configuration->getEntityListenerResolver());

        $resolver = $this->createMock(EntityListenerResolver::class);

        $this->configuration->setEntityListenerResolver($resolver);

        self::assertSame($resolver, $this->configuration->getEntityListenerResolver());
    }

    /**
     * @group DDC-2183
     */
    public function testSetGetSecondLevelCacheConfig() : void
    {
        $mockClass = $this->createMock(CacheConfiguration::class);

        self::assertNull($this->configuration->getSecondLevelCacheConfiguration());
        $this->configuration->setSecondLevelCacheConfiguration($mockClass);
        self::assertEquals($mockClass, $this->configuration->getSecondLevelCacheConfiguration());
    }

    public function testGetProxyManagerConfiguration() : void
    {
        self::assertInstanceOf(
            \ProxyManager\Configuration::class,
            $this->configuration->getProxyManagerConfiguration()
        );
    }

    public function testProxyManagerConfigurationContainsGivenProxyTargetDir() : void
    {
        $proxyPath = $this->makeTemporaryValidDirectory();

        $this->configuration->setProxyDir($proxyPath);
        self::assertSame($proxyPath, $this->configuration->getProxyManagerConfiguration()->getProxiesTargetDir());
    }

    public function testProxyManagerConfigurationContainsGivenProxyNamespace() : void
    {
        $namespace = str_replace('.', '', uniqid('Namespace', true));

        $this->configuration->setProxyNamespace($namespace);
        self::assertSame($namespace, $this->configuration->getProxyManagerConfiguration()->getProxiesNamespace());
    }

    /**
     * @dataProvider expectedGeneratorStrategies
     *
     * @param int|bool $proxyAutoGenerateFlag
     */
    public function testProxyManagerConfigurationWillBeUpdatedWithCorrectGeneratorStrategies(
        $proxyAutoGenerateFlag,
        string $expectedGeneratorStrategy
    ) : void {
        $this->configuration->setAutoGenerateProxyClasses($proxyAutoGenerateFlag);

        self::assertInstanceOf(
            $expectedGeneratorStrategy,
            $this->configuration->getProxyManagerConfiguration()->getGeneratorStrategy()
        );
    }

    public function expectedGeneratorStrategies() : array
    {
        return [
            [
                StaticProxyFactory::AUTOGENERATE_NEVER,
                EvaluatingGeneratorStrategy::class,
            ],
            [
                StaticProxyFactory::AUTOGENERATE_EVAL,
                EvaluatingGeneratorStrategy::class,
            ],
            [
                false,
                EvaluatingGeneratorStrategy::class,
            ],
            [
                StaticProxyFactory::AUTOGENERATE_ALWAYS,
                FileWriterGeneratorStrategy::class,
            ],
            [
                StaticProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS,
                FileWriterGeneratorStrategy::class,
            ],
            [
                true,
                FileWriterGeneratorStrategy::class,
            ],
        ];
    }

    private function makeTemporaryValidDirectory() : string
    {
        $path = tempnam(sys_get_temp_dir(), 'ProxyConfigurationTest');

        unlink($path);
        mkdir($path);

        return $path;
    }

    public function testWillProduceGhostObjectFactory() : void
    {
        $factory1 = $this->configuration->buildGhostObjectFactory();
        $factory2 = $this->configuration->buildGhostObjectFactory();

        $this->configuration->setProxyDir($this->makeTemporaryValidDirectory());

        $factory3 = $this->configuration->buildGhostObjectFactory();

        self::assertNotSame($factory1, $factory2);
        self::assertEquals($factory1, $factory2);
        self::assertNotEquals($factory2, $factory3);
    }
}

class ConfigurationTestAnnotationReaderChecker
{
    /** @ORM\PrePersist */
    public function annotatedMethod()
    {
    }
}
