<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * NullIfExpression ::= "NULLIF" "(" ScalarExpression "," ScalarExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class NullIfExpression extends Node
{
    /** @var mixed */
    public $firstExpression;

    /** @var mixed */
    public $secondExpression;

    /**
     * @param mixed $firstExpression
     * @param mixed $secondExpression
     */
    public function __construct($firstExpression, $secondExpression)
    {
        $this->firstExpression  = $firstExpression;
        $this->secondExpression = $secondExpression;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkNullIfExpression($this);
    }
}
