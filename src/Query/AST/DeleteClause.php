<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * DeleteClause ::= "DELETE" ["FROM"] AbstractSchemaName [["AS"] AliasIdentificationVariable]
 *
 * @link    www.doctrine-project.org
 */
class DeleteClause extends Node
{
    public string $aliasIdentificationVariable;

    public function __construct(public string $abstractSchemaName)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkDeleteClause($this);
    }
}
