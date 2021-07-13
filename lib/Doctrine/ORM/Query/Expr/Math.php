<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

/**
 * Expression class for DQL math statements.
 *
 * @link    www.doctrine-project.org
 */
class Math
{
    /** @var mixed */
    protected $leftExpr;

    /** @var string */
    protected $operator;

    /** @var mixed */
    protected $rightExpr;

    /**
     * Creates a mathematical expression with the given arguments.
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
        // Adjusting Left Expression
        $leftExpr = (string) $this->leftExpr;

        if ($this->leftExpr instanceof Math) {
            $leftExpr = '(' . $leftExpr . ')';
        }

        // Adjusting Right Expression
        $rightExpr = (string) $this->rightExpr;

        if ($this->rightExpr instanceof Math) {
            $rightExpr = '(' . $rightExpr . ')';
        }

        return $leftExpr . ' ' . $this->operator . ' ' . $rightExpr;
    }
}
