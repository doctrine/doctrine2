<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\Exception\MetadataCacheNotConfigured;
use Doctrine\ORM\Cache\Exception\MetadataCacheUsesNonPersistentCache;
use Doctrine\ORM\Cache\Exception\QueryCacheNotConfigured;
use Doctrine\ORM\Cache\Exception\QueryCacheUsesNonPersistentCache;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Exception\ProxyClassesAlwaysRegenerating;
use Doctrine\ORM\Mapping as AnnotationNamespace;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;
use Doctrine\ORM\Mapping\EntityListenerResolver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Proxy\Factory\StaticProxyFactory;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Tests\DoctrineTestCase;
use Doctrine\Tests\Models\DDC753\DDC753CustomRepository;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function class_exists;

/**
 * Tests for the Configuration object
 */
class ConfigurationTest extends DoctrineTestCase
{
    /** @var Configuration */
    private $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = new Configuration();
    }

    public function testSetGetProxyDir(): void
    {
        $this->assertSame(null, $this->configuration->getProxyDir()); // defaults

        $this->configuration->setProxyDir(__DIR__);
        $this->assertSame(__DIR__, $this->configuration->getProxyDir());
    }

    public function testSetGetAutoGenerateProxyClasses(): void
    {
        $this->assertSame(AbstractProxyFactory::AUTOGENERATE_ALWAYS, $this->configuration->getAutoGenerateProxyClasses()); // defaults

        $this->configuration->setAutoGenerateProxyClasses(false);
        $this->assertSame(AbstractProxyFactory::AUTOGENERATE_NEVER, $this->configuration->getAutoGenerateProxyClasses());

        $this->configuration->setAutoGenerateProxyClasses(true);
        $this->assertSame(AbstractProxyFactory::AUTOGENERATE_ALWAYS, $this->configuration->getAutoGenerateProxyClasses());

        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);
        $this->assertSame(AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS, $this->configuration->getAutoGenerateProxyClasses());
    }

    public function testSetGetProxyNamespace(): void
    {
        $this->assertSame(null, $this->configuration->getProxyNamespace()); // defaults

        $this->configuration->setProxyNamespace(__NAMESPACE__);
        $this->assertSame(__NAMESPACE__, $this->configuration->getProxyNamespace());
    }

    public function testSetGetMetadataDriverImpl(): void
    {
        $this->assertSame(null, $this->configuration->getMetadataDriverImpl()); // defaults

        $metadataDriver = $this->createMock(MappingDriver::class);
        $this->configuration->setMetadataDriverImpl($metadataDriver);
        $this->assertSame($metadataDriver, $this->configuration->getMetadataDriverImpl());
    }

    public function testNewDefaultAnnotationDriver(): void
    {
        $paths           = [__DIR__];
        $reflectionClass = new ReflectionClass(ConfigurationTestAnnotationReaderChecker::class);

        $annotationDriver = $this->configuration->newDefaultAnnotationDriver($paths, false);
        $reader           = $annotationDriver->getReader();
        $annotation       = $reader->getMethodAnnotation(
            $reflectionClass->getMethod('namespacedAnnotationMethod'),
            AnnotationNamespace\PrePersist::class
        );
        $this->assertInstanceOf(AnnotationNamespace\PrePersist::class, $annotation);

        $annotationDriver = $this->configuration->newDefaultAnnotationDriver($paths);
        $reader           = $annotationDriver->getReader();
        $annotation       = $reader->getMethodAnnotation(
            $reflectionClass->getMethod('simpleAnnotationMethod'),
            AnnotationNamespace\PrePersist::class
        );
        $this->assertInstanceOf(AnnotationNamespace\PrePersist::class, $annotation);
    }

    public function testSetGetEntityNamespace(): void
    {
        $this->configuration->addEntityNamespace('TestNamespace', __NAMESPACE__);
        $this->assertSame(__NAMESPACE__, $this->configuration->getEntityNamespace('TestNamespace'));
        $namespaces = ['OtherNamespace' => __NAMESPACE__];
        $this->configuration->setEntityNamespaces($namespaces);
        $this->assertSame($namespaces, $this->configuration->getEntityNamespaces());
        $this->expectException(ORMException::class);
        $this->configuration->getEntityNamespace('NonExistingNamespace');
    }

    public function testSetGetQueryCacheImpl(): void
    {
        $this->assertSame(null, $this->configuration->getQueryCacheImpl()); // defaults
        $queryCacheImpl = $this->createMock(Cache::class);
        $this->configuration->setQueryCacheImpl($queryCacheImpl);
        $this->assertSame($queryCacheImpl, $this->configuration->getQueryCacheImpl());
    }

    public function testSetGetHydrationCacheImpl(): void
    {
        $this->assertSame(null, $this->configuration->getHydrationCacheImpl()); // defaults
        $queryCacheImpl = $this->createMock(Cache::class);
        $this->configuration->setHydrationCacheImpl($queryCacheImpl);
        $this->assertSame($queryCacheImpl, $this->configuration->getHydrationCacheImpl());
    }

    public function testSetGetMetadataCacheImpl(): void
    {
        $this->assertSame(null, $this->configuration->getMetadataCacheImpl()); // defaults
        $queryCacheImpl = $this->createMock(Cache::class);
        $this->configuration->setMetadataCacheImpl($queryCacheImpl);
        $this->assertSame($queryCacheImpl, $this->configuration->getMetadataCacheImpl());
        $this->assertNotNull($this->configuration->getMetadataCache());
    }

    public function testSetGetMetadataCache(): void
    {
        $this->assertNull($this->configuration->getMetadataCache());
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $this->configuration->setMetadataCache($cache);
        $this->assertSame($cache, $this->configuration->getMetadataCache());
        $this->assertNotNull($this->configuration->getMetadataCacheImpl());
    }

    public function testAddGetNamedQuery(): void
    {
        $dql = 'SELECT u FROM User u';
        $this->configuration->addNamedQuery('QueryName', $dql);
        $this->assertSame($dql, $this->configuration->getNamedQuery('QueryName'));
        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('a named query');
        $this->configuration->getNamedQuery('NonExistingQuery');
    }

    public function testAddGetNamedNativeQuery(): void
    {
        $sql = 'SELECT * FROM user';
        $rsm = $this->createMock(ResultSetMapping::class);
        $this->configuration->addNamedNativeQuery('QueryName', $sql, $rsm);
        $fetched = $this->configuration->getNamedNativeQuery('QueryName');
        $this->assertSame($sql, $fetched[0]);
        $this->assertSame($rsm, $fetched[1]);
        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('a named native query');
        $this->configuration->getNamedNativeQuery('NonExistingQuery');
    }

    /**
     * Configures $this->configuration to use production settings.
     *
     * @param string|null $skipCache Do not configure a cache of this type, either "query" or "metadata".
     */
    protected function setProductionSettings(?string $skipCache = null): void
    {
        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_NEVER);

        $cache = $this->createMock(Cache::class);

        if ($skipCache !== 'query') {
            $this->configuration->setQueryCacheImpl($cache);
        }

        if ($skipCache !== 'metadata') {
            $this->configuration->setMetadataCacheImpl($cache);
        }
    }

    public function testEnsureProductionSettings(): void
    {
        $this->setProductionSettings();
        $this->configuration->ensureProductionSettings();

        $this->addToAssertionCount(1);
    }

    public function testEnsureProductionSettingsWithNewMetadataCache(): void
    {
        $this->setProductionSettings('metadata');
        $this->configuration->setMetadataCache(new ArrayAdapter());

        $this->configuration->ensureProductionSettings();

        $this->addToAssertionCount(1);
    }

    public function testEnsureProductionSettingsMissingQueryCache(): void
    {
        $this->setProductionSettings('query');

        $this->expectException(QueryCacheNotConfigured::class);
        $this->expectExceptionMessage('Query Cache is not configured.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsMissingMetadataCache(): void
    {
        $this->setProductionSettings('metadata');

        $this->expectException(MetadataCacheNotConfigured::class);
        $this->expectExceptionMessage('Metadata Cache is not configured.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsQueryArrayCache(): void
    {
        if (! class_exists(ArrayCache::class)) {
            $this->markTestSkipped('Test only applies with doctrine/cache 1.x');
        }

        $this->setProductionSettings();
        $this->configuration->setQueryCacheImpl(new ArrayCache());

        $this->expectException(QueryCacheUsesNonPersistentCache::class);
        $this->expectExceptionMessage('Query Cache uses a non-persistent cache driver, Doctrine\Common\Cache\ArrayCache.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsLegacyMetadataArrayCache(): void
    {
        if (! class_exists(ArrayCache::class)) {
            $this->markTestSkipped('Test only applies with doctrine/cache 1.x');
        }

        $this->setProductionSettings();
        $this->configuration->setMetadataCacheImpl(new ArrayCache());

        $this->expectException(MetadataCacheUsesNonPersistentCache::class);
        $this->expectExceptionMessage('Metadata Cache uses a non-persistent cache driver, Doctrine\Common\Cache\ArrayCache.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsAutoGenerateProxyClassesAlways(): void
    {
        $this->setProductionSettings();
        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_ALWAYS);

        $this->expectException(ProxyClassesAlwaysRegenerating::class);
        $this->expectExceptionMessage('Proxy Classes are always regenerating.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsAutoGenerateProxyClassesFileNotExists(): void
    {
        $this->setProductionSettings();
        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);

        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('Proxy Classes are always regenerating.');

        $this->configuration->ensureProductionSettings();
    }

    public function testEnsureProductionSettingsAutoGenerateProxyClassesEval(): void
    {
        $this->setProductionSettings();
        $this->configuration->setAutoGenerateProxyClasses(AbstractProxyFactory::AUTOGENERATE_EVAL);

        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('Proxy Classes are always regenerating.');

        $this->configuration->ensureProductionSettings();
    }

    public function testAddGetCustomStringFunction(): void
    {
        $this->configuration->addCustomStringFunction('FunctionName', self::class);
        $this->assertSame(self::class, $this->configuration->getCustomStringFunction('FunctionName'));
        $this->assertSame(null, $this->configuration->getCustomStringFunction('NonExistingFunction'));
        $this->configuration->setCustomStringFunctions(['OtherFunctionName' => self::class]);
        $this->assertSame(self::class, $this->configuration->getCustomStringFunction('OtherFunctionName'));
    }

    public function testAddGetCustomNumericFunction(): void
    {
        $this->configuration->addCustomNumericFunction('FunctionName', self::class);
        $this->assertSame(self::class, $this->configuration->getCustomNumericFunction('FunctionName'));
        $this->assertSame(null, $this->configuration->getCustomNumericFunction('NonExistingFunction'));
        $this->configuration->setCustomNumericFunctions(['OtherFunctionName' => self::class]);
        $this->assertSame(self::class, $this->configuration->getCustomNumericFunction('OtherFunctionName'));
    }

    public function testAddGetCustomDatetimeFunction(): void
    {
        $this->configuration->addCustomDatetimeFunction('FunctionName', self::class);
        $this->assertSame(self::class, $this->configuration->getCustomDatetimeFunction('FunctionName'));
        $this->assertSame(null, $this->configuration->getCustomDatetimeFunction('NonExistingFunction'));
        $this->configuration->setCustomDatetimeFunctions(['OtherFunctionName' => self::class]);
        $this->assertSame(self::class, $this->configuration->getCustomDatetimeFunction('OtherFunctionName'));
    }

    public function testAddGetCustomHydrationMode(): void
    {
        $this->assertSame(null, $this->configuration->getCustomHydrationMode('NonExisting'));
        $this->configuration->addCustomHydrationMode('HydrationModeName', self::class);
        $this->assertSame(self::class, $this->configuration->getCustomHydrationMode('HydrationModeName'));
    }

    public function testSetCustomHydrationModes(): void
    {
        $this->configuration->addCustomHydrationMode('HydrationModeName', self::class);
        $this->assertSame(self::class, $this->configuration->getCustomHydrationMode('HydrationModeName'));

        $this->configuration->setCustomHydrationModes(
            ['AnotherHydrationModeName' => self::class]
        );

        $this->assertNull($this->configuration->getCustomHydrationMode('HydrationModeName'));
        $this->assertSame(self::class, $this->configuration->getCustomHydrationMode('AnotherHydrationModeName'));
    }

    public function testSetGetClassMetadataFactoryName(): void
    {
        $this->assertSame(AnnotationNamespace\ClassMetadataFactory::class, $this->configuration->getClassMetadataFactoryName());
        $this->configuration->setClassMetadataFactoryName(self::class);
        $this->assertSame(self::class, $this->configuration->getClassMetadataFactoryName());
    }

    public function testAddGetFilters(): void
    {
        $this->assertSame(null, $this->configuration->getFilterClassName('NonExistingFilter'));
        $this->configuration->addFilter('FilterName', self::class);
        $this->assertSame(self::class, $this->configuration->getFilterClassName('FilterName'));
    }

    public function setDefaultRepositoryClassName(): void
    {
        $this->assertSame(EntityRepository::class, $this->configuration->getDefaultRepositoryClassName());
        $this->configuration->setDefaultRepositoryClassName(DDC753CustomRepository::class);
        $this->assertSame(DDC753CustomRepository::class, $this->configuration->getDefaultRepositoryClassName());
        $this->expectException(ORMException::class);
        $this->configuration->setDefaultRepositoryClassName(self::class);
    }

    public function testSetGetNamingStrategy(): void
    {
        $this->assertInstanceOf(NamingStrategy::class, $this->configuration->getNamingStrategy());
        $namingStrategy = $this->createMock(NamingStrategy::class);
        $this->configuration->setNamingStrategy($namingStrategy);
        $this->assertSame($namingStrategy, $this->configuration->getNamingStrategy());
    }

    public function testSetGetQuoteStrategy(): void
    {
        $this->assertInstanceOf(QuoteStrategy::class, $this->configuration->getQuoteStrategy());
        $quoteStrategy = $this->createMock(QuoteStrategy::class);
        $this->configuration->setQuoteStrategy($quoteStrategy);
        $this->assertSame($quoteStrategy, $this->configuration->getQuoteStrategy());
    }

    /**
     * @group DDC-1955
     */
    public function testSetGetEntityListenerResolver(): void
    {
        $this->assertInstanceOf(EntityListenerResolver::class, $this->configuration->getEntityListenerResolver());
        $this->assertInstanceOf(AnnotationNamespace\DefaultEntityListenerResolver::class, $this->configuration->getEntityListenerResolver());
        $resolver = $this->createMock(EntityListenerResolver::class);
        $this->configuration->setEntityListenerResolver($resolver);
        $this->assertSame($resolver, $this->configuration->getEntityListenerResolver());
    }

    /**
     * @group DDC-2183
     */
    public function testSetGetSecondLevelCacheConfig(): void
    {
        $mockClass = $this->createMock(CacheConfiguration::class);

        $this->assertNull($this->configuration->getSecondLevelCacheConfiguration());
        $this->configuration->setSecondLevelCacheConfiguration($mockClass);
        $this->assertEquals($mockClass, $this->configuration->getSecondLevelCacheConfiguration());
    }
}

class ConfigurationTestAnnotationReaderChecker
{
    /** @PrePersist */
    public function simpleAnnotationMethod(): void
    {
    }

    /** @AnnotationNamespace\PrePersist */
    public function namespacedAnnotationMethod(): void
    {
    }
}
