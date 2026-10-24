<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\Name;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Internal\Name\NameParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;

#[RequiresMethod(Schema::class, 'edit')]
class NameParserTest extends TestCase
{
    public function testUnqualified(): void
    {
        $name = NameParser::parseUnqualifiedName('"a""b"');

        self::assertTrue($name->getIdentifier()->isQuoted());
        self::assertSame('a"b', $name->getIdentifier()->getValue());
    }

    public function testBacktick(): void
    {
        $name = NameParser::parseUnqualifiedName('`a-b`');

        self::assertTrue($name->getIdentifier()->isQuoted());
        self::assertSame('a-b', $name->getIdentifier()->getValue());
    }

    public function testSquareBrackets(): void
    {
        $name = NameParser::parseOptionallyQualifiedName('[s].[a]]b]');

        self::assertSame('s', $name->getQualifier()?->getValue());
        self::assertTrue($name->getUnqualifiedName()->isQuoted());
        self::assertSame('a]b', $name->getUnqualifiedName()->getValue());
    }

    public function testOptionallyQualified(): void
    {
        $name = NameParser::parseOptionallyQualifiedName('s."t"');

        self::assertSame('s', $name->getQualifier()?->getValue());
        self::assertTrue($name->getUnqualifiedName()->isQuoted());
        self::assertSame('t', $name->getUnqualifiedName()->getValue());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        NameParser::parseOptionallyQualifiedName($input);
    }

    /** @return iterable<array{string}> */
    public static function invalidProvider(): iterable
    {
        yield [''];
        yield ['a.'];
        yield ['.a'];
        yield ['a..b'];
        yield ['a.b.c'];
        yield ['""'];
        yield ['"a'];
        yield ['`a'];
        yield ['``'];
        yield ['[a'];
        yield ['[]'];
        yield ['"a"b'];
        yield ['a b'];
    }
}
