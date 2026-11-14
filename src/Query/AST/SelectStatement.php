<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * SelectStatement = SelectClause FromClause [WhereClause] [GroupByClause] [HavingClause] [OrderByClause]
 *
 * @link    www.doctrine-project.org
 */
class SelectStatement extends Node
{
    public WhereClause|null $whereClause = null;

    public GroupByClause|null $groupByClause = null;

    public HavingClause|null $havingClause = null;

    public OrderByClause|null $orderByClause = null;

    public function __construct(public SelectClause $selectClause, public FromClause $fromClause)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkSelectStatement($this);
    }
}
