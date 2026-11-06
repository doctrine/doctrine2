<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command\SchemaTool;

use Doctrine\ORM\Tools\Console\Command\SchemaTool\UpdateCommand;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;

class UpdateCommandTest extends CommandTestCase
{
    use CommandTesterAssertions;

    /** @doesNotPerformAssertions */
    public function testItPrintsTheSql(): void
    {
        $tester = $this->getCommandTester(UpdateCommand::class);
        $tester->execute(
            ['--dump-sql' => true],
            ['capture_stderr_separately' => true]
        );

        self::$sharedConn->executeStatement($tester->getDisplay());
    }

    /**
     * @dataProvider getCasesForWarningMessageFromCompleteOption
     */
    public function testWarningMessageFromCompleteOption(?string $name, string $expectedMessage): void
    {
        $tester = $this->getCommandTester(UpdateCommand::class, $name);
        $tester->execute(
            [],
            ['capture_stderr_separately' => true]
        );

        self::assertUnwrappedErrorOutputContainsString($expectedMessage, $tester);
    }

    public static function getCasesForWarningMessageFromCompleteOption(): iterable
    {
        yield 'default_name' => [
            null,
            '[WARNING] Not passing the "--complete" option to "orm:schema-tool:update" is deprecated',
        ];

        yield 'custom_name' => [
            'doctrine:schema:update',
            '[WARNING] Not passing the "--complete" option to "doctrine:schema:update" is deprecated',
        ];
    }
}
