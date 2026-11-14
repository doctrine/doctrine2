<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * Executor that executes the SQL statements for DQL DELETE/UPDATE statements on classes
 * that are mapped to a single table.
 *
 * @link        www.doctrine-project.org
 */
class SingleTableDeleteUpdateExecutor extends AbstractSqlExecutor
{
    public function __construct(AST\Node $AST, SqlWalker $sqlWalker)
    {
        if ($AST instanceof AST\UpdateStatement) {
            $this->sqlStatements = $sqlWalker->walkUpdateStatement($AST);
        } elseif ($AST instanceof AST\DeleteStatement) {
            $this->sqlStatements = $sqlWalker->walkDeleteStatement($AST);
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function execute(Connection $conn, array $params, array $types): int
    {
        if ($conn instanceof PrimaryReadReplicaConnection) {
            $conn->ensureConnectedToPrimary();
        }

        return $conn->executeStatement($this->sqlStatements, $params, $types);
    }
}
