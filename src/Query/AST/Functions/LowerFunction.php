<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST\Functions;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

use function sprintf;

/**
 * "LOWER" "(" StringPrimary ")"
 *
 * @link    www.doctrine-project.org
 */
class LowerFunction extends FunctionNode
{
    public Node $stringPrimary;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'LOWER(%s)',
            $sqlWalker->walkSimpleArithmeticExpression($this->stringPrimary),
        );
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
