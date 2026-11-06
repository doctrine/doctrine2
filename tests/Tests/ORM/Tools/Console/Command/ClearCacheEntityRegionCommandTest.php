<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\ClearCache\EntityRegionCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Tests\Models\Cache\Country;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** @group DDC-2183 */
class ClearCacheEntityRegionCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    /** @var Application */
    private $application;

    /** @var EntityRegionCommand */
    private $command;

    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->command = new EntityRegionCommand(new SingleManagerProvider($this->_em));

        $this->application = new Application();
        self::addCommandToApplication($this->application, $this->command);
    }

    public function testClearAllRegion(): void
    {
        $command = $this->application->find('orm:clear-cache:region:entity');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command' => $command->getName(),
                '--all'   => true,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString('Clearing all second-level cache entity regions', $tester);
    }

    public function testClearByEntityClassName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:entity');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'      => $command->getName(),
                'entity-class' => Country::class,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing second-level cache for entity "Doctrine\Tests\Models\Cache\Country"',
            $tester
        );
    }

    public function testClearCacheEntryName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:entity');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'      => $command->getName(),
                'entity-class' => Country::class,
                'entity-id'    => 1,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing second-level cache entry for entity "Doctrine\Tests\Models\Cache\Country" identified by "1"',
            $tester
        );
    }

    public function testFlushRegionName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:entity');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'      => $command->getName(),
                'entity-class' => Country::class,
                '--flush'      => true,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Flushing cache provider configured for entity named "Doctrine\Tests\Models\Cache\Country"',
            $tester
        );
    }
}
