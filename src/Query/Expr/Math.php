<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use Override;
use Stringable;

/**
 * Expression class for DQL math statements.
 *
 * @link    www.doctrine-project.org
 */
class Math implements Stringable
{
    /**
     * Creates a mathematical expression with the given arguments.
     */
    public function __construct(
        protected mixed $leftExpr,
        protected string $operator,
        protected mixed $rightExpr,
    ) {
    }

    public function getLeftExpr(): mixed
    {
        return $this->leftExpr;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getRightExpr(): mixed
    {
        return $this->rightExpr;
    }

    #[Override]
    public function __toString(): string
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
