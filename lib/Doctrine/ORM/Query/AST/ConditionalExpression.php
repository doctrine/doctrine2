<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * ConditionalExpression ::= ConditionalTerm {"OR" ConditionalTerm}*
 *
 * @link    www.doctrine-project.org
 */
class ConditionalExpression extends Node
{
    /** @var mixed[] */
    public $conditionalTerms = [];

    /**
     * @param mixed[] $conditionalTerms
     */
    public function __construct(array $conditionalTerms)
    {
        $this->conditionalTerms = $conditionalTerms;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkConditionalExpression($this);
    }
}
