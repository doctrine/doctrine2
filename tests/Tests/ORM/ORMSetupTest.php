<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;

use function sys_get_temp_dir;

use const PHP_VERSION_ID;

class ORMSetupTest extends TestCase
{
    use VerifyDeprecations;

    #[IgnoreDeprecations]
    public function testAttributeConfiguration(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12005');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([], true);

        self::assertInstanceOf(Configuration::class, $config);
        self::assertEquals(sys_get_temp_dir(), $config->getProxyDir());
        self::assertEquals('DoctrineProxies', $config->getProxyNamespace());
        self::assertInstanceOf(AttributeDriver::class, $config->getMetadataDriverImpl());
    }

    public function testAttributeConfig(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([], true);

        self::assertInstanceOf(Configuration::class, $config);
        self::assertInstanceOf(AttributeDriver::class, $config->getMetadataDriverImpl());
    }

    #[IgnoreDeprecations]
    public function testXMLConfiguration(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12005');
        }

        $config = ORMSetup::createXMLMetadataConfiguration([], true);

        self::assertInstanceOf(Configuration::class, $config);
        self::assertInstanceOf(XmlDriver::class, $config->getMetadataDriverImpl());
    }

    public function testDisablingXmlValidationIsPossible(): void
    {
        $this->expectNotToPerformAssertions();

        ORMSetup::createXMLMetadataConfig(paths: [], cache: new NullAdapter(), isXsdValidationEnabled: false);
    }

    #[RequiresPhpExtension('apcu')]
    #[RequiresSetting('apc.enable_cli', '1')]
    #[RequiresSetting('apc.enabled', '1')]
    #[IgnoreDeprecations]
    public function testCacheNamespaceShouldBeGeneratedForApcuWhenUsingLegacyConstructor(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12005');
        }

        $config = ORMSetup::createConfiguration(false, '/foo');
        $cache  = $config->getMetadataCache();

        $namespaceProperty = new ReflectionProperty(AbstractAdapter::class, 'namespace');

        self::assertInstanceOf(ApcuAdapter::class, $cache);
        self::assertSame('dc2_1effb2475fcfba4f9e8b8a1dbc8f3caf:', $namespaceProperty->getValue($cache));
    }

    #[RequiresPhpExtension('apcu')]
    #[RequiresSetting('apc.enable_cli', '1')]
    #[RequiresSetting('apc.enabled', '1')]
    public function testCacheNamespaceShouldBeGeneratedForApcu(): void
    {
        $config = ORMSetup::createConfig(false, '/foo');
        $cache  = $config->getMetadataCache();

        $namespaceProperty = new ReflectionProperty(AbstractAdapter::class, 'namespace');

        self::assertInstanceOf(ApcuAdapter::class, $cache);
        self::assertSame('dc2_1effb2475fcfba4f9e8b8a1dbc8f3caf:', $namespaceProperty->getValue($cache));
    }

    #[Group('DDC-1350')]
    #[IgnoreDeprecations]
    public function testConfigureProxyDir(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([], true, '/foo');
        self::assertEquals('/foo', $config->getProxyDir());
    }

    #[Group('DDC-1350')]
    public function testConfigureCache(): void
    {
        $cache  = new ArrayAdapter();
        $config = ORMSetup::createAttributeMetadataConfig([], true, null, $cache);

        self::assertSame($cache, $config->getResultCache());
        self::assertSame($cache, $config->getQueryCache());
        self::assertSame($cache, $config->getMetadataCache());
    }

    #[Group('DDC-3190')]
    public function testConfigureCacheCustomInstance(): void
    {
        $cache  = new ArrayAdapter();
        $config = ORMSetup::createConfig(true, null, $cache);

        self::assertSame($cache, $config->getResultCache());
        self::assertSame($cache, $config->getQueryCache());
        self::assertSame($cache, $config->getMetadataCache());
    }
}
