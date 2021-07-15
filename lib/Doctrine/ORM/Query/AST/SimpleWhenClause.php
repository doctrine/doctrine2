<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * SimpleWhenClause ::= "WHEN" ScalarExpression "THEN" ScalarExpression
 *
 * @link    www.doctrine-project.org
 */
class SimpleWhenClause extends Node
{
    /** @var mixed */
    public $caseScalarExpression = null;

    /** @var mixed */
    public $thenScalarExpression = null;

    /**
     * @param mixed $caseScalarExpression
     * @param mixed $thenScalarExpression
     */
    public function __construct($caseScalarExpression, $thenScalarExpression)
    {
        $this->caseScalarExpression = $caseScalarExpression;
        $this->thenScalarExpression = $thenScalarExpression;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkWhenClauseExpression($this);
    }
}
