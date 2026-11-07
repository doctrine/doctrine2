<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters;

use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\Common\Collections\Expr\CompositeExpression;
use Doctrine\Common\Collections\Expr\ExpressionVisitor;
use Doctrine\Common\Collections\Expr\Value;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use RuntimeException;

use function implode;
use function in_array;
use function is_object;

/**
 * Visit Expressions and generate SQL WHERE conditions from them.
 */
class SqlExpressionVisitor extends ExpressionVisitor
{
    public function __construct(
        private readonly BasicEntityPersister $persister,
        private readonly ClassMetadata $classMetadata,
    ) {
    }

    /** Converts a comparison expression into the target query language output. */
    public function walkComparison(Comparison $comparison): string
    {
        $field = $comparison->getField();
        $value = $comparison->getValue()->getValue(); // shortcut for walkValue()

        if (
            isset($this->classMetadata->associationMappings[$field]) &&
            $value !== null &&
            ! is_object($value) &&
            ! in_array($comparison->getOperator(), [Comparison::IN, Comparison::NIN], true)
        ) {
            throw MatchingAssociationFieldRequiresObject::fromClassAndAssociation(
                $this->classMetadata->name,
                $field,
            );
        }

        return $this->persister->getSelectConditionStatementSQL($field, $value, null, $comparison->getOperator());
    }

    /**
     * Converts a composite expression into the target query language output.
     *
     * @throws RuntimeException
     */
    public function walkCompositeExpression(CompositeExpression $expr): string
    {
        $expressionList = [];

        foreach ($expr->getExpressionList() as $child) {
            $expressionList[] = $child->visit($this);
        }

        return match ($expr->getType()) {
            CompositeExpression::TYPE_AND => '(' . implode(' AND ', $expressionList) . ')',
            CompositeExpression::TYPE_OR => '(' . implode(' OR ', $expressionList) . ')',
            CompositeExpression::TYPE_NOT => 'NOT (' . $expressionList[0] . ')',
            default => throw new RuntimeException('Unknown composite ' . $expr->getType()),
        };
    }

    /**
     * Converts a value expression into the target query language part.
     */
    public function walkValue(Value $value): string
    {
        return '?';
    }
}
