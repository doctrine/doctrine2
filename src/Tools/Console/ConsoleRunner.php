<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Console;

use Composer\InstalledVersions;
use Doctrine\DBAL\Tools\Console as DBALConsole;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\ConnectionFromManagerProvider;
use OutOfBoundsException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function assert;

/**
 * Handles running the Console Tools inside Symfony Console context.
 */
final class ConsoleRunner
{
    use ApplicationCompatibility;

    /**
     * Runs console with the given helper set.
     *
     * @param SymfonyCommand[] $commands
     */
    public static function run(EntityManagerProvider $entityManagerProvider, array $commands = []): void
    {
        $cli = self::createApplication($entityManagerProvider, $commands);
        $cli->run();
    }

    /**
     * Creates a console application with the given helperset and
     * optional commands.
     *
     * @param SymfonyCommand[] $commands
     *
     * @throws OutOfBoundsException
     */
    public static function createApplication(
        EntityManagerProvider $entityManagerProvider,
        array $commands = [],
    ): Application {
        $version = InstalledVersions::getVersion('doctrine/orm');
        assert($version !== null);

        $cli = new Application('Doctrine Command Line Interface', $version);
        $cli->setCatchExceptions(true);

        self::addCommands($cli, $entityManagerProvider);
        $cli->addCommands($commands);

        return $cli;
    }

    public static function addCommands(Application $cli, EntityManagerProvider $entityManagerProvider): void
    {
        $connectionProvider = new ConnectionFromManagerProvider($entityManagerProvider);

        $cli->addCommands(
            [
                // DBAL Commands
                new DBALConsole\Command\RunSqlCommand($connectionProvider),

                // ORM Commands
                new Command\ClearCache\CollectionRegionCommand($entityManagerProvider),
                new Command\ClearCache\EntityRegionCommand($entityManagerProvider),
                new Command\ClearCache\MetadataCommand($entityManagerProvider),
                new Command\ClearCache\QueryCommand($entityManagerProvider),
                new Command\ClearCache\QueryRegionCommand($entityManagerProvider),
                new Command\ClearCache\ResultCommand($entityManagerProvider),
                new Command\SchemaTool\CreateCommand($entityManagerProvider),
                new Command\SchemaTool\UpdateCommand($entityManagerProvider),
                new Command\SchemaTool\DropCommand($entityManagerProvider),
                new Command\RunDqlCommand($entityManagerProvider),
                new Command\ValidateSchemaCommand($entityManagerProvider),
                new Command\InfoCommand($entityManagerProvider),
                new Command\MappingDescribeCommand($entityManagerProvider),
            ],
        );
    }
}
