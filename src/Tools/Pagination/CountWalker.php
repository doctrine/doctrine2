<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Query\AST\AggregateExpression;
use Doctrine\ORM\Query\AST\PathExpression;
use Doctrine\ORM\Query\AST\SelectExpression;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\TreeWalkerAdapter;
use Override;
use RuntimeException;

use function count;
use function reset;

/**
 * Replaces the selectClause of the AST with a COUNT statement.
 */
class CountWalker extends TreeWalkerAdapter
{
    /**
     * Distinct mode hint name.
     */
    final public const string HINT_DISTINCT = 'doctrine_paginator.distinct';

    #[Override]
    public function walkSelectStatement(SelectStatement $selectStatement): void
    {
        if ($selectStatement->havingClause) {
            throw new RuntimeException('Cannot count query that uses a HAVING clause. Use the output walkers for pagination');
        }

        // Get the root entity and alias from the AST fromClause
        $from = $selectStatement->fromClause->identificationVariableDeclarations;

        if (count($from) > 1) {
            throw new RuntimeException('Cannot count query which selects two FROM components, cannot make distinction');
        }

        $distinct = $this->_getQuery()->getHint(self::HINT_DISTINCT);

        $countPathExpressionOrLiteral = '*';
        if ($distinct) {
            $fromRoot            = reset($from);
            $rootAlias           = $fromRoot->rangeVariableDeclaration->aliasIdentificationVariable;
            $rootClass           = $this->getMetadataForDqlAlias($rootAlias);
            $identifierFieldName = $rootClass->getSingleIdentifierFieldName();

            $pathType = PathExpression::TYPE_STATE_FIELD;
            if (isset($rootClass->associationMappings[$identifierFieldName])) {
                $pathType = PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION;
            }

            $countPathExpressionOrLiteral       = new PathExpression(
                PathExpression::TYPE_STATE_FIELD | PathExpression::TYPE_SINGLE_VALUED_ASSOCIATION,
                $rootAlias,
                $identifierFieldName,
            );
            $countPathExpressionOrLiteral->type = $pathType;
        }

        $selectStatement->selectClause->selectExpressions = [
            new SelectExpression(
                new AggregateExpression('count', $countPathExpressionOrLiteral, $distinct),
                null,
            ),
        ];

        // ORDER BY is not needed, only increases query execution through unnecessary sorting.
        $selectStatement->orderByClause = null;
    }
}
