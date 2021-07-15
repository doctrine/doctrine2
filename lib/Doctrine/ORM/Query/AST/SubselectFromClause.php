<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * SubselectFromClause ::= "FROM" SubselectIdentificationVariableDeclaration {"," SubselectIdentificationVariableDeclaration}*
 *
 * @link    www.doctrine-project.org
 */
class SubselectFromClause extends Node
{
    /** @var mixed[] */
    public $identificationVariableDeclarations = [];

    /**
     * @param mixed[] $identificationVariableDeclarations
     */
    public function __construct(array $identificationVariableDeclarations)
    {
        $this->identificationVariableDeclarations = $identificationVariableDeclarations;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkSubselectFromClause($this);
    }
}
