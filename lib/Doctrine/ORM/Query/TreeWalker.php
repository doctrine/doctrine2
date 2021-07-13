<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Interface for walkers of DQL ASTs (abstract syntax trees).
 */
interface TreeWalker
{
    /**
     * Initializes TreeWalker with important information about the ASTs to be walked.
     *
     * @param AbstractQuery $query           The parsed Query.
     * @param ParserResult  $parserResult    The result of the parsing process.
     * @param mixed[]       $queryComponents The query components (symbol table).
     */
    public function __construct($query, $parserResult, array $queryComponents);

    /**
     * Returns internal queryComponents array.
     *
     * @return array<string, array<string, mixed>>
     * @psalm-return array<string, array{
     *                   metadata: ClassMetadata,
     *                   parent: string,
     *                   relation: mixed[],
     *                   map: mixed,
     *                   nestingLevel: int,
     *                   token: array
     *               }>
     */
    public function getQueryComponents();

    /**
     * Sets or overrides a query component for a given dql alias.
     *
     * @param string               $dqlAlias       The DQL alias.
     * @param array<string, mixed> $queryComponent
     *
     * @return void
     */
    public function setQueryComponent($dqlAlias, array $queryComponent);

    /**
     * Walks down a SelectStatement AST node, thereby generating the appropriate SQL.
     *
     * @return string The SQL.
     */
    public function walkSelectStatement(AST\SelectStatement $AST);

    /**
     * Walks down a SelectClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\SelectClause $selectClause
     *
     * @return string The SQL.
     */
    public function walkSelectClause($selectClause);

    /**
     * Walks down a FromClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\FromClause $fromClause
     *
     * @return string The SQL.
     */
    public function walkFromClause($fromClause);

    /**
     * Walks down a FunctionNode AST node, thereby generating the appropriate SQL.
     *
     * @param AST\Functions\FunctionNode $function
     *
     * @return string The SQL.
     */
    public function walkFunction($function);

    /**
     * Walks down an OrderByClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\OrderByClause $orderByClause
     *
     * @return string The SQL.
     */
    public function walkOrderByClause($orderByClause);

    /**
     * Walks down an OrderByItem AST node, thereby generating the appropriate SQL.
     *
     * @param AST\OrderByItem $orderByItem
     *
     * @return string The SQL.
     */
    public function walkOrderByItem($orderByItem);

    /**
     * Walks down a HavingClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\HavingClause $havingClause
     *
     * @return string The SQL.
     */
    public function walkHavingClause($havingClause);

    /**
     * Walks down a Join AST node and creates the corresponding SQL.
     *
     * @param AST\Join $join
     *
     * @return string The SQL.
     */
    public function walkJoin($join);

    /**
     * Walks down a SelectExpression AST node and generates the corresponding SQL.
     *
     * @param AST\SelectExpression $selectExpression
     *
     * @return string The SQL.
     */
    public function walkSelectExpression($selectExpression);

    /**
     * Walks down a QuantifiedExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\QuantifiedExpression $qExpr
     *
     * @return string The SQL.
     */
    public function walkQuantifiedExpression($qExpr);

    /**
     * Walks down a Subselect AST node, thereby generating the appropriate SQL.
     *
     * @param AST\Subselect $subselect
     *
     * @return string The SQL.
     */
    public function walkSubselect($subselect);

    /**
     * Walks down a SubselectFromClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\SubselectFromClause $subselectFromClause
     *
     * @return string The SQL.
     */
    public function walkSubselectFromClause($subselectFromClause);

    /**
     * Walks down a SimpleSelectClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\SimpleSelectClause $simpleSelectClause
     *
     * @return string The SQL.
     */
    public function walkSimpleSelectClause($simpleSelectClause);

    /**
     * Walks down a SimpleSelectExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\SimpleSelectExpression $simpleSelectExpression
     *
     * @return string The SQL.
     */
    public function walkSimpleSelectExpression($simpleSelectExpression);

    /**
     * Walks down an AggregateExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\AggregateExpression $aggExpression
     *
     * @return string The SQL.
     */
    public function walkAggregateExpression($aggExpression);

    /**
     * Walks down a GroupByClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\GroupByClause $groupByClause
     *
     * @return string The SQL.
     */
    public function walkGroupByClause($groupByClause);

    /**
     * Walks down a GroupByItem AST node, thereby generating the appropriate SQL.
     *
     * @param AST\PathExpression|string $groupByItem
     *
     * @return string The SQL.
     */
    public function walkGroupByItem($groupByItem);

    /**
     * Walks down an UpdateStatement AST node, thereby generating the appropriate SQL.
     *
     * @return string The SQL.
     */
    public function walkUpdateStatement(AST\UpdateStatement $AST);

    /**
     * Walks down a DeleteStatement AST node, thereby generating the appropriate SQL.
     *
     * @return string The SQL.
     */
    public function walkDeleteStatement(AST\DeleteStatement $AST);

    /**
     * Walks down a DeleteClause AST node, thereby generating the appropriate SQL.
     *
     * @return string The SQL.
     */
    public function walkDeleteClause(AST\DeleteClause $deleteClause);

    /**
     * Walks down an UpdateClause AST node, thereby generating the appropriate SQL.
     *
     * @param AST\UpdateClause $updateClause
     *
     * @return string The SQL.
     */
    public function walkUpdateClause($updateClause);

