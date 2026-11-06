<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\ClearCache\QueryRegionCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('DDC-2183')]
class ClearCacheQueryRegionCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    private Application $application;

    private QueryRegionCommand $command;

    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->command = new QueryRegionCommand(new SingleManagerProvider($this->_em));

        $this->application = new Application();
        self::addCommandToApplication($this->application, $this->command);
    }

    public function testClearAllRegion(): void
    {
        $command = $this->application->find('orm:clear-cache:region:query');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command' => $command->getName(),
                '--all'   => true,
            ],
            ['decorated' => false],
        );

        self::assertUnwrappedDisplayContainsString('Clearing all second-level cache query regions', $tester);
    }

    public function testClearDefaultRegionName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:query');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'     => $command->getName(),
                'region-name' => null,
            ],
            ['decorated' => false],
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing second-level cache query region named "query_cache_region"',
            $tester,
        );
    }

    public function testClearByRegionName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:query');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'     => $command->getName(),
                'region-name' => 'my_region',
            ],
            ['decorated' => false],
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing second-level cache query region named "my_region"',
            $tester,
        );
    }

    public function testFlushRegionName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:query');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'     => $command->getName(),
                'region-name' => 'my_region',
                '--flush'     => true,
            ],
            ['decorated' => false],
        );

        self::assertUnwrappedDisplayContainsString(
            'Flushing cache provider configured for second-level cache query region named "my_region"',
            $tester,
        );
    }
}
