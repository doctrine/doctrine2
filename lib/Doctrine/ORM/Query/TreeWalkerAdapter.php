<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\ClassMetadata;

use function array_diff;
use function array_keys;

/**
 * An adapter implementation of the TreeWalker interface. The methods in this class
 * are empty. This class exists as convenience for creating tree walkers.
 */
abstract class TreeWalkerAdapter implements TreeWalker
{
    /**
     * The original Query.
     *
     * @var AbstractQuery
     */
    private $_query;

    /**
     * The ParserResult of the original query that was produced by the Parser.
     *
     * @var ParserResult
     */
    private $_parserResult;

    /**
     * The query components of the original query (the "symbol table") that was produced by the Parser.
     *
     * @psalm-var array<string, array{
     *                metadata: ClassMetadata,
     *                parent: string,
     *                relation: mixed[],
     *                map: mixed,
     *                nestingLevel: int,
     *                token: array
     *            }>
     */
    private $_queryComponents;

    /**
     * {@inheritdoc}
     */
    public function __construct($query, $parserResult, array $queryComponents)
    {
        $this->_query           = $query;
        $this->_parserResult    = $parserResult;
        $this->_queryComponents = $queryComponents;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryComponents()
    {
        return $this->_queryComponents;
    }

    /**
     * {@inheritdoc}
     */
    public function setQueryComponent($dqlAlias, array $queryComponent)
    {
        $requiredKeys = ['metadata', 'parent', 'relation', 'map', 'nestingLevel', 'token'];

        if (array_diff($requiredKeys, array_keys($queryComponent))) {
            throw QueryException::invalidQueryComponent($dqlAlias);
        }

        $this->_queryComponents[$dqlAlias] = $queryComponent;
    }

    /**
     * {@inheritDoc}
     */
    protected function _getQueryComponents()
    {
        return $this->_queryComponents;
    }

    /**
     * Retrieves the Query Instance responsible for the current walkers execution.
     *
     * @return AbstractQuery
     */
    protected function _getQuery()
    {
        return $this->_query;
    }

    /**
     * Retrieves the ParserResult.
     *
     * @return ParserResult
     */
    protected function _getParserResult()
    {
        return $this->_parserResult;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSelectStatement(AST\SelectStatement $AST)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSelectClause($selectClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkFromClause($fromClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkFunction($function)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkOrderByClause($orderByClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkOrderByItem($orderByItem)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkHavingClause($havingClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkJoin($join)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSelectExpression($selectExpression)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkQuantifiedExpression($qExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSubselect($subselect)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSubselectFromClause($subselectFromClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSimpleSelectClause($simpleSelectClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSimpleSelectExpression($simpleSelectExpression)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkAggregateExpression($aggExpression)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkGroupByClause($groupByClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkGroupByItem($groupByItem)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkUpdateStatement(AST\UpdateStatement $AST)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkDeleteStatement(AST\DeleteStatement $AST)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkDeleteClause(AST\DeleteClause $deleteClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkUpdateClause($updateClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkUpdateItem($updateItem)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkWhereClause($whereClause)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalExpression($condExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalTerm($condTerm)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalFactor($factor)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalPrimary($primary)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkExistsExpression($existsExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkCollectionMemberExpression($collMemberExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkEmptyCollectionComparisonExpression($emptyCollCompExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkNullComparisonExpression($nullCompExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkInExpression($inExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkInstanceOfExpression($instanceOfExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkLiteral($literal)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkBetweenExpression($betweenExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkLikeExpression($likeExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkStateFieldPathExpression($stateFieldPathExpression)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkComparisonExpression($compExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkInputParameter($inputParam)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkArithmeticExpression($arithmeticExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkArithmeticTerm($term)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkStringPrimary($stringPrimary)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkArithmeticFactor($factor)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSimpleArithmeticExpression($simpleArithmeticExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkPathExpression($pathExpr)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkResultVariable($resultVariable)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function getExecutor($AST)
    {
    }
}
