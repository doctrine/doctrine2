<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * OrderByClause ::= "ORDER" "BY" OrderByItem {"," OrderByItem}*
 *
 * @link    www.doctrine-project.org
 */
class OrderByClause extends Node
{
    /** @var OrderByItem[] */
    public $orderByItems = [];

    /**
     * @param OrderByItem[] $orderByItems
     */
    public function __construct(array $orderByItems)
    {
        $this->orderByItems = $orderByItems;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkOrderByClause($this);
    }
}
