<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command\Debug;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\Debug\DebugEntityListenersDoctrineCommand;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BarListener;
use Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BazListener;
use Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\FooListener;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;

class DebugEntityListenersDoctrineCommandTest extends TestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    private DebugEntityListenersDoctrineCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $application   = new Application();
        $this->command = new DebugEntityListenersDoctrineCommand($this->getMockManagerRegistry());

        self::addCommandToApplication($application, $this->command);
    }

    public function testExecute(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(
            ['command' => $this->command->getName(), 'entity' => self::class],
        );

        self::assertSame(<<<'TXT'

Entity listeners for Doctrine\Tests\ORM\Tools\Console\Command\Debug\DebugEntityListenersDoctrineCommandTest
===========================================================================================================

 ------------- ------- ------------------------------------------------------------------------------------ 
  Event         Order   Listener                                                                            
 ------------- ------- ------------------------------------------------------------------------------------ 
  postPersist   #1      Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BazListener::postPersist()  
 ------------- ------- ------------------------------------------------------------------------------------ 
  preUpdate     #1      Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\FooListener::preUpdate()    
                #2      Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BarListener::__invoke()     
 ------------- ------- ------------------------------------------------------------------------------------ 


TXT
            , $commandTester->getDisplay(true));
    }

    public function testExecuteWithEvent(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(
            ['command' => $this->command->getName(), 'entity' => self::class, 'event' => 'postPersist'],
        );

        self::assertSame(<<<'TXT'

Entity listeners for Doctrine\Tests\ORM\Tools\Console\Command\Debug\DebugEntityListenersDoctrineCommandTest
===========================================================================================================

 ------------- ------- ------------------------------------------------------------------------------------ 
  Event         Order   Listener                                                                            
 ------------- ------- ------------------------------------------------------------------------------------ 
  postPersist   #1      Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BazListener::postPersist()  
 ------------- ------- ------------------------------------------------------------------------------------ 


TXT
            , $commandTester->getDisplay(true));
    }

    public function testExecuteWithMissingEvent(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(
            ['command' => $this->command->getName(), 'entity' => self::class, 'event' => 'preRemove'],
        );

        self::assertUnwrappedDisplayContainsString(
            '[INFO] No listeners are configured for the "preRemove" event.',
            $commandTester,
        );
    }

    /**
     * @param list<string> $args
     * @param list<string> $expectedSuggestions
     */
    #[TestWith([['console'], 1, [self::class]])]
    #[TestWith([['console', self::class], 2, ['preUpdate', 'postPersist']])]
    #[TestWith([['console', 'NonExistentEntity'], 2, []])]
    public function testComplete(array $args, int $currentIndex, array $expectedSuggestions): void
    {
        $input = CompletionInput::fromTokens($args, $currentIndex);
        $input->bind($this->command->getDefinition());
        $suggestions = new CompletionSuggestions();

        $this->command->complete($input, $suggestions);

        self::assertSame($expectedSuggestions, array_map(static fn (Suggestion $suggestion) => $suggestion->getValue(), $suggestions->getValueSuggestions()));
    }

    /** @return Stub&ManagerRegistry */
    private function getMockManagerRegistry(): ManagerRegistry
    {
        $mappingDriverStub = $this->createStub(MappingDriver::class);
        $mappingDriverStub->method('getAllClassNames')->willReturn([self::class]);

        $config = new Configuration();
        $config->setMetadataDriverImpl($mappingDriverStub);

        $classMetadata = new ClassMetadata(self::class);
        $classMetadata->addEntityListener('preUpdate', FooListener::class, 'preUpdate');
        $classMetadata->addEntityListener('preUpdate', BarListener::class, '__invoke');
        $classMetadata->addEntityListener('postPersist', BazListener::class, 'postPersist');

        $emStub = $this->createStub(EntityManagerInterface::class);
        $emStub->method('getConfiguration')->willReturn($config);
        $emStub->method('getClassMetadata')->willReturn($classMetadata);

        $doctrineStub = $this->createStub(ManagerRegistry::class);
        $doctrineStub->method('getManagerNames')->willReturn(['default' => 'entity_manager.default']);
        $doctrineStub->method('getManager')->willReturn($emStub);
        $doctrineStub->method('getManagerForClass')->willReturn($emStub);

        return $doctrineStub;
    }
}
