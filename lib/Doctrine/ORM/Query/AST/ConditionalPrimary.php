<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * ConditionalPrimary ::= SimpleConditionalExpression | "(" ConditionalExpression ")"
 *
 * @link    www.doctrine-project.org
 */
class ConditionalPrimary extends Node
{
    /** @var Node|null */
    public $simpleConditionalExpression;

    /** @var ConditionalExpression|null */
    public $conditionalExpression;

    /**
     * @return bool
     */
    public function isSimpleConditionalExpression()
    {
        return (bool) $this->simpleConditionalExpression;
    }

    /**
     * @return bool
     */
    public function isConditionalExpression()
    {
        return (bool) $this->conditionalExpression;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkConditionalPrimary($this);
    }
}
