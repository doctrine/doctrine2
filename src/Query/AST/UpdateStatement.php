<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * UpdateStatement = UpdateClause [WhereClause]
 *
 * @link    www.doctrine-project.org
 */
class UpdateStatement extends Node
{
    public WhereClause|null $whereClause = null;

    public function __construct(public UpdateClause $updateClause)
    {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkUpdateStatement($this);
    }
}
