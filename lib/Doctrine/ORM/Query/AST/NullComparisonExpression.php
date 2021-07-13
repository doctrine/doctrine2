<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * NullComparisonExpression ::= (SingleValuedPathExpression | InputParameter) "IS" ["NOT"] "NULL"
 *
 * @link    www.doctrine-project.org
 */
class NullComparisonExpression extends Node
{
    /** @var bool */
    public $not;

    /** @var Node */
    public $expression;

    /**
     * @param Node $expression
     */
    public function __construct($expression)
    {
        $this->expression = $expression;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkNullComparisonExpression($this);
    }
}
