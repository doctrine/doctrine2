<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * SimpleSelectExpression ::= StateFieldPathExpression | IdentificationVariable
 *                          | (AggregateExpression [["AS"] FieldAliasIdentificationVariable])
 *
 * @link    www.doctrine-project.org
 */
class SimpleSelectExpression extends Node
{
    public string|null $fieldIdentificationVariable = null;

    public function __construct(public Node|string $expression)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkSimpleSelectExpression($this);
    }
}
