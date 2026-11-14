<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * NewObjectExpression ::= "NEW" IdentificationVariable "(" NewObjectArg {"," NewObjectArg}* ")"
 *
 * @link    www.doctrine-project.org
 */
class NewObjectExpression extends Node
{
    /**
     * @param class-string $className
     * @param mixed[]      $args
     */
    public function __construct(public string $className, public array $args)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker, string|null $parentAlias = null): string
    {
        return $walker->walkNewObject($this, $parentAlias);
    }
}
