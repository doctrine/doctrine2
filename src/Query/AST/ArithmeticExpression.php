<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ArithmeticExpression ::= SimpleArithmeticExpression | "(" Subselect ")"
 *
 * @link    www.doctrine-project.org
 */
class ArithmeticExpression extends Node
{
    public Node|string|null $simpleArithmeticExpression = null;

    public Subselect|null $subselect = null;

    public function isSimpleArithmeticExpression(): bool
    {
        return (bool) $this->simpleArithmeticExpression;
    }

    public function isSubselect(): bool
    {
        return (bool) $this->subselect;
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkArithmeticExpression($this);
    }
}
