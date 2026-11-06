<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\MappingDescribeCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Tests\Models\Cache\AttractionInfo;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for {@see \Doctrine\ORM\Tools\Console\Command\MappingDescribeCommand}
 *
 * @covers \Doctrine\ORM\Tools\Console\Command\MappingDescribeCommand
 */
class MappingDescribeCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    /** @var Application */
    private $application;

    /** @var MappingDescribeCommand */
    private $command;

    /** @var CommandTester */
    private $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = new Application();
        self::addCommandToApplication($this->application, new MappingDescribeCommand(new SingleManagerProvider($this->_em)));

        $this->command = $this->application->find('orm:mapping:describe');
        $this->tester  = new CommandTester($this->command);
    }

    public function testShowSpecificFuzzySingle(): void
    {
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => 'AttractionInfo',
            ]
        );

        self::assertUnwrappedDisplayContainsString(AttractionInfo::class, $this->tester);
        self::assertUnwrappedDisplayContainsString('Root entity name', $this->tester);
    }

    public function testShowSpecificFuzzyAmbiguous(): void
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('possible matches');
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => 'Attraction',
            ]
        );
    }

    public function testShowSpecificNotFound(): void
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Could not find any mapped Entity classes matching "AttractionFooBar"');
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => 'AttractionFooBar',
            ]
        );
    }

    public function testShowEntityWithEntityListeners(): void
    {
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => CmsAddress::class,
            ]
        );

        self::assertUnwrappedDisplayContainsString(CmsAddress::class, $this->tester);
        self::assertUnwrappedDisplayContainsString('Entity listeners', $this->tester);
        self::assertUnwrappedDisplayContainsString('CmsAddressListener', $this->tester);
        self::assertUnwrappedDisplayContainsString('postPersist', $this->tester);
        self::assertUnwrappedDisplayContainsString('prePersist', $this->tester);
    }
}
