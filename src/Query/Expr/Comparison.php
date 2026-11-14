<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Expr;

use Override;
use Stringable;

/**
 * Expression class for DQL comparison expressions.
 *
 * @link    www.doctrine-project.org
 */
class Comparison implements Stringable
{
    final public const string EQ  = '=';
    final public const string NEQ = '<>';
    final public const string LT  = '<';
    final public const string LTE = '<=';
    final public const string GT  = '>';
    final public const string GTE = '>=';

    /** Creates a comparison expression with the given arguments. */
    public function __construct(protected mixed $leftExpr, protected string $operator, protected mixed $rightExpr)
    {
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
        return $this->leftExpr . ' ' . $this->operator . ' ' . $this->rightExpr;
    }
}
