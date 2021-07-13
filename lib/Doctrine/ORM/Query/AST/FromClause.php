<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * FromClause ::= "FROM" IdentificationVariableDeclaration {"," IdentificationVariableDeclaration}
 *
 * @link    www.doctrine-project.org
 */
class FromClause extends Node
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
        return $sqlWalker->walkFromClause($this);
    }
}
