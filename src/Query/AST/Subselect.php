<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * Subselect ::= SimpleSelectClause SubselectFromClause [WhereClause] [GroupByClause] [HavingClause] [OrderByClause]
 *
 * @link    www.doctrine-project.org
 */
class Subselect extends Node
{
    public WhereClause|null $whereClause = null;

    public GroupByClause|null $groupByClause = null;

    public HavingClause|null $havingClause = null;

    public OrderByClause|null $orderByClause = null;

    public function __construct(public SimpleSelectClause $simpleSelectClause, public SubselectFromClause $subselectFromClause)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkSubselect($this);
    }
}
