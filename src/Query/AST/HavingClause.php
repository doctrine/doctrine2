<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

class HavingClause extends Node
{
    public function __construct(public ConditionalExpression|Phase2OptimizableConditional $conditionalExpression)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkHavingClause($this);
    }
}
