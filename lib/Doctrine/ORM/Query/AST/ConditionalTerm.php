<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * ConditionalTerm ::= ConditionalFactor {"AND" ConditionalFactor}*
 *
 * @link    www.doctrine-project.org
 */
class ConditionalTerm extends Node
{
    /** @var mixed[] */
    public $conditionalFactors = [];

    /**
     * @param mixed[] $conditionalFactors
     */
    public function __construct(array $conditionalFactors)
    {
        $this->conditionalFactors = $conditionalFactors;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkConditionalTerm($this);
    }
}
