<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ParenthesisExpression ::= "(" ArithmeticPrimary ")"
 */
class ParenthesisExpression extends Node
{
    public function __construct(public Node $expression)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkParenthesisExpression($this);
    }
}
