<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * IndexBy ::= "INDEX" "BY" SingleValuedPathExpression
 *
 * @link    www.doctrine-project.org
 */
class IndexBy extends Node
{
    /** @var PathExpression */
    public $singleValuedPathExpression = null;

    /**
     * @deprecated
     *
     * @var PathExpression
     */
    public $simpleStateFieldPathExpression = null;

    public function __construct(PathExpression $singleValuedPathExpression)
    {
        $this->singleValuedPathExpression = $this->simpleStateFieldPathExpression = $singleValuedPathExpression;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkIndexBy($this);
    }
}
