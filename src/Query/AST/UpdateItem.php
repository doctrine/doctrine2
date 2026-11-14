<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * UpdateItem ::= [IdentificationVariable "."] {StateField | SingleValuedAssociationField} "=" NewValue
 * NewValue ::= SimpleArithmeticExpression | StringPrimary | DatetimePrimary | BooleanPrimary |
 *              EnumPrimary | SimpleEntityExpression | "NULL"
 *
 * @link    www.doctrine-project.org
 */
class UpdateItem extends Node
{
    public function __construct(public PathExpression $pathExpression, public InputParameter|ArithmeticExpression|null $newValue)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkUpdateItem($this);
    }
}
