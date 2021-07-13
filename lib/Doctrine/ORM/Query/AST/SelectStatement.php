<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * SelectStatement = SelectClause FromClause [WhereClause] [GroupByClause] [HavingClause] [OrderByClause]
 *
 * @link    www.doctrine-project.org
 */
class SelectStatement extends Node
{
    /** @var SelectClause */
    public $selectClause;

    /** @var FromClause */
    public $fromClause;

    /** @var WhereClause|null */
    public $whereClause;

    /** @var GroupByClause|null */
    public $groupByClause;

    /** @var HavingClause|null */
    public $havingClause;

    /** @var OrderByClause|null */
    public $orderByClause;

    /**
     * @param SelectClause $selectClause
     * @param FromClause   $fromClause
     */
    public function __construct($selectClause, $fromClause)
    {
        $this->selectClause = $selectClause;
        $this->fromClause   = $fromClause;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkSelectStatement($this);
    }
}
