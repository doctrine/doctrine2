<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function constant;
use function method_exists;

class DDC12272Test extends TestCase
{
    public function testColumnAliasUsesPlatformIdentifierFolding(): void
    {
        $foldingClass = 'Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding';

        if (! class_exists($foldingClass)) {
            self::markTestSkipped('The DBAL version does not expose identifier folding.');
        }

        $platform = $this->createMock(OraclePlatform::class);
        $platform->method('getMaxIdentifierLength')->willReturn(30);
        $platform->method('getUnquotedIdentifierFolding')->willReturn(
            constant($foldingClass . '::LOWER'),
        );

        self::assertSame(
            'country_0',
            (new DefaultQuoteStrategy())->getColumnAlias('country', 0, $platform),
        );
    }

    public function testColumnAliasUsesIdentifierFoldingForEveryPlatform(): void
    {
        if (! method_exists(SQLitePlatform::class, 'getUnquotedIdentifierFolding')) {
            self::markTestSkipped('The DBAL version does not expose identifier folding.');
        }

        $platform = $this->createMock(SQLitePlatform::class);
        $platform->method('getMaxIdentifierLength')->willReturn(30);
        $platform->method('getUnquotedIdentifierFolding')->willReturn(UnquotedIdentifierFolding::LOWER);

        self::assertSame(
            'country_0',
            (new DefaultQuoteStrategy())->getColumnAlias('COUNTRY', 0, $platform),
        );
    }

    public function testColumnAliasUsesLegacyFoldingWithoutIdentifierFoldingApi(): void
    {
        if (method_exists(OraclePlatform::class, 'getUnquotedIdentifierFolding')) {
            self::markTestSkipped('The DBAL version exposes identifier folding.');
        }

        $platform = $this->createMock(OraclePlatform::class);
        $platform->method('getMaxIdentifierLength')->willReturn(30);

        self::assertSame(
            'COUNTRY_0',
            (new DefaultQuoteStrategy())->getColumnAlias('country', 0, $platform),
        );
    }

    public function testColumnAliasUsesLegacyPostgreSQLFoldingWithoutIdentifierFoldingApi(): void
    {
        if (method_exists(PostgreSQLPlatform::class, 'getUnquotedIdentifierFolding')) {
            self::markTestSkipped('The DBAL version exposes identifier folding.');
        }

        $platform = $this->createMock(PostgreSQLPlatform::class);
        $platform->method('getMaxIdentifierLength')->willReturn(30);

        self::assertSame(
            'country_0',
            (new DefaultQuoteStrategy())->getColumnAlias('COUNTRY', 0, $platform),
        );
    }
}
