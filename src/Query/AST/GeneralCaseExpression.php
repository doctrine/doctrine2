<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * GeneralCaseExpression ::= "CASE" WhenClause {WhenClause}* "ELSE" ScalarExpression "END"
 *
 * @link    www.doctrine-project.org
 */
class GeneralCaseExpression extends Node
{
    /** @param mixed[] $whenClauses */
    public function __construct(
        public array $whenClauses,
        public mixed $elseScalarExpression = null,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkGeneralCaseExpression($this);
    }
}
