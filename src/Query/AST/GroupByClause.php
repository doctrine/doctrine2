<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

class GroupByClause extends Node
{
    /** @param mixed[] $groupByItems */
    public function __construct(public array $groupByItems)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkGroupByClause($this);
    }
}
