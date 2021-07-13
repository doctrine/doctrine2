<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\Mapping\ClassMetadata;

use function array_diff;
use function array_keys;

/**
 * Represents a chain of tree walkers that modify an AST and finally emit output.
 * Only the last walker in the chain can emit output. Any previous walkers can modify
 * the AST to influence the final output produced by the last walker.
 */
class TreeWalkerChain implements TreeWalker
{
    /**
     * The tree walkers.
     *
     * @var TreeWalker[]
     * @psalm-var TreeWalkerChainIterator
     */
    private $_walkers;

    /**
     * The query components of the original query (the "symbol table") that was produced by the Parser.
     *
     * @var array<string, array<string, mixed>>
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
     * Returns the internal queryComponents array.
     *
     * {@inheritDoc}
     */
    public function getQueryComponents()
    {
        return $this->_queryComponents;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
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
     * {@inheritdoc}
     */
    public function __construct($query, $parserResult, array $queryComponents)
    {
        $this->_queryComponents = $queryComponents;
        $this->_walkers         = new TreeWalkerChainIterator($this, $query, $parserResult);
    }

    /**
     * Adds a tree walker to the chain.
     *
     * @param string $walkerClass The class of the walker to instantiate.
     *
     * @return void
     */
    public function addTreeWalker($walkerClass)
    {
        $this->_walkers[] = $walkerClass;
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSelectStatement(AST\SelectStatement $AST)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSelectStatement($AST);

            $this->_queryComponents = $walker->getQueryComponents();
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSelectClause($selectClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSelectClause($selectClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkFromClause($fromClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkFromClause($fromClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkFunction($function)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkFunction($function);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkOrderByClause($orderByClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkOrderByClause($orderByClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkOrderByItem($orderByItem)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkOrderByItem($orderByItem);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkHavingClause($havingClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkHavingClause($havingClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkJoin($join)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkJoin($join);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSelectExpression($selectExpression)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSelectExpression($selectExpression);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkQuantifiedExpression($qExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkQuantifiedExpression($qExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSubselect($subselect)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSubselect($subselect);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSubselectFromClause($subselectFromClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSubselectFromClause($subselectFromClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSimpleSelectClause($simpleSelectClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSimpleSelectClause($simpleSelectClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSimpleSelectExpression($simpleSelectExpression)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSimpleSelectExpression($simpleSelectExpression);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkAggregateExpression($aggExpression)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkAggregateExpression($aggExpression);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkGroupByClause($groupByClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkGroupByClause($groupByClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkGroupByItem($groupByItem)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkGroupByItem($groupByItem);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkUpdateStatement(AST\UpdateStatement $AST)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkUpdateStatement($AST);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkDeleteStatement(AST\DeleteStatement $AST)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkDeleteStatement($AST);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkDeleteClause(AST\DeleteClause $deleteClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkDeleteClause($deleteClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkUpdateClause($updateClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkUpdateClause($updateClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkUpdateItem($updateItem)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkUpdateItem($updateItem);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkWhereClause($whereClause)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkWhereClause($whereClause);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalExpression($condExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkConditionalExpression($condExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalTerm($condTerm)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkConditionalTerm($condTerm);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalFactor($factor)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkConditionalFactor($factor);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkConditionalPrimary($condPrimary)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkConditionalPrimary($condPrimary);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkExistsExpression($existsExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkExistsExpression($existsExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkCollectionMemberExpression($collMemberExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkCollectionMemberExpression($collMemberExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkEmptyCollectionComparisonExpression($emptyCollCompExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkEmptyCollectionComparisonExpression($emptyCollCompExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkNullComparisonExpression($nullCompExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkNullComparisonExpression($nullCompExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkInExpression($inExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkInExpression($inExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkInstanceOfExpression($instanceOfExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkInstanceOfExpression($instanceOfExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkLiteral($literal)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkLiteral($literal);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkBetweenExpression($betweenExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkBetweenExpression($betweenExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkLikeExpression($likeExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkLikeExpression($likeExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkStateFieldPathExpression($stateFieldPathExpression)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkStateFieldPathExpression($stateFieldPathExpression);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkComparisonExpression($compExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkComparisonExpression($compExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkInputParameter($inputParam)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkInputParameter($inputParam);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkArithmeticExpression($arithmeticExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkArithmeticExpression($arithmeticExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkArithmeticTerm($term)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkArithmeticTerm($term);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkStringPrimary($stringPrimary)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkStringPrimary($stringPrimary);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkArithmeticFactor($factor)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkArithmeticFactor($factor);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkSimpleArithmeticExpression($simpleArithmeticExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkSimpleArithmeticExpression($simpleArithmeticExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkPathExpression($pathExpr)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkPathExpression($pathExpr);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function walkResultVariable($resultVariable)
    {
        foreach ($this->_walkers as $walker) {
            $walker->walkResultVariable($resultVariable);
        }
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
