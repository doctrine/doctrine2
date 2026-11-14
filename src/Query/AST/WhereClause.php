<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * WhereClause ::= "WHERE" ConditionalExpression
 *
 * @link    www.doctrine-project.org
 */
class WhereClause extends Node
{
    public function __construct(public ConditionalExpression|Phase2OptimizableConditional $conditionalExpression)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkWhereClause($this);
    }
}
