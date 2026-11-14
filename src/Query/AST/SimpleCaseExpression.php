<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * SimpleCaseExpression ::= "CASE" CaseOperand SimpleWhenClause {SimpleWhenClause}* "ELSE" ScalarExpression "END"
 *
 * @link    www.doctrine-project.org
 */
class SimpleCaseExpression extends Node
{
    /** @param mixed[] $simpleWhenClauses */
    public function __construct(
        public PathExpression|null $caseOperand = null,
        public array $simpleWhenClauses = [],
        public mixed $elseScalarExpression = null,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkSimpleCaseExpression($this);
    }
}
