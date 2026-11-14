<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ConditionalPrimary ::= SimpleConditionalExpression | "(" ConditionalExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class ConditionalPrimary extends Node implements Phase2OptimizableConditional
{
    public Node|null $simpleConditionalExpression = null;

    public ConditionalExpression|Phase2OptimizableConditional|null $conditionalExpression = null;

    public function isSimpleConditionalExpression(): bool
    {
        return (bool) $this->simpleConditionalExpression;
    }

    public function isConditionalExpression(): bool
    {
        return (bool) $this->conditionalExpression;
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkConditionalPrimary($this);
    }
}
