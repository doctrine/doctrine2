<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console\Command\Debug;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Console\ApplicationCompatibility;
use Doctrine\ORM\Tools\Console\Command\Debug\DebugEventManagerDoctrineCommand;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BarListener;
use Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\BazListener;
use Doctrine\Tests\ORM\Tools\Console\Command\Debug\Fixtures\FooListener;
use Doctrine\Tests\ORM\Tools\Console\CommandTesterAssertions;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;

class DebugEventManagerDoctrineCommandTest extends TestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    private DebugEventManagerDoctrineCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $application   = new Application();
        $this->command = new DebugEventManagerDoctrineCommand($this->getMockManagerRegistry());

        self::addCommandToApplication($application, $this->command);
    }

    public function testExecute(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(
            ['command' => $this->command->getName()],
        );

        self::assertSame(<<<'TXT'

Event listeners for default entity manager
==========================================

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
            ['command' => $this->command->getName(), 'event' => 'postPersist'],
        );

        self::assertSame(<<<'TXT'

Event listeners for default entity manager
==========================================

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
            ['command' => $this->command->getName(), 'event' => 'preRemove'],
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
    #[TestWith([['console'], 1, ['preUpdate', 'postPersist']])]
    #[TestWith([['console', '--em'], 1, ['default']])]
    public function testComplete(array $args, int $currentIndex, array $expectedSuggestions): void
    {
        $input = CompletionInput::fromTokens($args, $currentIndex);
        $input->bind($this->command->getDefinition());
        $suggestions = new CompletionSuggestions();

        $this->command->complete($input, $suggestions);

        self::assertSame($expectedSuggestions, array_map(static fn (Suggestion $suggestion) => $suggestion->getValue(), $suggestions->getValueSuggestions()));
    }

    /** @return MockObject&ManagerRegistry */
    private function getMockManagerRegistry(): ManagerRegistry
    {
        $eventManager = new EventManager();
        $eventManager->addEventListener('preUpdate', new FooListener());
        $eventManager->addEventListener('preUpdate', new BarListener());
        $eventManager->addEventListener('postPersist', new BazListener());

        $emStub = $this->createStub(EntityManagerInterface::class);
        $emStub->method('getEventManager')->willReturn($eventManager);

        $doctrineStub = $this->createStub(ManagerRegistry::class);
        $doctrineStub->method('getDefaultManagerName')->willReturn('default');
        $doctrineStub->method('getManager')->willReturn($emStub);
        $doctrineStub->method('getManagerNames')->willReturn(['default' => 'entity_manager.default']);

        return $doctrineStub;
    }
}
