<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Common\Collections\Expr\CompositeExpression;
use Doctrine\Common\Collections\Expr\ExpressionVisitor;
use Doctrine\Common\Collections\Expr\Value;
use Override;
use RuntimeException;

use function count;
use function str_replace;
use function str_starts_with;

/**
 * Converts Collection expressions to Query expressions.
 */
class QueryExpressionVisitor extends ExpressionVisitor
{
    private const array OPERATOR_MAP = [
        Comparison::GT => Expr\Comparison::GT,
        Comparison::GTE => Expr\Comparison::GTE,
        Comparison::LT  => Expr\Comparison::LT,
        Comparison::LTE => Expr\Comparison::LTE,
    ];

    private readonly Expr $expr;

    /** @var list<mixed> */
    private array $parameters = [];

    /** @param mixed[] $queryAliases */
    public function __construct(
        private readonly array $queryAliases,
    ) {
        $this->expr = new Expr();
    }

    /**
     * Gets bound parameters.
     * Filled after {@link dispach()}.
     *
     * @return ArrayCollection<int, mixed>
     */
    public function getParameters(): ArrayCollection
    {
        return new ArrayCollection($this->parameters);
    }

    public function clearParameters(): void
    {
        $this->parameters = [];
    }

    /**
     * Converts Criteria expression to Query one based on static map.
     */
    private static function convertComparisonOperator(string $criteriaOperator): string|null
    {
        return self::OPERATOR_MAP[$criteriaOperator] ?? null;
    }

    #[Override]
    public function walkCompositeExpression(CompositeExpression $expr): mixed
    {
        $expressionList = [];

        foreach ($expr->getExpressionList() as $child) {
            $expressionList[] = $child->visit($this);
        }

        return match ($expr->getType()) {
            CompositeExpression::TYPE_AND => new Expr\Andx($expressionList),
            CompositeExpression::TYPE_OR => new Expr\Orx($expressionList),
            CompositeExpression::TYPE_NOT => $this->expr->not($expressionList[0]),
            default => throw new RuntimeException('Unknown composite ' . $expr->getType()),
        };
    }

    #[Override]
    public function walkComparison(Comparison $comparison): mixed
    {
        if (! isset($this->queryAliases[0])) {
            throw new QueryException('No aliases are set before invoking walkComparison().');
        }

        $field = $this->queryAliases[0] . '.' . $comparison->getField();

        foreach ($this->queryAliases as $alias) {
            if (str_starts_with($comparison->getField() . '.', $alias . '.')) {
                $field = $comparison->getField();
                break;
            }
        }

        $parameterName = str_replace('.', '_', $comparison->getField());

        foreach ($this->parameters as $parameter) {
            if ($parameter->getName() === $parameterName) {
                $parameterName .= '_' . count($this->parameters);
                break;
            }
        }

        $parameter   = new Parameter($parameterName, $this->walkValue($comparison->getValue()));
        $placeholder = ':' . $parameterName;

        switch ($comparison->getOperator()) {
            case Comparison::IN:
                $this->parameters[] = $parameter;

                return $this->expr->in($field, $placeholder);

            case Comparison::NIN:
                $this->parameters[] = $parameter;

                return $this->expr->notIn($field, $placeholder);

            case Comparison::EQ:
            case Comparison::IS:
                if ($this->walkValue($comparison->getValue()) === null) {
                    return $this->expr->isNull($field);
                }

                $this->parameters[] = $parameter;

                return $this->expr->eq($field, $placeholder);

            case Comparison::NEQ:
                if ($this->walkValue($comparison->getValue()) === null) {
                    return $this->expr->isNotNull($field);
                }

                $this->parameters[] = $parameter;

                return $this->expr->neq($field, $placeholder);

            case Comparison::CONTAINS:
                $parameter->setValue('%' . $parameter->getValue() . '%', $parameter->getType());
                $this->parameters[] = $parameter;

                return $this->expr->like($field, $placeholder);

            case Comparison::MEMBER_OF:
                return $this->expr->isMemberOf($comparison->getField(), $comparison->getValue()->getValue());

            case Comparison::STARTS_WITH:
                $parameter->setValue($parameter->getValue() . '%', $parameter->getType());
                $this->parameters[] = $parameter;

                return $this->expr->like($field, $placeholder);

            case Comparison::ENDS_WITH:
                $parameter->setValue('%' . $parameter->getValue(), $parameter->getType());
                $this->parameters[] = $parameter;

                return $this->expr->like($field, $placeholder);

            default:
                $operator = self::convertComparisonOperator($comparison->getOperator());
                if ($operator) {
                    $this->parameters[] = $parameter;

                    return new Expr\Comparison(
                        $field,
                        $operator,
                        $placeholder,
                    );
                }

                throw new RuntimeException('Unknown comparison operator: ' . $comparison->getOperator());
        }
    }

    #[Override]
    public function walkValue(Value $value): mixed
    {
        return $value->getValue();
    }
}
