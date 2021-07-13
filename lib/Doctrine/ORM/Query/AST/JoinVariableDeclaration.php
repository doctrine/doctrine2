<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * JoinVariableDeclaration ::= Join [IndexBy]
 *
 * @link    www.doctrine-project.org
 */
class JoinVariableDeclaration extends Node
{
    /** @var Join */
    public $join;

    /** @var IndexBy|null */
    public $indexBy;

    /**
     * @param Join         $join
     * @param IndexBy|null $indexBy
     */
    public function __construct($join, $indexBy)
    {
        $this->join    = $join;
        $this->indexBy = $indexBy;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($walker)
    {
        return $walker->walkJoinVariableDeclaration($this);
    }
}
