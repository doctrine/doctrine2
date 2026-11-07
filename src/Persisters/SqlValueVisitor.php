<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters;

use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Common\Collections\Expr\CompositeExpression;
use Doctrine\Common\Collections\Expr\ExpressionVisitor;

/**
 * Extract the values from a criteria/expression
 */
class SqlValueVisitor extends ExpressionVisitor
{
    use SqlValueVisitorImplementation;

    /** @var mixed[] */
    private array $values = [];

    /** @var mixed[][] */
    private array $types = [];

    private function doWalkComparison(Comparison $comparison): mixed
    {
        $value = $this->getValueFromComparison($comparison);

        $this->values[] = $value;
        $this->types[]  = [$comparison->getField(), $value, $comparison->getOperator()];

        return null;
    }

    private function doWalkCompositeExpression(CompositeExpression $expr): mixed
    {
        foreach ($expr->getExpressionList() as $child) {
            $child->visit($this);
        }

        return null;
    }

    /**
     * Returns the Parameters and Types necessary for matching the last visited expression.
     *
     * @return mixed[][]
     * @phpstan-return array{0: array, 1: array<array<mixed>>}
     */
    public function getParamsAndTypes(): array
    {
        return [$this->values, $this->types];
    }

    /**
     * Returns the value from a Comparison. In case of a CONTAINS comparison,
     * the value is wrapped in %-signs, because it will be used in a LIKE clause.
     */
    protected function getValueFromComparison(Comparison $comparison): mixed
    {
        $value = $comparison->getValue()->getValue();

        return match ($comparison->getOperator()) {
            Comparison::CONTAINS => '%' . $value . '%',
            Comparison::STARTS_WITH => $value . '%',
            Comparison::ENDS_WITH => '%' . $value,
            default => $value,
        };
    }
}
