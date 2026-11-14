<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ConditionalFactor ::= ["NOT"] ConditionalPrimary
 *
 * @link    www.doctrine-project.org
 */
class ConditionalFactor extends Node implements Phase2OptimizableConditional
{
    public function __construct(
        public ConditionalPrimary $conditionalPrimary,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkConditionalFactor($this);
    }
}
