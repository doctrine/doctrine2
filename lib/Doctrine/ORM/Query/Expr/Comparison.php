<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

/**
 * Expression class for DQL comparison expressions.
 *
 * @link    www.doctrine-project.org
 */
class Comparison
{
    public const EQ  = '=';
    public const NEQ = '<>';
    public const LT  = '<';
    public const LTE = '<=';
    public const GT  = '>';
    public const GTE = '>=';

    /** @var mixed */
    protected $leftExpr;

    /** @var string */
    protected $operator;

    /** @var mixed */
    protected $rightExpr;

    /**
     * Creates a comparison expression with the given arguments.
     *
     * @param mixed  $leftExpr
     * @param string $operator
     * @param mixed  $rightExpr
     */
    public function __construct($leftExpr, $operator, $rightExpr)
    {
        $this->leftExpr  = $leftExpr;
        $this->operator  = $operator;
        $this->rightExpr = $rightExpr;
    }

    /**
     * @return mixed
     */
    public function getLeftExpr()
    {
        return $this->leftExpr;
    }

    /**
     * @return string
     */
    public function getOperator()
    {
        return $this->operator;
    }

    /**
     * @return mixed
     */
    public function getRightExpr()
    {
        return $this->rightExpr;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->leftExpr . ' ' . $this->operator . ' ' . $this->rightExpr;
    }
}
