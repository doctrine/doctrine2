<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ExistsExpression ::= ["NOT"] "EXISTS" "(" Subselect ")"
 *
 * @link    www.doctrine-project.org
 */
class ExistsExpression extends Node
{
    public function __construct(
        public Subselect $subselect,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkExistsExpression($this);
    }
}
