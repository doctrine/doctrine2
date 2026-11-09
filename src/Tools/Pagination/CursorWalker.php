<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\ComparisonExpression;
use Doctrine\ORM\Query\AST\ConditionalExpression;
use Doctrine\ORM\Query\AST\ConditionalPrimary;
use Doctrine\ORM\Query\AST\ConditionalTerm;
use Doctrine\ORM\Query\AST\InputParameter;
use Doctrine\ORM\Query\AST\Literal;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\NullComparisonExpression;
use Doctrine\ORM\Query\AST\OrderByClause;
use Doctrine\ORM\Query\AST\OrderByItem;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\WhereClause;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use LogicException;

use function array_unshift;
use function assert;
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

        $nullsSortLast = $this->nullsSortLast();
        $condition     = $this->buildCursorCondition($orderByItems, $cursorParameters, $nullsSortLast);

        if ($condition === null) {
            return;
        }

        if (count($orderByItems) > 1) {
            $condition = $this->prependLeadingBound($orderByItems[0], $cursorParameters[$orderByItems[0]->paramKey] ?? null, $condition, $nullsSortLast);
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
     * A NULL boundary value and a nullable column are spelled out with IS NULL and
     * IS NOT NULL, since a comparison with NULL is never true; see beyondBoundary().
     *
     * @param list<CursorOrderByItem> $orderByItems
     * @param array<string, mixed>    $cursorParameters
     *
     * @throws QueryException
     */
    private function buildCursorCondition(array $orderByItems, array $cursorParameters, bool $nullsSortLast, int $index = 0): ConditionalExpression|null
    {
        if (! isset($orderByItems[$index])) {
            return null;
        }

        $orderByItem = $orderByItems[$index];
        $paramName   = $this->parameterName($index);
        $paramValue  = $cursorParameters[$orderByItem->paramKey] ?? null;

        // Security note: $paramKey is derived from the DQL ORDER BY AST, not from the
        // cursor payload. A tampered cursor can only influence the *values* used as pivot
        // points, not the columns being filtered. All values are bound via setParameter(),
        // so SQL injection is not possible. The worst a user can do is navigate to an
        // arbitrary position in the result set, while remaining bound by the original
        // query's WHERE constraints.
        if ($paramValue !== null) {
            $this->_getQuery()->setParameter($paramName, $paramValue);
        }

        $terms  = [];
        $beyond = $this->beyondBoundary($orderByItem, $orderByItem->direction->exclusiveOperator(), $paramName, $paramValue, $nullsSortLast);

        if ($beyond !== null) {
            $terms[] = new ConditionalTerm([$beyond]);
        }

        if ($index < count($orderByItems) - 1) {
            $nextCondition = $this->buildCursorCondition($orderByItems, $cursorParameters, $nullsSortLast, $index + 1);
            assert($nextCondition !== null);

            $equality = $paramValue === null
                ? new NullComparisonExpression($orderByItem->expression)
                : new ComparisonExpression($orderByItem->expression, '=', new InputParameter(':' . $paramName));

            $nextPrimary                        = new ConditionalPrimary();
            $nextPrimary->conditionalExpression = $nextCondition;

            $andPrimary                        = new ConditionalPrimary();
            $andPrimary->conditionalExpression = new ConditionalExpression([
                new ConditionalTerm([$this->simplePrimary($equality), $nextPrimary]),
            ]);

            $terms[] = new ConditionalTerm([$andPrimary]);
        }

        if ($terms === []) {
            // The boundary is a NULL on the last column, and every row beyond it would be
            // past the NULLs: there is none.
            $terms[] = new ConditionalTerm([
                $this->simplePrimary(new ComparisonExpression(new Literal(Literal::NUMERIC, '1'), '=', new Literal(Literal::NUMERIC, '0'))),
            ]);
        }

        return new ConditionalExpression($terms);
    }

    /**
     * The rows past the boundary on one column, or null if there are none:
     *
     * | boundary | rows beyond lie toward the NULLs | rows beyond lie away from them |
     * | -------- | -------------------------------- | ------------------------------ |
     * | NULL     | none                             | col IS NOT NULL                |
     * | :val     | col > :val OR col IS NULL        | col > :val                     |
     *
     * NULLs keep the platform's own position, so the ORDER BY stays a plain column order: after
     * every value on PostgreSQL, Oracle and DB2, before every value on the other platforms.
     * The IS NULL branch is only added for a column that can hold NULL.
     *
     * @throws QueryException
     */
    private function beyondBoundary(CursorOrderByItem $orderByItem, string $operator, string $paramName, mixed $paramValue, bool $nullsSortLast): ConditionalPrimary|null
    {
        $towardNulls = $nullsSortLast === ($orderByItem->direction === OrderDirection::Ascending);

        if ($paramValue === null) {
            return $towardNulls ? null : $this->simplePrimary(new NullComparisonExpression($orderByItem->expression, true));
        }

        $comparison = $this->simplePrimary(new ComparisonExpression($orderByItem->expression, $operator, new InputParameter(':' . $paramName)));

        if (! $towardNulls || ! $this->isNullable($orderByItem)) {
            return $comparison;
        }

        $primary                        = new ConditionalPrimary();
        $primary->conditionalExpression = new ConditionalExpression([
            new ConditionalTerm([$comparison]),
            new ConditionalTerm([$this->simplePrimary(new NullComparisonExpression($orderByItem->expression))]),
        ]);

        return $primary;
    }

    /**
     * Whether the column can hold NULL: a field or a join column the mapping allows to be
     * null, and anything on a joined alias, which an outer join can leave empty.
     */
    private function isNullable(CursorOrderByItem $orderByItem): bool
    {
        if (! $orderByItem->expression instanceof PathExpression || $orderByItem->metadata === null || $orderByItem->associationPath !== []) {
            return true;
        }

        $field    = $orderByItem->expression->field;
        $metadata = $orderByItem->metadata;

        if (isset($metadata->fieldMappings[$field])) {
            return $metadata->fieldMappings[$field]->nullable === true;
        }

        if (isset($metadata->associationMappings[$field]) && $metadata->associationMappings[$field]->isToOneOwningSide()) {
            foreach ($metadata->associationMappings[$field]->joinColumns as $joinColumn) {
                if ($joinColumn->nullable !== false) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function nullsSortLast(): bool
    {
        $platform = $this->_getQuery()->getEntityManager()->getConnection()->getDatabasePlatform();

        return $platform instanceof PostgreSQLPlatform || $platform instanceof OraclePlatform || $platform instanceof DB2Platform;
    }

    private function simplePrimary(Node $expression): ConditionalPrimary
    {
        $primary                              = new ConditionalPrimary();
        $primary->simpleConditionalExpression = $expression;

        return $primary;
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
     * depth; the bound gives them a range to seek to. A NULL boundary bounds the rows to
     * the NULLs when they lie beyond it, and is left out when every value does.
     *
     * @see https://use-the-index-luke.com/sql/partial-results/fetch-next-page#sb-equivalent-logic
     *
     * @throws QueryException
     */
    private function prependLeadingBound(CursorOrderByItem $orderByItem, mixed $paramValue, ConditionalExpression $condition, bool $nullsSortLast): ConditionalExpression
    {
        if ($paramValue === null) {
            if ($nullsSortLast !== ($orderByItem->direction === OrderDirection::Ascending)) {
                return $condition;
            }

            $boundPrimary = $this->simplePrimary(new NullComparisonExpression($orderByItem->expression));
        } else {
            $boundPrimary = $this->beyondBoundary($orderByItem, $orderByItem->direction->inclusiveOperator(), $this->parameterName(0), $paramValue, $nullsSortLast);
            assert($boundPrimary !== null);
        }

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
