<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

use function strtoupper;

/**
 * QuantifiedExpression ::= ("ALL" | "ANY" | "SOME") "(" Subselect ")"
 *
 * @link    www.doctrine-project.org
 */
class QuantifiedExpression extends Node
{
    public string $type;

    public function __construct(public Subselect $subselect)
    {
    }

    public function isAll(): bool
    {
        return strtoupper($this->type) === 'ALL';
    }

    public function isAny(): bool
    {
        return strtoupper($this->type) === 'ANY';
    }

    public function isSome(): bool
    {
        return strtoupper($this->type) === 'SOME';
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkQuantifiedExpression($this);
    }
}
