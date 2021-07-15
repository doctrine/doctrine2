<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * ArithmeticExpression ::= SimpleArithmeticExpression | "(" Subselect ")"
 *
 * @link    www.doctrine-project.org
 */
class ArithmeticExpression extends Node
{
    /** @var SimpleArithmeticExpression|null */
    public $simpleArithmeticExpression;

    /** @var Subselect|null */
    public $subselect;

    /**
     * @return bool
     */
    public function isSimpleArithmeticExpression()
    {
        return (bool) $this->simpleArithmeticExpression;
    }

    /**
     * @return bool
     */
    public function isSubselect()
    {
        return (bool) $this->subselect;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($walker)
    {
        return $walker->walkArithmeticExpression($this);
    }
}
