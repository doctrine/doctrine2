<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use LogicException;
use Override;

class SqlOutputWalker extends SqlWalker implements OutputWalker
{
    #[Override]
    public function getFinalizer(AST\DeleteStatement|AST\UpdateStatement|AST\SelectStatement $AST): SqlFinalizer
    {
        switch (true) {
            case $AST instanceof AST\SelectStatement:
                return new SingleSelectSqlFinalizer($this->createSqlForFinalizer($AST));

            case $AST instanceof AST\UpdateStatement:
                return new PreparedExecutorFinalizer($this->createUpdateStatementExecutor($AST));

            case $AST instanceof AST\DeleteStatement:
                return new PreparedExecutorFinalizer($this->createDeleteStatementExecutor($AST));
        }

        throw new LogicException('Unexpected AST node type');
    }
}
