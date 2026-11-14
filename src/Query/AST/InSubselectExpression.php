<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

class InSubselectExpression extends Node
{
    public function __construct(
        public ArithmeticExpression $expression,
        public Subselect $subselect,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkInSubselectExpression($this);
    }
}
