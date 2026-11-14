<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * UpdateClause ::= "UPDATE" AbstractSchemaName [["AS"] AliasIdentificationVariable] "SET" UpdateItem {"," UpdateItem}*
 *
 * @link    www.doctrine-project.org
 */
class UpdateClause extends Node
{
    public string $aliasIdentificationVariable;

    /** @param mixed[] $updateItems */
    public function __construct(
        public string $abstractSchemaName,
        public array $updateItems,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkUpdateClause($this);
    }
}
