<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ComparisonExpression ::= ArithmeticExpression ComparisonOperator ( QuantifiedExpression | ArithmeticExpression ) |
 *                          StringExpression ComparisonOperator (StringExpression | QuantifiedExpression) |
 *                          BooleanExpression ("=" | "<>" | "!=") (BooleanExpression | QuantifiedExpression) |
 *                          EnumExpression ("=" | "<>" | "!=") (EnumExpression | QuantifiedExpression) |
 *                          DatetimeExpression ComparisonOperator (DatetimeExpression | QuantifiedExpression) |
 *                          EntityExpression ("=" | "<>") (EntityExpression | QuantifiedExpression)
 *
 * @link    www.doctrine-project.org
 */
class ComparisonExpression extends Node
{
    public function __construct(
        public Node|string $leftExpression,
        public string $operator,
        public Node|string $rightExpression,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkComparisonExpression($this);
    }
}
