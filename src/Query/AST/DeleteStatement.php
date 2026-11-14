<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * DeleteStatement = DeleteClause [WhereClause]
 *
 * @link    www.doctrine-project.org
 */
class DeleteStatement extends Node
{
    public WhereClause|null $whereClause = null;

    public function __construct(public DeleteClause $deleteClause)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkDeleteStatement($this);
    }
}
