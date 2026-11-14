<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Console;

use Composer\InstalledVersions;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\Tools\Console\EntityManagerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

#[CoversClass(ConsoleRunner::class)]
#[Group('DDC-3186')]
final class ConsoleRunnerTest extends TestCase
{
    public function testCreateApplicationShouldReturnAnApplicationWithTheCorrectCommands(): void
    {
        $app = ConsoleRunner::createApplication($this->createStub(EntityManagerProvider::class));

        self::assertSame(InstalledVersions::getVersion('doctrine/orm'), $app->getVersion());

        self::assertTrue($app->has('dbal:run-sql'));
        self::assertTrue($app->has('orm:clear-cache:region:collection'));
        self::assertTrue($app->has('orm:clear-cache:region:entity'));
        self::assertTrue($app->has('orm:clear-cache:region:query'));
        self::assertTrue($app->has('orm:clear-cache:metadata'));
        self::assertTrue($app->has('orm:clear-cache:query'));
        self::assertTrue($app->has('orm:clear-cache:result'));
        self::assertTrue($app->has('orm:info'));
        self::assertTrue($app->has('orm:mapping:describe'));
        self::assertTrue($app->has('orm:run-dql'));
        self::assertTrue($app->has('orm:schema-tool:create'));
        self::assertTrue($app->has('orm:schema-tool:drop'));
        self::assertTrue($app->has('orm:schema-tool:update'));
        self::assertTrue($app->has('orm:validate-schema'));
    }

    public function testCreateApplicationShouldAppendGivenCommands(): void
    {
        $command = 'my:lovely-command';
        $app     = ConsoleRunner::createApplication($this->createStub(EntityManagerProvider::class), [new Command($command)]);

        self::assertTrue($app->has($command));
    }
}
