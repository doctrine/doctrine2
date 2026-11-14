<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * CoalesceExpression ::= "COALESCE" "(" ScalarExpression {"," ScalarExpression}* ")"
 *
 * @link    www.doctrine-project.org
 */
class CoalesceExpression extends Node
{
    /** @param mixed[] $scalarExpressions */
    public function __construct(public array $scalarExpressions)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkCoalesceExpression($this);
    }
}
