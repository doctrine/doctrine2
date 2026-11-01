<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\AST\ComparisonExpression;
use Doctrine\ORM\Query\AST\ConditionalExpression;
use Doctrine\ORM\Query\AST\ConditionalPrimary;
use Doctrine\ORM\Query\AST\ConditionalTerm;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\OrderByClause;
use Doctrine\ORM\Query\AST\OrderByItem;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\WhereClause;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use Doctrine\ORM\Utility\PersisterHelper;
use LogicException;

use function array_unshift;
use function count;
use function reset;

/**
 * @internal
 *
 * TreeWalker for cursor-based pagination.
 *
 * Responsibilities:
 * - Extract ORDER BY columns from AST
 * - Inject WHERE conditions for cursor navigation
 * - Reverse ORDER BY direction for previous page navigation
 */
class CursorWalker extends TreeWalkerAdapter
{
    public const HINT_CURSOR_PARAMETERS         = 'doctrine.cursor.parameters';
    public const HINT_CURSOR_REVERSE            = 'doctrine.cursor.reverse';
    public const HINT_CURSOR_ORDER_BY_ITEMS     = 'doctrine.cursor.order_by_items';
    public const HINT_QUERY_PRODUCES_DUPLICATES = 'doctrine.cursor.query_produces_duplicates';

    /**
     * Prefix of the parameters bound for the cursor predicate. Names are
     * namespaced so that they cannot collide with the parameters of the
     * paginated query, whose values would then be silently overwritten.
     */
    private const CURSOR_PARAMETER_PREFIX = 'dctrn_cursor_';

    public function walkSelectStatement(SelectStatement $selectStatement): void
    {
        $query            = $this->_getQuery();
        $shouldReverse    = $query->getHint(self::HINT_CURSOR_REVERSE) === true;
        $cursorParameters = $query->getHint(self::HINT_CURSOR_PARAMETERS);

        if (! isset($selectStatement->orderByClause)) {
            throw new LogicException('No ORDER BY clause found. Cursor pagination requires a deterministic sort order.');
        }

        $from      = $selectStatement->fromClause->identificationVariableDeclarations;
        $rootAlias = reset($from)->rangeVariableDeclaration->aliasIdentificationVariable;

        $orderByItems    = [];
        $newOrderByItems = [];

        foreach ($selectStatement->orderByClause->orderByItems as $orderByItem) {
            $direction = OrderDirection::fromOrderByItem($orderByItem, $shouldReverse);

            $paramKey        = $this->getParameterKey($orderByItem->expression);
            $metadata        = null;
            $associationPath = [];

            if ($orderByItem->expression instanceof PathExpression) {
                $metadata        = $this->getMetadataForDqlAlias($orderByItem->expression->identificationVariable);
                $associationPath = $this->getAssociationPath($orderByItem->expression->identificationVariable, $rootAlias);
            }

            $orderByItems[] = new CursorOrderByItem($orderByItem->expression, $direction, $paramKey, $metadata, $associationPath);

            $newItem           = new OrderByItem($orderByItem->expression);
            $newItem->type     = $direction->value;
            $newOrderByItems[] = $newItem;
        }

        $selectStatement->orderByClause = new OrderByClause($newOrderByItems);

        $query->setHint(self::HINT_CURSOR_ORDER_BY_ITEMS, $orderByItems);

        if (empty($cursorParameters)) {
            return;
        }

        $condition = $this->buildCursorCondition($orderByItems, $cursorParameters);

        if ($condition === null) {
            return;
        }

        if (count($orderByItems) > 1) {
            $condition = $this->prependLeadingBound($orderByItems[0], $condition);
        }

        $conditionalPrimary                        = new ConditionalPrimary();
        $conditionalPrimary->conditionalExpression = $condition;

        if ($selectStatement->whereClause !== null) {
            if ($selectStatement->whereClause->conditionalExpression instanceof ConditionalTerm) {
                $selectStatement->whereClause->conditionalExpression->conditionalFactors[] = $conditionalPrimary;
            } elseif ($selectStatement->whereClause->conditionalExpression instanceof ConditionalPrimary) {
                $selectStatement->whereClause->conditionalExpression = new ConditionalExpression(
                    [
                        new ConditionalTerm(
                            [
                                $selectStatement->whereClause->conditionalExpression,
                                $conditionalPrimary,
                            ],
                        ),
                    ],
                );
            } else {
                $existingPrimary                                     = new ConditionalPrimary();
                $existingPrimary->conditionalExpression              = $selectStatement->whereClause->conditionalExpression;
                $selectStatement->whereClause->conditionalExpression = new ConditionalTerm(
                    [
                        $existingPrimary,
                        $conditionalPrimary,
                    ],
                );
            }
        } else {
            $selectStatement->whereClause = new WhereClause(
                new ConditionalExpression(
                    [new ConditionalTerm([$conditionalPrimary])],
                ),
            );
        }
    }

