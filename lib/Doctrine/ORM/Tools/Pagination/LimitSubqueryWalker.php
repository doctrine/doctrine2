<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST\Functions\IdentityFunction;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use RuntimeException;

use function count;
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
     *
     * @var int
     */
    private $_aliasCounter = 0;

    /**
     * Walks down a SelectStatement AST node, modifying it to retrieve DISTINCT ids
     * of the root Entity.
     *
     * @return void
     *
     * @throws RuntimeException
     */
    public function walkSelectStatement(SelectStatement $AST)
    {
        $queryComponents = $this->_getQueryComponents();
        // Get the root entity and alias from the AST fromClause
        $from      = $AST->fromClause->identificationVariableDeclarations;
        $fromRoot  = reset($from);
        $rootAlias = $fromRoot->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass = $queryComponents[$rootAlias]['metadata'];

        $this->validate($AST);
        $identifier = $rootClass->getSingleIdentifierFieldName();

        if (isset($rootClass->associationMappings[$identifier])) {
            throw new RuntimeException('Paginating an entity with foreign key as identifier only works when using the Output Walkers. Call Paginator#setUseOutputWalkers(true) before iterating the paginator.');
        }

        $this->_getQuery()->setHint(
            self::IDENTIFIER_TYPE,
            Type::getType($rootClass->fieldMappings[$identifier]['type'])
        );

        $this->_getQuery()->setHint(self::FORCE_DBAL_TYPE_CONVERSION, true);

        $pathExpression = new PathExpression(
            PathExpression::TYPE_STATE_FIELD | PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
            $rootAlias,
            $identifier
        );

        $pathExpression->type = PathExpression::TYPE_STATE_FIELD;

        $AST->selectClause->selectExpressions = [new SelectExpression($pathExpression, '_dctrn_id')];
        $AST->selectClause->isDistinct        = true;

        if (! isset($AST->orderByClause)) {
            return;
        }

        foreach ($AST->orderByClause->orderByItems as $item) {
            if ($item->expression instanceof PathExpression) {
                $AST->selectClause->selectExpressions[] = new SelectExpression(
                    $this->createSelectExpressionItem($item->expression),
                    '_dctrn_ord' . $this->_aliasCounter++
                );

                continue;
            }

            if (is_string($item->expression) && isset($queryComponents[$item->expression])) {
                $qComp = $queryComponents[$item->expression];

                if (isset($qComp['resultVariable'])) {
                    $AST->selectClause->selectExpressions[] = new SelectExpression(
                        $qComp['resultVariable'],
                        $item->expression
                    );
                }
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
            // Check each orderby item.
            // TODO: check complex orderby items too...
            foreach ($AST->orderByClause->orderByItems as $orderByItem) {
                $expression = $orderByItem->expression;
                if (
                    $orderByItem->expression instanceof PathExpression
                    && isset($queryComponents[$expression->identificationVariable])
                ) {
                    $queryComponent = $queryComponents[$expression->identificationVariable];
                    if (
                        isset($queryComponent['parent'])
                        && $queryComponent['relation']['type'] & ClassMetadataInfo::TO_MANY
                    ) {
                        throw new RuntimeException('Cannot select distinct identifiers from query with LIMIT and ORDER BY on a column from a fetch joined to-many association. Use output walkers.');
                    }
                }
            }
        }
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
