<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * ArithmeticTerm ::= ArithmeticFactor {("*" | "/") ArithmeticFactor}*
 *
 * @link    www.doctrine-project.org
 */
class ArithmeticTerm extends Node
{
    /** @var mixed[] */
    public $arithmeticFactors;

    /**
     * @param mixed[] $arithmeticFactors
     */
    public function __construct(array $arithmeticFactors)
    {
        $this->arithmeticFactors = $arithmeticFactors;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkArithmeticTerm($this);
    }
}
