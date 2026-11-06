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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandCompletionTester;
use Symfony\Component\Console\Tester\CommandTester;

use function json_decode;

/**
 * Tests for {@see \Doctrine\ORM\Tools\Console\Command\MappingDescribeCommand}
 */
#[CoversClass(MappingDescribeCommand::class)]
class MappingDescribeCommandTest extends OrmFunctionalTestCase
{
    use ApplicationCompatibility;
    use CommandTesterAssertions;

    private Application $application;

    private MappingDescribeCommand $command;

    private CommandTester $tester;

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
            ],
        );

        self::assertUnwrappedDisplayContainsString(AttractionInfo::class, $this->tester);
        self::assertUnwrappedDisplayContainsString('Root entity name', $this->tester);
    }

    public function testShowSpecificFuzzySingleJson(): void
    {
        $this->tester->execute([
            'command' => $this->command->getName(),
            'entityName' => 'AttractionInfo',
            '--format' => 'json',
        ]);

        $display     = $this->tester->getDisplay();
        $decodedJson = json_decode($display, true);

        self::assertJson($display);
        self::assertSame(AttractionInfo::class, $decodedJson['name']);
        self::assertArrayHasKey('rootEntityName', $decodedJson);
        self::assertArrayHasKey('fieldMappings', $decodedJson);
        self::assertArrayHasKey('associationMappings', $decodedJson);
        self::assertArrayHasKey('id', $decodedJson['fieldMappings']);
    }

    public function testShowSpecificFuzzyAmbiguous(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('possible matches');
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => 'Attraction',
            ],
        );
    }

    public function testShowSpecificNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find any mapped Entity classes matching "AttractionFooBar"');
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => 'AttractionFooBar',
            ],
        );
    }

    /**
     * @param string[] $input
     * @param string[] $expectedSuggestions
     */
    #[DataProvider('provideCompletionSuggestions')]
    public function testComplete(array $input, array $expectedSuggestions): void
    {
        $this->useModelSet('cache');

        parent::setUp();

        $completionTester = new CommandCompletionTester(new MappingDescribeCommand(new SingleManagerProvider($this->_em)));

        $suggestions = $completionTester->complete($input);

        foreach ($expectedSuggestions as $expected) {
            self::assertContains($expected, $suggestions);
        }
    }

    /** @return iterable<string, array{string[], string[]}> */
    public static function provideCompletionSuggestions(): iterable
    {
        yield 'entityName' => [
            [''],
            [
                'Doctrine\\\\Tests\\\\Models\\\\Cache\\\\Restaurant',
                'Doctrine\\\\Tests\\\\Models\\\\Cache\\\\Beach',
                'Doctrine\\\\Tests\\\\Models\\\\Cache\\\\Bar',
            ],
        ];

        yield 'format option value' => [
            ['--format='],
            ['text', 'json'],
        ];
    }

    public function testShowEntityWithEntityListeners(): void
    {
        $this->tester->execute(
            [
                'command'    => $this->command->getName(),
                'entityName' => CmsAddress::class,
            ],
        );

        self::assertUnwrappedDisplayContainsString(CmsAddress::class, $this->tester);
        self::assertUnwrappedDisplayContainsString('Entity listeners', $this->tester);
        self::assertUnwrappedDisplayContainsString('CmsAddressListener', $this->tester);
        self::assertUnwrappedDisplayContainsString('postPersist', $this->tester);
        self::assertUnwrappedDisplayContainsString('prePersist', $this->tester);
    }
}
