<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * IndexBy ::= "INDEX" "BY" SingleValuedPathExpression
 *
 * @link    www.doctrine-project.org
 */
class IndexBy extends Node
{
    public function __construct(public PathExpression $singleValuedPathExpression)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        $walker->walkIndexBy($this);

        return '';
    }
}
