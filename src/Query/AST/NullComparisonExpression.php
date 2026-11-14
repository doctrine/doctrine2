<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * NullComparisonExpression ::= (SingleValuedPathExpression | InputParameter) "IS" ["NOT"] "NULL"
 *
 * @link    www.doctrine-project.org
 */
class NullComparisonExpression extends Node
{
    public function __construct(
        public Node|string $expression,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkNullComparisonExpression($this);
    }
}
