<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\InfoCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Tests\Models\Cache\AttractionInfo;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InfoCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    private Application $application;
    private InfoCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->application = new Application();

        self::addCommandToApplication($this->application, new InfoCommand(new SingleManagerProvider($this->_em)));

        $this->command = $this->application->find('orm:info');
        $this->tester  = new CommandTester($this->command);
    }

    public function testListAllClasses(): void
    {
        $this->tester->execute(['command' => $this->command->getName()]);

        self::assertUnwrappedDisplayContainsString(AttractionInfo::class, $this->tester);
        self::assertUnwrappedDisplayContainsString(City::class, $this->tester);
    }

    public function testEmptyEntityClassNames(): void
    {
        $mappingDriver = $this->createStub(MappingDriver::class);
        $configuration = $this->createStub(Configuration::class);
        $em            = $this->createStub(EntityManagerInterface::class);

        $mappingDriver->method('getAllClassNames')
                      ->willReturn([]);

        $configuration->method('getMetadataDriverImpl')
                      ->willReturn($mappingDriver);

        $em->method('getConfiguration')
           ->willReturn($configuration);

        $application = new Application();
        self::addCommandToApplication($application, new InfoCommand(new SingleManagerProvider($em)));

        $command = $application->find('orm:info');
        $tester  = new CommandTester($command);

        $tester->execute(['command' => $command->getName()]);

        self::assertUnwrappedDisplayContainsString(
            '[CAUTION] You do not have any mapped Doctrine ORM entities according to the current configuration',
            $tester,
        );

        self::assertUnwrappedDisplayContainsString(
            'If you have entities or mapping files you should check your mapping configuration for errors.',
            $tester,
        );
    }

    public function testInvalidEntityClassMetadata(): void
    {
        $mappingDriver = $this->createStub(MappingDriver::class);
        $configuration = $this->createStub(Configuration::class);
        $em            = $this->createMock(EntityManagerInterface::class);

        $mappingDriver->method('getAllClassNames')
                      ->willReturn(['InvalidEntity']);

        $configuration->method('getMetadataDriverImpl')
                      ->willReturn($mappingDriver);

        $em->method('getConfiguration')
           ->willReturn($configuration);

        $em->method('getClassMetadata')
           ->with('InvalidEntity')
           ->willThrowException(new MappingException('exception message'));

        $application = new Application();
        self::addCommandToApplication($application, new InfoCommand(new SingleManagerProvider($em)));

        $command = $application->find('orm:info');
        $tester  = new CommandTester($command);

        $tester->execute(['command' => $command->getName()]);

        self::assertUnwrappedDisplayContainsString('[FAIL] InvalidEntity', $tester);
        self::assertUnwrappedDisplayContainsString('exception message', $tester);
    }
}
