<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\Expr\GroupBy;

class GroupByClause extends Node
{
    /** @var mixed[] */
    public $groupByItems = [];

    /**
     * @param mixed[] $groupByItems
     */
    public function __construct(array $groupByItems)
    {
        $this->groupByItems = $groupByItems;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkGroupByClause($this);
    }
}
