<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

use function strtoupper;

/**
 * OrderByItem ::= (ResultVariable | StateFieldPathExpression) ["ASC" | "DESC"]
 *
 * @link    www.doctrine-project.org
 */
class OrderByItem extends Node
{
    public string $type;

    public function __construct(public mixed $expression)
    {
    }

    public function isAsc(): bool
    {
        return strtoupper($this->type) === 'ASC';
    }

    public function isDesc(): bool
    {
        return strtoupper($this->type) === 'DESC';
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkOrderByItem($this);
    }
}
