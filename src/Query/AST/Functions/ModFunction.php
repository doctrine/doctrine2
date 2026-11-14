<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * "MOD" "(" SimpleArithmeticExpression "," SimpleArithmeticExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class ModFunction extends FunctionNode
{
    public Node|string $firstSimpleArithmeticExpression;
    public Node|string $secondSimpleArithmeticExpression;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return $sqlWalker->getConnection()->getDatabasePlatform()->getModExpression(
            $sqlWalker->walkSimpleArithmeticExpression($this->firstSimpleArithmeticExpression),
            $sqlWalker->walkSimpleArithmeticExpression($this->secondSimpleArithmeticExpression),
        );
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->firstSimpleArithmeticExpression = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_COMMA);

        $this->secondSimpleArithmeticExpression = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
