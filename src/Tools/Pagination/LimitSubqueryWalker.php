<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Internal\TypeProviderLocator;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\AggregateNode;
use Doctrine\ORM\Query\AST\Functions\IdentityFunction;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use RuntimeException;

use function count;
use function get_object_vars;
use function is_array;
use function is_string;
use function reset;

/**
 * Replaces the selectClause of the AST with a SELECT DISTINCT root.id equivalent.
 */
class LimitSubqueryWalker extends TreeWalkerAdapter
{
    public const IDENTIFIER_TYPE = 'doctrine_paginator.id.type';

    public const FORCE_DBAL_TYPE_CONVERSION = 'doctrine_paginator.scalar_result.force_dbal_type_conversion';

    /**
     * Counter for generating unique order column aliases.
     */
    private int $aliasCounter = 0;

    public function walkSelectStatement(SelectStatement $selectStatement): void
    {
        // Get the root entity and alias from the AST fromClause
        $from      = $selectStatement->fromClause->identificationVariableDeclarations;
        $fromRoot  = reset($from);
        $rootAlias = $fromRoot->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass = $this->getMetadataForDqlAlias($rootAlias);

        $this->validate($selectStatement);
        $identifier = $rootClass->getSingleIdentifierFieldName();

        if (isset($rootClass->associationMappings[$identifier])) {
            throw new RuntimeException('Paginating an entity with foreign key as identifier only works when using the Output Walkers. Pass $useOutputWalkers = true to the paginator constructor.');
        }

        $query = $this->_getQuery();

        $query->setHint(
            self::IDENTIFIER_TYPE,
            TypeProviderLocator::fromConnection($query->getEntityManager()->getConnection())->get($rootClass->fieldMappings[$identifier]->type),
        );

        $query->setHint(self::FORCE_DBAL_TYPE_CONVERSION, true);

        $pathExpression = new PathExpression(
            PathExpression::TYPE_STATE_FIELD | PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
            $rootAlias,
            $identifier,
        );

        $pathExpression->type = PathExpression::TYPE_STATE_FIELD;

        $selectStatement->selectClause->selectExpressions = [new SelectExpression($pathExpression, '_dctrn_id')];
        $selectStatement->selectClause->isDistinct        = ($query->getHints()[PaginatorInterface::HINT_ENABLE_DISTINCT] ?? true) === true;

        if (! isset($selectStatement->orderByClause)) {
            return;
        }

        $queryComponents = $this->getQueryComponents();
        foreach ($selectStatement->orderByClause->orderByItems as $item) {
            if ($item->expression instanceof PathExpression) {
                $selectStatement->selectClause->selectExpressions[] = new SelectExpression(
                    $this->createSelectExpressionItem($item->expression),
                    '_dctrn_ord' . $this->aliasCounter++,
                );

                continue;
            }

            if (is_string($item->expression) && isset($queryComponents[$item->expression])) {
                $qComp = $queryComponents[$item->expression];

                if (isset($qComp['resultVariable'])) {
                    $selectStatement->selectClause->selectExpressions[] = new SelectExpression(
                        $qComp['resultVariable'],
                        $item->expression,
                    );
                }

                continue;
            }

            if ($item->expression instanceof Node) {
                $selectStatement->selectClause->selectExpressions[] = new SelectExpression(
                    $item->expression,
                    '_dctrn_ord' . $this->aliasCounter++,
                );
            }
        }
    }

    /**
     * Validate the AST to ensure that this walker is able to properly manipulate it.
     */
    private function validate(SelectStatement $AST): void
    {
        // Prevent LimitSubqueryWalker from being used with queries that include
        // a limit, a fetched to-many join, and an order by condition that
        // references a column from the fetch joined table.
        $queryComponents = $this->getQueryComponents();
        $query           = $this->_getQuery();
        $from            = $AST->fromClause->identificationVariableDeclarations;
        $fromRoot        = reset($from);

        if (
            $query instanceof Query
            && $query->getMaxResults() !== null
            && $AST->orderByClause
            && count($fromRoot->joins)
        ) {
            foreach ($AST->orderByClause->orderByItems as $orderByItem) {
                $expression = $orderByItem->expression;

                if ($expression instanceof PathExpression) {
                    if ($this->referencesToManyAssociation($expression, $queryComponents)) {
                        throw new RuntimeException('Cannot select distinct identifiers from query with LIMIT and ORDER BY on a column from a fetch joined to-many association. Use output walkers.');
                    }

                    continue;
                }

                if (is_string($expression) && isset($queryComponents[$expression]['resultVariable'])) {
                    $expression = $queryComponents[$expression]['resultVariable'];
                }

                if ($this->referencesToManyAssociation($expression, $queryComponents)) {
                    throw new RuntimeException('Cannot select distinct identifiers from query with LIMIT and ORDER BY on an expression referencing a fetch joined to-many association. Use output walkers.');
                }
            }
        }
    }

    /**
     * Checks whether an ORDER BY expression contains a path expression that
     * references a fetch joined to-many association.
     *
     * The DQL AST has no expression visitor, and function nodes, including
     * custom ones, keep their operands in arbitrary public properties. Only
     * those public properties are inspected, so custom function nodes must
     * expose their operands as public properties to be checked.
     *
     * Aggregations are ignored on purpose: they reduce the joined rows to one
     * row per root entity in the grouped subquery, so they stay safe in a
     * DISTINCT select list.
     *
     * @param mixed[] $queryComponents
     */
    private function referencesToManyAssociation(mixed $value, array $queryComponents): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->referencesToManyAssociation($item, $queryComponents)) {
                    return true;
                }
            }

            return false;
        }

        if (! $value instanceof Node || $value instanceof AggregateNode) {
            return false;
        }

        if ($value instanceof PathExpression) {
            $queryComponent = $queryComponents[$value->identificationVariable] ?? null;

            return $queryComponent !== null
                && isset($queryComponent['parent'], $queryComponent['relation'])
                && $queryComponent['relation']->isToMany();
        }

        foreach (get_object_vars($value) as $property) {
            if ($this->referencesToManyAssociation($property, $queryComponents)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve either an IdentityFunction (IDENTITY(u.assoc)) or a state field (u.name).
     *
     * @return IdentityFunction|PathExpression
     */
    private function createSelectExpressionItem(PathExpression $pathExpression): Node
    {
        if ($pathExpression->type === PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION) {
            $identity = new IdentityFunction('identity');

            $identity->pathExpression = clone $pathExpression;

            return $identity;
        }

        return clone $pathExpression;
    }
}
