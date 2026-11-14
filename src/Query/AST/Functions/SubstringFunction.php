<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * "SUBSTRING" "(" StringPrimary "," SimpleArithmeticExpression "," SimpleArithmeticExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class SubstringFunction extends FunctionNode
{
    public Node $stringPrimary;

    public Node|string $firstSimpleArithmeticExpression;
    public Node|string|null $secondSimpleArithmeticExpression = null;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $optionalSecondSimpleArithmeticExpression = null;
        if ($this->secondSimpleArithmeticExpression !== null) {
            $optionalSecondSimpleArithmeticExpression = $sqlWalker->walkSimpleArithmeticExpression($this->secondSimpleArithmeticExpression);
        }

        return $sqlWalker->getConnection()->getDatabasePlatform()->getSubstringExpression(
            $sqlWalker->walkStringPrimary($this->stringPrimary),
            $sqlWalker->walkSimpleArithmeticExpression($this->firstSimpleArithmeticExpression),
            $optionalSecondSimpleArithmeticExpression,
        );
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(TokenType::T_COMMA);

        $this->firstSimpleArithmeticExpression = $parser->SimpleArithmeticExpression();

        $lexer = $parser->getLexer();
        if ($lexer->isNextToken(TokenType::T_COMMA)) {
            $parser->match(TokenType::T_COMMA);

            $this->secondSimpleArithmeticExpression = $parser->SimpleArithmeticExpression();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
