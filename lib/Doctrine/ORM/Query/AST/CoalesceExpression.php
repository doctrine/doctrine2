<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * CoalesceExpression ::= "COALESCE" "(" ScalarExpression {"," ScalarExpression}* ")"
 *
 * @link    www.doctrine-project.org
 */
class CoalesceExpression extends Node
{
    /** @var mixed[] */
    public $scalarExpressions = [];

    /**
     * @param mixed[] $scalarExpressions
     */
    public function __construct(array $scalarExpressions)
    {
        $this->scalarExpressions = $scalarExpressions;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkCoalesceExpression($this);
    }
}
