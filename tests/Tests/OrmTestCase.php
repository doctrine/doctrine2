<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\CacheFactory;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Logging\StatisticsCacheLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Tests\Mocks\AttributeDriverFactory;
use Doctrine\Tests\Mocks\EntityManagerMock;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function class_exists;
use function filter_var;
use function getenv;
use function method_exists;
use function sprintf;

use const FILTER_VALIDATE_BOOLEAN;

// DBAL 3 compatibility
class_exists('Doctrine\\DBAL\\Platforms\\SqlitePlatform');

/**
 * Base testcase class for all ORM testcases.
 */
abstract class OrmTestCase extends TestCase
{
    /**
     * The metadata cache that is shared between all ORM tests (except functional tests).
     */
    private static CacheItemPoolInterface|null $metadataCache = null;

    /**
     * The query cache that is shared between all ORM tests (except functional tests).
     */
    private static CacheItemPoolInterface|null $queryCache = null;

    /**
     * The DBAL types registry shared by the tests, see {@see createDbalConfiguration()}.
     */
    private static TypeRegistry|null $typeRegistry = null;

    /** @var bool */
    protected $isSecondLevelCacheEnabled = false;

    /** @var bool */
    protected $isSecondLevelCacheLogEnabled = false;

    /** @var CacheFactory */
    protected $secondLevelCacheFactory;

    /** @var StatisticsCacheLogger */
    protected $secondLevelCacheLogger;

    private CacheItemPoolInterface|null $secondLevelCache = null;

    /** @param list<string> $paths */
    protected function createAttributeDriver(array $paths = []): AttributeDriver
    {
        return AttributeDriverFactory::createAttributeDriver($paths);
    }

    /**
     * Creates an EntityManager for testing purposes.
     *
     * NOTE: The created EntityManager will have its dependant DBAL parts completely
     * mocked out using a DriverMock, ConnectionMock, etc. These mocks can then
     * be configured in the tests to simulate the DBAL behavior that is desired
     * for a particular test,
     */
    protected function getTestEntityManager(): EntityManagerMock
    {
        return $this->createTestEntityManagerWithPlatform(new SQLitePlatform());
    }

    protected function createTestEntityManagerWithConnection(Connection $connection): EntityManagerMock
    {
        return $this->buildTestEntityManagerWithPlatform($connection);
    }

    protected function createTestEntityManagerWithPlatform(AbstractPlatform $platform): EntityManagerMock
    {
        return $this->buildTestEntityManagerWithPlatform(
            $this->createConnectionMock($platform),
        );
    }

    final protected function getEnv(string $name, bool $default): bool
    {
        $envVar = getenv($name);

        if ($envVar === false) {
            // If the environment variable is not set, use the default.
            // This is OK because environment variables are always strings, and
            // we are comparing it to a boolean.
            $envVar = $default;
        }

        return filter_var($envVar, FILTER_VALIDATE_BOOLEAN);
    }

    private function buildTestEntityManagerWithPlatform(Connection $connection): EntityManagerMock
    {
        $metadataCache = self::getSharedMetadataCacheImpl();

        $config = new Configuration();

        if (method_exists(Schema::class, 'edit') && $this->getEnv('ENABLE_SCHEMA_EDITOR_API', true)) {
            $config->setUseSchemaEditorApi(true);
        }

        TestUtil::configureProxies($config);
        $config->setMetadataCache($metadataCache);
        $config->setQueryCache(self::getSharedQueryCache());
        $config->setMetadataDriverImpl(AttributeDriverFactory::createAttributeDriver([__DIR__ . '/Models/Cache']));

        if ($this->isSecondLevelCacheEnabled) {
            $cacheConfig = new CacheConfiguration();
            $factory     = new DefaultCacheFactory(
                $cacheConfig->getRegionsConfiguration(),
                $this->getSharedSecondLevelCache(),
            );

            $this->secondLevelCacheFactory = $factory;

            $cacheConfig->setCacheFactory($factory);
            $config->setSecondLevelCacheEnabled();
            $config->setSecondLevelCacheConfiguration($cacheConfig);
        }

        return new EntityManagerMock($connection, $config);
    }

