<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\Common\Lexer\Token;
use Doctrine\ORM\Query\Lexer;
use Doctrine\ORM\Query\TokenType;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class LexerTest extends OrmTestCase
{
    #[DataProvider('provideTokens')]
    public function testScannerRecognizesTokens($type, $value): void
    {
        $lexer = new Lexer($value);

        $lexer->moveNext();
        $token = $lexer->lookahead;

        self::assertEquals($type, $token->type);
        self::assertEquals($value, $token->value);
    }

    public function testScannerRecognizesTerminalString(): void
    {
        /*
         * "all" looks like an identifier, but in fact it's a reserved word
         * (a terminal string). It's up to the parser to accept it as an identifier
         * (with its literal value) when appropriate.
         */

        $lexer = new Lexer('all');

        $lexer->moveNext();
        $token = $lexer->lookahead;

        self::assertEquals(TokenType::T_ALL, $token->type);
    }

    public function testScannerRecognizesDecimalInteger(): void
    {
        $lexer = new Lexer('1234');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_INTEGER, $token->type);
        self::assertEquals(1234, $token->value);
    }

    public function testScannerRecognizesFloat(): void
    {
        $lexer = new Lexer('1.234');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_FLOAT, $token->type);
        self::assertEquals(1.234, $token->value);
    }

    public function testScannerRecognizesFloatWithExponent(): void
    {
        $lexer = new Lexer('1.2e3');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_FLOAT, $token->type);
        self::assertEquals(1.2e3, $token->value);
    }

    public function testScannerRecognizesFloatWithExponent2(): void
    {
        $lexer = new Lexer('0.2e3');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_FLOAT, $token->type);
        self::assertEquals(.2e3, $token->value);
    }

    public function testScannerRecognizesFloatWithNegativeExponent(): void
    {
        $lexer = new Lexer('7E-10');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_FLOAT, $token->type);
        self::assertEquals(7E-10, $token->value);
    }

    public function testScannerRecognizesFloatBig(): void
    {
        $lexer = new Lexer('123456789.01');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_FLOAT, $token->type);
        self::assertEquals(1.2345678901e8, $token->value);
    }

    public function testScannerRecognizesFloatContainingWhitespace(): void
    {
        $lexer = new Lexer('-   1.234e2');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_MINUS, $token->type);
        self::assertEquals('-', $token->value);

        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_FLOAT, $token->type);
        self::assertNotEquals(-1.234e2, $token->value);
        self::assertEquals(1.234e2, $token->value);
    }

    public function testScannerRecognizesStringContainingWhitespace(): void
    {
        $lexer = new Lexer("'This is a string.'");
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_STRING, $token->type);
        self::assertEquals('This is a string.', $token->value);
    }

    public function testScannerRecognizesStringContainingSingleQuotes(): void
    {
        $lexer = new Lexer("'abc''defg'''");
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_STRING, $token->type);
        self::assertEquals("abc'defg'", $token->value);
    }

    public function testScannerDoesNotRecognizeDoubleQuotedString(): void
    {
        $lexer = new Lexer('" "');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertNotEquals(TokenType::T_STRING, $token->type);
        self::assertSame('"', $token->value);
    }

    public function testScannerRecognizesInputParameter(): void
    {
        $lexer = new Lexer('?1');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_INPUT_PARAMETER, $token->type);
        self::assertEquals('?1', $token->value);
    }

    public function testScannerRecognizesNamedInputParameter(): void
    {
        $lexer = new Lexer(':name');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_INPUT_PARAMETER, $token->type);
        self::assertEquals(':name', $token->value);
    }

    public function testScannerRecognizesNamedInputParameterStartingWithUnderscore(): void
    {
        $lexer = new Lexer(':_name');
        $lexer->moveNext();
        $token = $lexer->lookahead;
        self::assertEquals(TokenType::T_INPUT_PARAMETER, $token->type);
        self::assertEquals(':_name', $token->value);
    }

    public function testScannerTokenizesASimpleQueryCorrectly(): void
    {
        $dql   = "SELECT u FROM My\Namespace\User u WHERE u.name = 'Jack O''Neil'";
        $lexer = new Lexer($dql);

        $tokens = [
            new Token('SELECT', TokenType::T_SELECT, 0),
            new Token('u', TokenType::T_IDENTIFIER, 7),
            new Token('FROM', TokenType::T_FROM, 9),
            new Token('My\Namespace\User', TokenType::T_FULLY_QUALIFIED_NAME, 14),
            new Token('u', TokenType::T_IDENTIFIER, 32),
            new Token('WHERE', TokenType::T_WHERE, 34),
            new Token('u', TokenType::T_IDENTIFIER, 40),
            new Token('.', TokenType::T_DOT, 41),
            new Token('name', TokenType::T_IDENTIFIER, 42),
            new Token('=', TokenType::T_EQUALS, 47),
            new Token("Jack O'Neil", TokenType::T_STRING, 49),
        ];

        foreach ($tokens as $expected) {
            $lexer->moveNext();
            $actual = $lexer->lookahead;
            self::assertEquals($expected->value, $actual->value);
            self::assertEquals($expected->type, $actual->type);
            self::assertEquals($expected->position, $actual->position);
        }

        self::assertFalse($lexer->moveNext());
    }

    /** @phpstan-return list<array{int, string}> */
    public static function provideTokens(): array
    {
        return [
            [TokenType::T_IDENTIFIER, 'u'], // one char
            [TokenType::T_IDENTIFIER, 'someIdentifier'],
            [TokenType::T_IDENTIFIER, 's0m31d3nt1f13r'], // including digits
            [TokenType::T_IDENTIFIER, 'some_identifier'], // including underscore
            [TokenType::T_IDENTIFIER, '_some_identifier'], // starts with underscore
            [TokenType::T_IDENTIFIER, 'comma'], // name of a token class with value < 100 (whitebox test)
            [TokenType::T_FULLY_QUALIFIED_NAME, 'Some\Class'], // DQL class reference
        ];
    }
}
