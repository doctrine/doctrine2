<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Executor that executes the SQL statements for DQL DELETE/UPDATE statements on classes
 * that are mapped to a single table.
 *
 * @link        www.doctrine-project.org
 *
 * @todo This is exactly the same as SingleSelectExecutor. Unify in SingleStatementExecutor.
 */
class SingleTableDeleteUpdateExecutor extends AbstractSqlExecutor
{
    /**
     * @param SqlWalker $sqlWalker
     */
    public function __construct(AST\Node $AST, $sqlWalker)
    {
        if ($AST instanceof AST\UpdateStatement) {
            $this->_sqlStatements = $sqlWalker->walkUpdateStatement($AST);
        } elseif ($AST instanceof AST\DeleteStatement) {
            $this->_sqlStatements = $sqlWalker->walkDeleteStatement($AST);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return int
     */
    public function execute(Connection $conn, array $params, array $types)
    {
        return $conn->executeStatement($this->_sqlStatements, $params, $types);
    }
}
