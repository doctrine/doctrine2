<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Exception\InvalidEntityRepository;
use Doctrine\ORM\Mapping as MappingNamespace;
use Doctrine\ORM\Mapping\DefaultTypedFieldMapper;
use Doctrine\ORM\Mapping\EntityListenerResolver;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Tests\Models\DDC753\DDC753CustomRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Tests for the Configuration object
 */
class ConfigurationTest extends TestCase
{
    use VerifyDeprecations;

    private Configuration $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = new Configuration();
    }

    #[IgnoreDeprecations]
    public function testSetGetProxyDir(): void
    {
        self::assertNull($this->configuration->getProxyDir()); // defaults

        $this->configuration->setProxyDir(__DIR__);
        self::assertSame(__DIR__, $this->configuration->getProxyDir());
    }

    #[IgnoreDeprecations]
    public function testSetGetAutoGenerateProxyClasses(): void
    {
        self::assertSame(ProxyFactory::AUTOGENERATE_ALWAYS, $this->configuration->getAutoGenerateProxyClasses()); // defaults

        $this->configuration->setAutoGenerateProxyClasses(false);
        self::assertSame(ProxyFactory::AUTOGENERATE_NEVER, $this->configuration->getAutoGenerateProxyClasses());

        $this->configuration->setAutoGenerateProxyClasses(true);
        self::assertSame(ProxyFactory::AUTOGENERATE_ALWAYS, $this->configuration->getAutoGenerateProxyClasses());

        $this->configuration->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);
        self::assertSame(ProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS, $this->configuration->getAutoGenerateProxyClasses());
    }

    #[IgnoreDeprecations]
    public function testSetGetProxyNamespace(): void
    {
        self::assertNull($this->configuration->getProxyNamespace()); // defaults

        $this->configuration->setProxyNamespace(__NAMESPACE__);
        self::assertSame(__NAMESPACE__, $this->configuration->getProxyNamespace());
    }

    public function testSetGetMetadataDriverImpl(): void
    {
        self::assertNull($this->configuration->getMetadataDriverImpl()); // defaults

        $metadataDriver = $this->createStub(MappingDriver::class);
        $this->configuration->setMetadataDriverImpl($metadataDriver);
        self::assertSame($metadataDriver, $this->configuration->getMetadataDriverImpl());
    }

    public function testSetGetQueryCache(): void
    {
        self::assertNull($this->configuration->getQueryCache()); // defaults
        $queryCache = $this->createStub(CacheItemPoolInterface::class);
        $this->configuration->setQueryCache($queryCache);
        self::assertSame($queryCache, $this->configuration->getQueryCache());
    }

    public function testSetGetHydrationCache(): void
    {
        self::assertNull($this->configuration->getHydrationCache()); // defaults
        $hydrationCache = $this->createStub(CacheItemPoolInterface::class);
        $this->configuration->setHydrationCache($hydrationCache);
        self::assertSame($hydrationCache, $this->configuration->getHydrationCache());
    }

    public function testSetGetMetadataCache(): void
    {
        self::assertNull($this->configuration->getMetadataCache());
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $this->configuration->setMetadataCache($cache);
        self::assertSame($cache, $this->configuration->getMetadataCache());
    }

    public function testAddGetCustomStringFunction(): void
    {
        $this->configuration->addCustomStringFunction('FunctionName', self::class);
        self::assertSame(self::class, $this->configuration->getCustomStringFunction('FunctionName'));
        self::assertNull($this->configuration->getCustomStringFunction('NonExistingFunction'));
        $this->configuration->setCustomStringFunctions(['OtherFunctionName' => self::class]);
        self::assertSame(self::class, $this->configuration->getCustomStringFunction('OtherFunctionName'));
    }

    public function testAddGetCustomNumericFunction(): void
    {
        $this->configuration->addCustomNumericFunction('FunctionName', self::class);
        self::assertSame(self::class, $this->configuration->getCustomNumericFunction('FunctionName'));
        self::assertNull($this->configuration->getCustomNumericFunction('NonExistingFunction'));
        $this->configuration->setCustomNumericFunctions(['OtherFunctionName' => self::class]);
        self::assertSame(self::class, $this->configuration->getCustomNumericFunction('OtherFunctionName'));
    }

    public function testAddGetCustomDatetimeFunction(): void
    {
        $this->configuration->addCustomDatetimeFunction('FunctionName', self::class);
        self::assertSame(self::class, $this->configuration->getCustomDatetimeFunction('FunctionName'));
        self::assertNull($this->configuration->getCustomDatetimeFunction('NonExistingFunction'));
        $this->configuration->setCustomDatetimeFunctions(['OtherFunctionName' => self::class]);
        self::assertSame(self::class, $this->configuration->getCustomDatetimeFunction('OtherFunctionName'));
    }

    public function testAddGetCustomHydrationMode(): void
    {
        self::assertNull($this->configuration->getCustomHydrationMode('NonExisting'));
        $this->configuration->addCustomHydrationMode('HydrationModeName', self::class);
        self::assertSame(self::class, $this->configuration->getCustomHydrationMode('HydrationModeName'));
    }

    public function testSetCustomHydrationModes(): void
    {
        $this->configuration->addCustomHydrationMode('HydrationModeName', self::class);
        self::assertSame(self::class, $this->configuration->getCustomHydrationMode('HydrationModeName'));

        $this->configuration->setCustomHydrationModes(
            ['AnotherHydrationModeName' => self::class],
        );

        self::assertNull($this->configuration->getCustomHydrationMode('HydrationModeName'));
        self::assertSame(self::class, $this->configuration->getCustomHydrationMode('AnotherHydrationModeName'));
    }

    public function testSetGetClassMetadataFactoryName(): void
    {
        self::assertSame(MappingNamespace\ClassMetadataFactory::class, $this->configuration->getClassMetadataFactoryName());
        $this->configuration->setClassMetadataFactoryName(self::class);
        self::assertSame(self::class, $this->configuration->getClassMetadataFactoryName());
    }

    public function testAddGetFilters(): void
    {
        self::assertNull($this->configuration->getFilterClassName('NonExistingFilter'));
        $this->configuration->addFilter('FilterName', self::class);
        self::assertSame(self::class, $this->configuration->getFilterClassName('FilterName'));
    }

    public function setDefaultRepositoryClassName(): void
    {
        self::assertSame(EntityRepository::class, $this->configuration->getDefaultRepositoryClassName());
        $this->configuration->setDefaultRepositoryClassName(DDC753CustomRepository::class);
        self::assertSame(DDC753CustomRepository::class, $this->configuration->getDefaultRepositoryClassName());
        $this->expectException(InvalidEntityRepository::class);
        $this->expectExceptionMessage('Invalid repository class \'Doctrine\Tests\ORM\ConfigurationTest\'. It must be a Doctrine\ORM\EntityRepository.');
        $this->configuration->setDefaultRepositoryClassName(self::class);
    }

    public function testSetGetNamingStrategy(): void
    {
        self::assertInstanceOf(NamingStrategy::class, $this->configuration->getNamingStrategy());
        $namingStrategy = $this->createStub(NamingStrategy::class);
        $this->configuration->setNamingStrategy($namingStrategy);
        self::assertSame($namingStrategy, $this->configuration->getNamingStrategy());
    }

    public function testSetGetQuoteStrategy(): void
    {
        self::assertInstanceOf(QuoteStrategy::class, $this->configuration->getQuoteStrategy());
        $quoteStrategy = $this->createStub(QuoteStrategy::class);
        $this->configuration->setQuoteStrategy($quoteStrategy);
        self::assertSame($quoteStrategy, $this->configuration->getQuoteStrategy());
    }

    #[Group('DDC-1955')]
    public function testSetGetEntityListenerResolver(): void
    {
        self::assertInstanceOf(EntityListenerResolver::class, $this->configuration->getEntityListenerResolver());
        self::assertInstanceOf(MappingNamespace\DefaultEntityListenerResolver::class, $this->configuration->getEntityListenerResolver());
        $resolver = $this->createStub(EntityListenerResolver::class);
        $this->configuration->setEntityListenerResolver($resolver);
        self::assertSame($resolver, $this->configuration->getEntityListenerResolver());
    }

    #[Group('DDC-2183')]
    public function testSetGetSecondLevelCacheConfig(): void
    {
        $mockClass = $this->createStub(CacheConfiguration::class);

        self::assertNull($this->configuration->getSecondLevelCacheConfiguration());
        $this->configuration->setSecondLevelCacheConfiguration($mockClass);
        self::assertEquals($mockClass, $this->configuration->getSecondLevelCacheConfiguration());
    }

    #[Group('GH10313')]
    public function testSetGetTypedFieldMapper(): void
    {
        self::assertEmpty($this->configuration->getTypedFieldMapper());
        $defaultTypedFieldMapper = new DefaultTypedFieldMapper();
        $this->configuration->setTypedFieldMapper($defaultTypedFieldMapper);
        self::assertSame($defaultTypedFieldMapper, $this->configuration->getTypedFieldMapper());
    }

    #[RequiresPhp('>=8.4.0')]
    #[IgnoreDeprecations]
    public function testDisablingNativeLazyObjectsIsDeprecated(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12005');

        $this->configuration->enableNativeLazyObjects(false);
    }

    public function testSetGetOnRemoveEntitySetIdentifierNull(): void
    {
        self::assertTrue($this->configuration->isOnRemoveEntitySetIdentifierNull());

        $this->configuration->setOnRemoveEntitySetIdentifierNull(false);

        self::assertFalse($this->configuration->isOnRemoveEntitySetIdentifierNull());
    }
}
