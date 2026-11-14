<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DatabaseObjectNotFoundException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use UnexpectedValueException;

use function assert;
use function class_exists;
use function explode;
use function fwrite;
use function get_debug_type;
use function in_array;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const STDERR;

/**
 * TestUtil is a class with static utility methods used during tests.
 */
class TestUtil
{
    /** @var bool Whether the database schema is initialized. */
    private static bool $initialized = false;

    /**
     * Gets a <b>real</b> database connection using the following parameters
     * of the $GLOBALS array:
     *
     * 'db_driver' : The name of the Doctrine DBAL database driver to use.
     * 'db_user' : The username to use for connecting.
     * 'db_password' : The password to use for connecting.
     * 'db_host' : The hostname of the database to connect to.
     * 'db_server' : The server name of the database to connect to
     *               (optional, some vendors allow multiple server instances with different names on the same host).
     * 'db_dbname' : The name of the database to connect to.
     * 'db_port' : The port of the database to connect to.
     *
     * These variables of the $GLOBALS array are filled by PHPUnit based on an XML configuration file.
     *
     * IMPORTANT:
     * 1) Each invocation of this method returns a NEW database connection.
     * 2) The database is dropped and recreated to ensure it's clean.
     */
    public static function getConnection(DbalConfiguration|null $config = null): DbalExtensions\Connection
    {
        if (! self::$initialized) {
            self::initializeDatabase();
            self::$initialized = true;
        }

        $connectionParameters = self::getTestConnectionParameters();

        if (in_array($connectionParameters['driver'], ['pdo_sqlite', 'sqlite3'], true) && class_exists(EnableForeignKeys::class)) {
            if ($config === null) {
                $config = new DbalConfiguration();
            }

            $config->setMiddlewares([...$config->getMiddlewares(), new EnableForeignKeys()]);
        }

        $connection = DriverManager::getConnection($connectionParameters, $config);
        assert($connection instanceof DbalExtensions\Connection);

        self::addDbEventSubscribers($connection);

        return $connection;
    }

    public static function getPrivilegedConnection(): DbalExtensions\Connection
    {
        $connection = DriverManager::getConnection(self::getPrivilegedConnectionParameters());
        assert($connection instanceof DbalExtensions\Connection);

        return $connection;
    }

    private static function initializeDatabase(): void
    {
        $testConnParams = self::getTestConnectionParameters();
        $privConnParams = self::getPrivilegedConnectionParameters();

        $testConn = DriverManager::getConnection($testConnParams);

        // Note, writes direct to STDERR to prevent phpunit detecting output - otherwise this would cause either an
        // "unexpected output" warning or a failure on the first test case to call this method.
        fwrite(STDERR, sprintf("\nUsing DB driver %s\n", get_debug_type($testConn->getDriver())));

        // Connect as a privileged user to create and drop the test database.
        $privConn = DriverManager::getConnection($privConnParams);

        $platform = $privConn->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            $schema = $testConn->createSchemaManager()->introspectSchema();
            $stmts  = $schema->toDropSql($testConn->getDatabasePlatform());

            foreach ($stmts as $stmt) {
                $testConn->executeStatement($stmt);
            }
        } else {
            $dbname = $testConnParams['dbname'] ?? $testConn->getDatabase();
            $testConn->close();

            $schemaManager = $privConn->createSchemaManager();

            try {
                $schemaManager->dropDatabase($dbname);
            } catch (DatabaseObjectNotFoundException) {
            }

            $schemaManager->createDatabase($dbname);

            $privConn->close();
        }
    }

    private static function addDbEventSubscribers(Connection $conn): void
    {
        if (! isset($GLOBALS['db_event_subscribers'])) {
            return;
        }

        $evm = $conn->getEventManager();
        /** @var class-string<EventSubscriber> $subscriberClass */
        foreach (explode(',', $GLOBALS['db_event_subscribers']) as $subscriberClass) {
            $subscriberInstance = new $subscriberClass();
            $evm->addEventSubscriber($subscriberInstance);
        }
    }

    /** @return array<string, mixed> */
    private static function getPrivilegedConnectionParameters(): array
    {
        if (isset($GLOBALS['privileged_db_driver'])) {
            return self::mapConnectionParameters($GLOBALS, 'privileged_db_');
        }

        $parameters = self::mapConnectionParameters($GLOBALS, 'db_');
        unset($parameters['dbname']);

        return $parameters;
    }

    /** @phpstan-return array<string, mixed> */
    private static function getTestConnectionParameters(): array
    {
        if (! isset($GLOBALS['db_driver'])) {
            throw new UnexpectedValueException(
                'You must provide database connection params including a db_driver value. See phpunit.xml.dist for details',
            );
        }

        return self::mapConnectionParameters($GLOBALS, 'db_');
    }

    /**
     * @param array<string,mixed> $configuration
     *
     * @return array<string,mixed>
     */
    private static function mapConnectionParameters(array $configuration, string $prefix): array
    {
        $parameters = [];

        foreach (
            [
                'driver',
                'user',
                'password',
                'host',
                'dbname',
                'port',
                'server',
                'memory',
                'path',
                'ssl_key',
                'ssl_cert',
                'ssl_ca',
                'ssl_capath',
                'ssl_cipher',
                'unix_socket',
            ] as $parameter
        ) {
            if (! isset($configuration[$prefix . $parameter])) {
                continue;
            }

            $parameters[$parameter] = $configuration[$prefix . $parameter];
        }

        if (isset($parameters['port'])) {
            $parameters['port'] = (int) $parameters['port'];
        }

        foreach ($configuration as $param => $value) {
            if (str_starts_with($param, $prefix . 'driver_option_')) {
                $parameters['driverOptions'][substr($param, strlen($prefix . 'driver_option_'))] = $value;
            }

            if (! str_starts_with($param, $prefix . 'default_table_option_')) {
                continue;
            }

            $parameters['defaultTableOptions'][substr($param, strlen($prefix . 'default_table_option_'))] = $value;
        }

        $parameters['wrapperClass'] = DbalExtensions\Connection::class;

        return $parameters;
    }
}
