<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * InExpression ::= ArithmeticExpression ["NOT"] "IN" "(" (Literal {"," Literal}* | Subselect) ")"
 *
 * @link    www.doctrine-project.org
 */
class InExpression extends Node
{
    /** @var bool */
    public $not;

    /** @var ArithmeticExpression */
    public $expression;

    /** @var mixed[] */
    public $literals = [];

    /** @var Subselect|null */
    public $subselect;

    /**
     * @param ArithmeticExpression $expression
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
        return $sqlWalker->walkInExpression($this);
    }
}
