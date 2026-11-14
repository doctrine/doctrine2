<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * IdentificationVariableDeclaration ::= RangeVariableDeclaration [IndexBy] {JoinVariableDeclaration}*
 *
 * @link    www.doctrine-project.org
 */
class IdentificationVariableDeclaration extends Node
{
    /** @param mixed[] $joins */
    public function __construct(
        public RangeVariableDeclaration|null $rangeVariableDeclaration = null,
        public IndexBy|null $indexBy = null,
        public array $joins = [],
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkIdentificationVariableDeclaration($this);
    }
}
