<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\ClearCache\CollectionRegionCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Tests\Models\Cache\State;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** @group DDC-2183 */
class ClearCacheCollectionRegionCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    /** @var Application */
    private $application;

    /** @var CollectionRegionCommand */
    private $command;

    protected function setUp(): void
    {
        $this->enableSecondLevelCache();

        parent::setUp();

        $this->command = new CollectionRegionCommand(new SingleManagerProvider($this->_em));

        $this->application = new Application();
        self::addCommandToApplication($this->application, $this->command);
    }

    public function testClearAllRegion(): void
    {
        $command = $this->application->find('orm:clear-cache:region:collection');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command' => $command->getName(),
                '--all'   => true,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing all second-level cache collection regions',
            $tester
        );
    }

    public function testClearByOwnerEntityClassName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:collection');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'     => $command->getName(),
                'owner-class' => State::class,
                'association' => 'cities',
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing second-level cache for collection "Doctrine\Tests\Models\Cache\State#cities"',
            $tester
        );
    }

    public function testClearCacheEntryName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:collection');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'     => $command->getName(),
                'owner-class' => State::class,
                'association' => 'cities',
                'owner-id'    => 1,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Clearing second-level cache entry for collection "Doctrine\Tests\Models\Cache\State#cities" owner entity identified by "1"',
            $tester
        );
    }

    public function testFlushRegionName(): void
    {
        $command = $this->application->find('orm:clear-cache:region:collection');
        $tester  = new CommandTester($command);

        $tester->execute(
            [
                'command'     => $command->getName(),
                'owner-class' => State::class,
                'association' => 'cities',
                '--flush'     => true,
            ],
            ['decorated' => false]
        );

        self::assertUnwrappedDisplayContainsString(
            'Flushing cache provider configured for "Doctrine\Tests\Models\Cache\State#cities"',
            $tester
        );
    }
}
