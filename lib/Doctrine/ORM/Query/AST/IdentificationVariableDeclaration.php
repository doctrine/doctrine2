<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * IdentificationVariableDeclaration ::= RangeVariableDeclaration [IndexBy] {JoinVariableDeclaration}*
 *
 * @link    www.doctrine-project.org
 */
class IdentificationVariableDeclaration extends Node
{
    /** @var RangeVariableDeclaration|null */
    public $rangeVariableDeclaration = null;

    /** @var IndexBy|null */
    public $indexBy = null;

    /** @var mixed[] */
    public $joins = [];

    /**
     * @param RangeVariableDeclaration|null $rangeVariableDecl
     * @param IndexBy|null                  $indexBy
     * @param mixed[]                       $joins
     */
    public function __construct($rangeVariableDecl, $indexBy, array $joins)
    {
        $this->rangeVariableDeclaration = $rangeVariableDecl;
        $this->indexBy                  = $indexBy;
        $this->joins                    = $joins;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkIdentificationVariableDeclaration($this);
    }
}