    /**
     * Walks down an UpdateItem AST node, thereby generating the appropriate SQL.
     *
     * @param AST\UpdateItem $updateItem
     *
     * @return string The SQL.
     */
    public function walkUpdateItem($updateItem);

    /**
     * Walks down a WhereClause AST node, thereby generating the appropriate SQL.
     * WhereClause or not, the appropriate discriminator sql is added.
     *
     * @param AST\WhereClause $whereClause
     *
     * @return string The SQL.
     */
    public function walkWhereClause($whereClause);

    /**
     * Walk down a ConditionalExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ConditionalExpression $condExpr
     *
     * @return string The SQL.
     */
    public function walkConditionalExpression($condExpr);

    /**
     * Walks down a ConditionalTerm AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ConditionalTerm $condTerm
     *
     * @return string The SQL.
     */
    public function walkConditionalTerm($condTerm);

    /**
     * Walks down a ConditionalFactor AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ConditionalFactor $factor
     *
     * @return string The SQL.
     */
    public function walkConditionalFactor($factor);

    /**
     * Walks down a ConditionalPrimary AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ConditionalPrimary $primary
     *
     * @return string The SQL.
     */
    public function walkConditionalPrimary($primary);

    /**
     * Walks down an ExistsExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ExistsExpression $existsExpr
     *
     * @return string The SQL.
     */
    public function walkExistsExpression($existsExpr);

    /**
     * Walks down a CollectionMemberExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\CollectionMemberExpression $collMemberExpr
     *
     * @return string The SQL.
     */
    public function walkCollectionMemberExpression($collMemberExpr);

    /**
     * Walks down an EmptyCollectionComparisonExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\EmptyCollectionComparisonExpression $emptyCollCompExpr
     *
     * @return string The SQL.
     */
    public function walkEmptyCollectionComparisonExpression($emptyCollCompExpr);

    /**
     * Walks down a NullComparisonExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\NullComparisonExpression $nullCompExpr
     *
     * @return string The SQL.
     */
    public function walkNullComparisonExpression($nullCompExpr);

    /**
     * Walks down an InExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\InExpression $inExpr
     *
     * @return string The SQL.
     */
    public function walkInExpression($inExpr);

    /**
     * Walks down an InstanceOfExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\InstanceOfExpression $instanceOfExpr
     *
     * @return string The SQL.
     */
    public function walkInstanceOfExpression($instanceOfExpr);

    /**
     * Walks down a literal that represents an AST node, thereby generating the appropriate SQL.
     *
     * @param mixed $literal
     *
     * @return string The SQL.
     */
    public function walkLiteral($literal);

    /**
     * Walks down a BetweenExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\BetweenExpression $betweenExpr
     *
     * @return string The SQL.
     */
    public function walkBetweenExpression($betweenExpr);

    /**
     * Walks down a LikeExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\LikeExpression $likeExpr
     *
     * @return string The SQL.
     */
    public function walkLikeExpression($likeExpr);

    /**
     * Walks down a StateFieldPathExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\PathExpression $stateFieldPathExpression
     *
     * @return string The SQL.
     */
    public function walkStateFieldPathExpression($stateFieldPathExpression);

    /**
     * Walks down a ComparisonExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ComparisonExpression $compExpr
     *
     * @return string The SQL.
     */
    public function walkComparisonExpression($compExpr);

    /**
     * Walks down an InputParameter AST node, thereby generating the appropriate SQL.
     *
     * @param AST\InputParameter $inputParam
     *
     * @return string The SQL.
     */
    public function walkInputParameter($inputParam);

    /**
     * Walks down an ArithmeticExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\ArithmeticExpression $arithmeticExpr
     *
     * @return string The SQL.
     */
    public function walkArithmeticExpression($arithmeticExpr);

    /**
     * Walks down an ArithmeticTerm AST node, thereby generating the appropriate SQL.
     *
     * @param mixed $term
     *
     * @return string The SQL.
     */
    public function walkArithmeticTerm($term);

    /**
     * Walks down a StringPrimary that represents an AST node, thereby generating the appropriate SQL.
     *
     * @param mixed $stringPrimary
     *
     * @return string The SQL.
     */
    public function walkStringPrimary($stringPrimary);

    /**
     * Walks down an ArithmeticFactor that represents an AST node, thereby generating the appropriate SQL.
     *
     * @param mixed $factor
     *
     * @return string The SQL.
     */
    public function walkArithmeticFactor($factor);

    /**
     * Walks down an SimpleArithmeticExpression AST node, thereby generating the appropriate SQL.
     *
     * @param AST\SimpleArithmeticExpression $simpleArithmeticExpr
     *
     * @return string The SQL.
     */
    public function walkSimpleArithmeticExpression($simpleArithmeticExpr);

    /**
     * Walks down a PathExpression AST node, thereby generating the appropriate SQL.
     *
     * @param mixed $pathExpr
     *
     * @return string The SQL.
     */
    public function walkPathExpression($pathExpr);

    /**
     * Walks down a ResultVariable that represents an AST node, thereby generating the appropriate SQL.
     *
     * @param string $resultVariable
     *
     * @return string The SQL.
     */
    public function walkResultVariable($resultVariable);

    /**
     * Gets an executor that can be used to execute the result of this walker.
     *
     * @param AST\DeleteStatement|AST\UpdateStatement|AST\SelectStatement $AST
     *
     * @return Exec\AbstractSqlExecutor
     */
    public function getExecutor($AST);
}
