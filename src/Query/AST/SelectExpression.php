<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * SelectExpression ::= IdentificationVariable ["." "*"] | StateFieldPathExpression |
 *                      (AggregateExpression | "(" Subselect ")") [["AS"] ["HIDDEN"] FieldAliasIdentificationVariable]
 *
 * @link    www.doctrine-project.org
 */
class SelectExpression extends Node
{
    public function __construct(
        public mixed $expression,
        public string|null $fieldIdentificationVariable,
        public bool $hiddenAliasResultVariable = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkSelectExpression($this);
    }
}
