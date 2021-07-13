<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use function strtoupper;

/**
 * QuantifiedExpression ::= ("ALL" | "ANY" | "SOME") "(" Subselect ")"
 *
 * @link    www.doctrine-project.org
 */
class QuantifiedExpression extends Node
{
    /** @var string */
    public $type;

    /** @var Subselect */
    public $subselect;

    /**
     * @param Subselect $subselect
     */
    public function __construct($subselect)
    {
        $this->subselect = $subselect;
    }

    /**
     * @return bool
     */
    public function isAll()
    {
        return strtoupper($this->type) === 'ALL';
    }

    /**
     * @return bool
     */
    public function isAny()
    {
        return strtoupper($this->type) === 'ANY';
    }

    /**
     * @return bool
     */
    public function isSome()
    {
        return strtoupper($this->type) === 'SOME';
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkQuantifiedExpression($this);
    }
}
