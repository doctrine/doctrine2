<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\RunDqlCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Tests\Models\Generic\DateTimeModel;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function trim;

/**
 * Tests for {@see \Doctrine\ORM\Tools\Console\Command\RunDqlCommand}
 */
#[CoversClass(RunDqlCommand::class)]
class RunDqlCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    private Application $application;

    private RunDqlCommand $command;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->useModelSet('generic');

        parent::setUp();

        $this->command = new RunDqlCommand(new SingleManagerProvider($this->_em));

        $this->application = new Application();
        self::addCommandToApplication($this->application, $this->command);

        $this->tester = new CommandTester($this->command);
    }

    public function testCommandName(): void
    {
        self::assertSame($this->command, $this->application->get('orm:run-dql'));
    }

    public function testWillRunQuery(): void
    {
        $this->_em->persist(new DateTimeModel());
        $this->_em->flush();

        self::assertSame(
            0,
            $this->tester->execute(
                [
                    'command' => $this->command->getName(),
                    'dql'     => 'SELECT e FROM ' . DateTimeModel::class . ' e',
                ],
            ),
        );

        self::assertUnwrappedDisplayContainsString(DateTimeModel::class, $this->tester);
    }

    public function testWillShowQuery(): void
    {
        $this->_em->persist(new DateTimeModel());
        $this->_em->flush();

        self::assertSame(
            0,
            $this->tester->execute(
                [
                    'command'    => $this->command->getName(),
                    'dql'        => 'SELECT e FROM ' . DateTimeModel::class . ' e',
                    '--show-sql' => 'true',
                ],
            ),
        );

        self::assertStringMatchesFormat('SELECT %a', trim($this->tester->getDisplay()));
    }
}
