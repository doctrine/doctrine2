<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

class BetweenExpression extends Node
{
    public function __construct(
        public ArithmeticExpression $expression,
        public ArithmeticExpression $leftBetweenExpression,
        public ArithmeticExpression $rightBetweenExpression,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkBetweenExpression($this);
    }
}
