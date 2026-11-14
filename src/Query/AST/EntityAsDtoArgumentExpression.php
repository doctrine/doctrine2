<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * EntityAsDtoArgumentExpression ::= IdentificationVariable
 *
 * @link    www.doctrine-project.org
 */
class EntityAsDtoArgumentExpression extends Node
{
    public function __construct(
        public mixed $expression,
        public string|null $identificationVariable,
        public string|null $aliasVariable = null,
    ) {
        if (! $aliasVariable) {
            $this->aliasVariable = $expression;
        }
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkEntityAsDtoArgumentExpression($this);
    }
}