    protected function enableSecondLevelCache(bool $log = true): void
    {
        $this->isSecondLevelCacheEnabled    = true;
        $this->isSecondLevelCacheLogEnabled = $log;
    }

    private static function getSharedMetadataCacheImpl(): CacheItemPoolInterface
    {
        return self::$metadataCache
            ?? self::$metadataCache = new ArrayAdapter();
    }

    private static function getSharedQueryCache(): CacheItemPoolInterface
    {
        return self::$queryCache
            ?? self::$queryCache = new ArrayAdapter();
    }

    protected function getSharedSecondLevelCache(): CacheItemPoolInterface
    {
        return $this->secondLevelCache
            ?? $this->secondLevelCache = new ArrayAdapter();
    }

    /**
     * Creates a fully stubbed connection with the given platform and a default DBAL
     * configuration, so that {@see \Doctrine\ORM\Internal\TypeProviderLocator} can resolve
     * the type registry. On DBAL >= 4.6, the shared type registry is injected into the
     * configuration.
     */
    final protected function createConnectionStub(AbstractPlatform $platform): Connection&Stub
    {
        $configuration = self::createDbalConfiguration();

        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')
            ->willReturn($platform);
        $connection->method('getConfiguration')
            ->willReturn($configuration);

        return $connection;
    }

    /**
     * Registers a DBAL type used by the tests.
     *
     * @param class-string<Type> $type
     */
    final protected static function registerDbalType(string $name, string $type): void
    {
        if (! method_exists(DBALConfiguration::class, 'setTypeProvider')) {
            if (Type::hasType($name)) {
                Type::overrideType($name, $type);
            } else {
                Type::addType($name, $type);
            }

            return;
        }

        $typeRegistry = self::getTypeRegistry();

        if ($typeRegistry->has($name)) {
            $typeRegistry->override($name, $type);
        } else {
            $typeRegistry->register($name, $type);
        }
    }

    /**
     * Tells whether a DBAL type used by the tests is registered.
     */
    final protected static function hasDbalType(string $name): bool
    {
        if (method_exists(DBALConfiguration::class, 'setTypeProvider')) {
            return self::getTypeRegistry()->has($name);
        }

        return Type::hasType($name);
    }

    /**
     * Returns the DBAL types registry shared by the tests.
     */
    final protected static function getTypeRegistry(): TypeRegistry
    {
        return self::$typeRegistry ??= new TypeRegistry();
    }

    /**
     * Discards the types registered by a test, as the registry cannot unregister them.
     */
    protected function tearDown(): void
    {
        self::$typeRegistry = null;
    }

    /**
     * Creates a DBAL configuration whose type provider is the registry shared by the tests.
     */
    final protected static function createDbalConfiguration(): DBALConfiguration
    {
        $configuration = new DBALConfiguration();

        if (method_exists($configuration, 'setTypeProvider')) {
            $configuration->setTypeProvider(self::getTypeRegistry());
        }

        return $configuration;
    }

    private function createConnectionMock(AbstractPlatform $platform): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([[], $this->createDriverStub($platform), self::createDbalConfiguration()])
            ->onlyMethods(['quote'])
            ->getMock();
        $connection->method('quote')->willReturnCallback(static fn (string $input) => sprintf("'%s'", $input));

        return $connection;
    }

    private function createDriverStub(AbstractPlatform $platform): Driver
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')
            ->willReturn(false);

        $connection = $this->createStub(Driver\Connection::class);
        $connection->method('query')
            ->willReturn($result);

        $driver = $this->createStub(Driver::class);
        $driver->method('connect')
            ->willReturn($connection);
        $driver->method('getDatabasePlatform')
            ->willReturn($platform);

        if (method_exists(Driver::class, 'getSchemaManager')) {
            $driver->method('getSchemaManager')
                ->willReturnCallback([$platform, 'createSchemaManager']);
        }

        return $driver;
    }
}
