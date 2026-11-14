<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * RangeVariableDeclaration ::= AbstractSchemaName ["AS"] AliasIdentificationVariable
 *
 * @link    www.doctrine-project.org
 */
class RangeVariableDeclaration extends Node
{
    public function __construct(
        public string $abstractSchemaName,
        public string $aliasIdentificationVariable,
        public bool $isRoot = true,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkRangeVariableDeclaration($this);
    }
}
