<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * SubselectFromClause ::= "FROM" SubselectIdentificationVariableDeclaration {"," SubselectIdentificationVariableDeclaration}*
 *
 * @link    www.doctrine-project.org
 */
class SubselectFromClause extends Node
{
    /** @param mixed[] $identificationVariableDeclarations */
    public function __construct(public array $identificationVariableDeclarations)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkSubselectFromClause($this);
    }
}
