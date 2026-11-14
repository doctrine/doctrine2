<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * NullIfExpression ::= "NULLIF" "(" ScalarExpression "," ScalarExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class NullIfExpression extends Node
{
    public function __construct(public mixed $firstExpression, public mixed $secondExpression)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkNullIfExpression($this);
    }
}
