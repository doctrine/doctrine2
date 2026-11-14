<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * WhenClause ::= "WHEN" ConditionalExpression "THEN" ScalarExpression
 *
 * @link    www.doctrine-project.org
 */
class WhenClause extends Node
{
    public function __construct(
        public ConditionalExpression|Phase2OptimizableConditional $caseConditionExpression,
        public mixed $thenScalarExpression = null,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkWhenClauseExpression($this);
    }
}
