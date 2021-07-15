<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

/**
 * DeleteStatement = DeleteClause [WhereClause]
 *
 * @link    www.doctrine-project.org
 */
class DeleteStatement extends Node
{
    /** @var DeleteClause */
    public $deleteClause;

    /** @var WhereClause|null */
    public $whereClause;

    /**
     * @param DeleteClause $deleteClause
     */
    public function __construct($deleteClause)
    {
        $this->deleteClause = $deleteClause;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($sqlWalker)
    {
        return $sqlWalker->walkDeleteStatement($this);
    }
}
