<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\DBAL\Platforms\TrimMode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

use function assert;
use function strcasecmp;

/**
 * "TRIM" "(" [["LEADING" | "TRAILING" | "BOTH"] [char] "FROM"] StringPrimary ")"
 *
 * @link    www.doctrine-project.org
 */
class TrimFunction extends FunctionNode
{
    public bool $leading          = false;
    public bool $trailing         = false;
    public bool $both             = false;
    public string|false $trimChar = false;
    public Node $stringPrimary;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $stringPrimary = $sqlWalker->walkStringPrimary($this->stringPrimary);
        $platform      = $sqlWalker->getConnection()->getDatabasePlatform();
        $trimMode      = $this->getTrimMode();

        if ($this->trimChar !== false) {
            return $platform->getTrimExpression(
                $stringPrimary,
                $trimMode,
                $platform->quoteStringLiteral($this->trimChar),
            );
        }

        return $platform->getTrimExpression($stringPrimary, $trimMode);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $lexer = $parser->getLexer();

        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->parseTrimMode($parser);

        if ($lexer->isNextToken(TokenType::T_STRING)) {
            $parser->match(TokenType::T_STRING);

            assert($lexer->token !== null);
            $this->trimChar = $lexer->token->value;
        }

        if ($this->leading || $this->trailing || $this->both || ($this->trimChar !== false)) {
            $parser->match(TokenType::T_FROM);
        }

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    private function getTrimMode(): TrimMode
    {
        if ($this->leading) {
            return TrimMode::LEADING;
        }

        if ($this->trailing) {
            return TrimMode::TRAILING;
        }

        if ($this->both) {
            return TrimMode::BOTH;
        }

        return TrimMode::UNSPECIFIED;
    }

    private function parseTrimMode(Parser $parser): void
    {
        $lexer = $parser->getLexer();
        assert($lexer->lookahead !== null);
        $value = $lexer->lookahead->value;

        if (strcasecmp('leading', $value) === 0) {
            $parser->match(TokenType::T_LEADING);

            $this->leading = true;

            return;
        }

        if (strcasecmp('trailing', $value) === 0) {
            $parser->match(TokenType::T_TRAILING);

            $this->trailing = true;

            return;
        }

        if (strcasecmp('both', $value) === 0) {
            $parser->match(TokenType::T_BOTH);

            $this->both = true;

            return;
        }
    }
}
