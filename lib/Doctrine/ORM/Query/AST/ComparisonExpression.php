<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

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
    /** @var Node */
    public $leftExpression;

    /** @var Node */
    public $rightExpression;

    /** @var string */
    public $operator;

    /**
     * @param Node   $leftExpr
     * @param string $operator
     * @param Node   $rightExpr
     */
    public function __construct($leftExpr, $operator, $rightExpr)
    {
        $this->leftExpression  = $leftExpr;
        $this->rightExpression = $rightExpr;
        $this->operator        = $operator;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkComparisonExpression($this);
    }
}
