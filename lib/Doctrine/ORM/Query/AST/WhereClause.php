<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * WhereClause ::= "WHERE" ConditionalExpression
 *
 * @link    www.doctrine-project.org
 */
class WhereClause extends Node
{
    /** @var ConditionalExpression|ConditionalTerm */
    public $conditionalExpression;

    /**
     * @param ConditionalExpression $conditionalExpression
     */
    public function __construct($conditionalExpression)
    {
        $this->conditionalExpression = $conditionalExpression;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkWhereClause($this);
    }
}
