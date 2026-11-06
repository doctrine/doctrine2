<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for {@see \Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand}
 *
 * @covers \Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand
 */
class ValidateSchemaCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    /** @var ValidateSchemaCommand */
    private $command;

    /** @var CommandTester */
    private $tester;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->_em->getConnection()->getDatabasePlatform() instanceof SqlitePlatform) {
            self::markTestSkipped('Only with sqlite');
        }

        $application = new Application();
        self::addCommandToApplication($application, new ValidateSchemaCommand(new SingleManagerProvider($this->_em)));

        $this->command = $application->find('orm:validate-schema');
        $this->tester  = new CommandTester($this->command);
    }

    public function testNotInSync(): void
    {
        $this->tester->execute(
            [
                'command' => $this->command->getName(),
            ]
        );

        self::assertUnwrappedDisplayContainsString(
            'The database schema is not in sync with the current mapping file',
            $this->tester
        );
        self::assertStringNotContainsString('cache_login', $this->tester->getDisplay());
    }

    public function testNotInSyncVerbose(): void
    {
        $schemaManager = $this->createSchemaManager();
        if ($schemaManager->tablesExist('cache_login')) {
            $schemaManager->dropTable('cache_login');
        }

        $this->tester->execute(
            [
                'command' => $this->command->getName(),
            ],
            [
                'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
            ]
        );

        self::assertUnwrappedDisplayContainsString(
            'The database schema is not in sync with the current mapping file',
            $this->tester
        );
        self::assertUnwrappedDisplayContainsString('cache_login', $this->tester);
    }
}
