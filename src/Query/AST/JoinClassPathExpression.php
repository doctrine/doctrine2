<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * JoinClassPathExpression ::= AbstractSchemaName ["AS"] AliasIdentificationVariable
 *
 * @link    www.doctrine-project.org
 */
class JoinClassPathExpression extends Node
{
    public function __construct(
        public mixed $abstractSchemaName,
        public mixed $aliasIdentificationVariable,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkJoinPathExpression($this);
    }
}