    /**
     * Recursively builds a cursor condition:
     * (col1 > :val1) OR (col1 = :val1 AND col2 > :val2) OR ...
     *
     * @param list<CursorOrderByItem> $orderByItems
     * @param array<string, mixed>    $cursorParameters
     *
     * @throws QueryException
     */
    private function buildCursorCondition(array $orderByItems, array $cursorParameters, int $index = 0): ConditionalExpression|null
    {
        if (! isset($orderByItems[$index])) {
            return null;
        }

        $orderByItem = $orderByItems[$index];
        $expression  = $orderByItem->expression;
        $direction   = $orderByItem->direction;
        $paramKey    = $orderByItem->paramKey;

        $operator = $direction->exclusiveOperator();

        $paramName  = $this->parameterName($index);
        $paramValue = $cursorParameters[$paramKey] ?? null;

        // Security note: $paramKey is derived from the DQL ORDER BY AST, not from the
        // cursor payload. A tampered cursor can only influence the *values* used as pivot
        // points, not the columns being filtered. All values are bound via setParameter(),
        // so SQL injection is not possible. The worst a user can do is navigate to an
        // arbitrary position in the result set, while remaining bound by the original
        // query's WHERE constraints.
        $this->_getQuery()->setParameter($paramName, $paramValue, $this->getFieldType($orderByItem)?->getBindingType());

        $comparisonExpr = new ComparisonExpression(
            $expression,
            $operator,
            new InputParameter(':' . $paramName),
        );

        $comparisonPrimary                              = new ConditionalPrimary();
        $comparisonPrimary->simpleConditionalExpression = $comparisonExpr;

        if ($index === count($orderByItems) - 1) {
            return new ConditionalExpression([new ConditionalTerm([$comparisonPrimary])]);
        }

        $nextCondition = $this->buildCursorCondition($orderByItems, $cursorParameters, $index + 1);

        $equalityExpr = new ComparisonExpression(
            $expression,
            '=',
            new InputParameter(':' . $paramName),
        );

        $equalityPrimary                              = new ConditionalPrimary();
        $equalityPrimary->simpleConditionalExpression = $equalityExpr;

        $nextPrimary                        = new ConditionalPrimary();
        $nextPrimary->conditionalExpression = $nextCondition;

        $andPrimary                        = new ConditionalPrimary();
        $andPrimary->conditionalExpression = new ConditionalExpression([
            new ConditionalTerm([$equalityPrimary, $nextPrimary]),
        ]);

        return new ConditionalExpression([
            new ConditionalTerm([$comparisonPrimary]),
            new ConditionalTerm([$andPrimary]),
        ]);
    }

    /**
     * Returns the DBAL type of the field an ORDER BY item is on, if any.
     *
     * Cursor values are already converted for the database, so only the binding
     * type is used: the type itself would convert them again. Bound untyped, a
     * binary value is sent as a string, which PostgreSQL among others rejects.
     */
    private function getFieldType(CursorOrderByItem $orderByItem): Type|null
    {
        if (! $orderByItem->expression instanceof PathExpression || $orderByItem->metadata === null) {
            return null;
        }

        $types = PersisterHelper::getTypeOfField(
            $orderByItem->expression->field,
            $orderByItem->metadata,
            $this->_getQuery()->getEntityManager(),
        );

        return count($types) === 1 ? Type::getType($types[0]) : null;
    }

    private function parameterName(int $index): string
    {
        return self::CURSOR_PARAMETER_PREFIX . $index;
    }

    /**
     * ANDs a redundant bound on the first ORDER BY column in front of the cursor condition:
     * col1 >= :val1 AND ((col1 > :val1) OR (col1 = :val1 AND col2 > :val2) OR ...)
     *
     * It does not change which rows match, since every row matching the OR chain already
     * satisfies it. Some platforms (PostgreSQL among them) cannot derive an index range from
     * the OR chain and filter rows one by one instead, so a page's cost grows with its
     * depth; the bound gives them a range to seek to.
     *
     * @see https://use-the-index-luke.com/sql/partial-results/fetch-next-page#sb-equivalent-logic
     *
     * @throws QueryException
     */
    private function prependLeadingBound(CursorOrderByItem $orderByItem, ConditionalExpression $condition): ConditionalExpression
    {
        $boundPrimary                              = new ConditionalPrimary();
        $boundPrimary->simpleConditionalExpression = new ComparisonExpression(
            $orderByItem->expression,
            $orderByItem->direction->inclusiveOperator(),
            new InputParameter(':' . $this->parameterName(0)),
        );

        $conditionPrimary                        = new ConditionalPrimary();
        $conditionPrimary->conditionalExpression = $condition;

        return new ConditionalExpression([
            new ConditionalTerm([$boundPrimary, $conditionPrimary]),
        ]);
    }

    /**
     * Returns the single-valued associations to follow from the root alias to
     * the given alias, or null if the given alias cannot be reached that way
     * (e.g. it is joined through a collection, or not joined from the root alias).
     *
     * @return list<string>|null
     */
    private function getAssociationPath(string $dqlAlias, string $rootAlias): array|null
    {
        $associationPath = [];

        while ($dqlAlias !== $rootAlias) {
            $queryComponent = $this->getQueryComponents()[$dqlAlias];

            if (! isset($queryComponent['parent'], $queryComponent['relation']) || ! $queryComponent['relation']->isToOne()) {
                return null;
            }

            array_unshift($associationPath, $queryComponent['relation']->fieldName);
            $dqlAlias = $queryComponent['parent'];
        }

        return $associationPath;
    }

    /**
     * Returns the parameter key for the given expression.
     */
    private function getParameterKey(mixed $expression): string
    {
        if ($expression instanceof PathExpression) {
            return $expression->identificationVariable . '.' . $expression->field;
        }

        return (string) $expression;
    }
}
