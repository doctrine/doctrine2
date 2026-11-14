<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * SimpleWhenClause ::= "WHEN" ScalarExpression "THEN" ScalarExpression
 *
 * @link    www.doctrine-project.org
 */
class SimpleWhenClause extends Node
{
    public function __construct(
        public mixed $caseScalarExpression = null,
        public mixed $thenScalarExpression = null,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkWhenClauseExpression($this);
    }
}
