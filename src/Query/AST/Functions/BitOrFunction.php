<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * "BIT_OR" "(" ArithmeticPrimary "," ArithmeticPrimary ")"
 *
 * @link    www.doctrine-project.org
 */
class BitOrFunction extends FunctionNode
{
    public Node $firstArithmetic;
    public Node $secondArithmetic;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()->getDatabasePlatform();

        return $platform->getBitOrComparisonExpression(
            $this->firstArithmetic->dispatch($sqlWalker),
            $this->secondArithmetic->dispatch($sqlWalker),
        );
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->firstArithmetic = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->secondArithmetic = $parser->ArithmeticPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
