<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\ORM\Internal\Query\TemporaryIdTable;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\SqlWalker;
use Throwable;

use function array_reverse;
use function implode;

/**
 * Executes the SQL statements for bulk DQL DELETE statements on classes in
 * Class Table Inheritance (JOINED).
 *
 * @link        http://www.doctrine-project.org
 */
class MultiTableDeleteExecutor extends AbstractSqlExecutor
{
    private readonly string $createTempTableSql;
    private readonly string $dropTempTableSql;
    private readonly string $insertSql;

    /**
     * Initializes a new <tt>MultiTableDeleteExecutor</tt>.
     *
     * Internal note: Any SQL construction and preparation takes place in the constructor for
     *                best performance. With a query cache the executor will be cached.
     *
     * @param DeleteStatement $AST       The root AST node of the DQL query.
     * @param SqlWalker       $sqlWalker The walker used for SQL generation from the AST.
     */
    public function __construct(AST\Node $AST, SqlWalker $sqlWalker)
    {
        $em            = $sqlWalker->getEntityManager();
        $conn          = $em->getConnection();
        $platform      = $conn->getDatabasePlatform();
        $quoteStrategy = $em->getConfiguration()->getQuoteStrategy();

        if ($conn instanceof PrimaryReadReplicaConnection) {
            $conn->ensureConnectedToPrimary();
        }

        $primaryClass    = $em->getClassMetadata($AST->deleteClause->abstractSchemaName);
        $primaryDqlAlias = $AST->deleteClause->aliasIdentificationVariable;
        $rootClass       = $em->getClassMetadata($primaryClass->rootEntityName);

        $tempTable     = $platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $idColumnList  = implode(', ', $idColumnNames);

        // 1. Create an INSERT INTO temptable ... SELECT identifiers WHERE $AST->getWhereClause()
        $sqlWalker->setSQLTableAlias($primaryClass->getTableName(), 't0', $primaryDqlAlias);

        $insertSql = 'INSERT INTO ' . $tempTable . ' (' . $idColumnList . ')'
                . ' SELECT t0.' . implode(', t0.', $idColumnNames);

        $rangeDecl  = new AST\RangeVariableDeclaration($primaryClass->name, $primaryDqlAlias);
        $fromClause = new AST\FromClause([new AST\IdentificationVariableDeclaration($rangeDecl, null, [])]);
        $insertSql .= $sqlWalker->walkFromClause($fromClause);

        // Append WHERE clause, if there is one.
        if ($AST->whereClause) {
            $insertSql .= $sqlWalker->walkWhereClause($AST->whereClause);
        }

        $this->insertSql = $insertSql;

        // 2. Create ID subselect statement used in DELETE ... WHERE ... IN (subselect)
        $idSubselect = 'SELECT ' . $idColumnList . ' FROM ' . $tempTable;

        // 3. Create and store DELETE statements
        $classNames = [...$primaryClass->parentClasses, ...[$primaryClass->name], ...$primaryClass->subClasses];
        foreach (array_reverse($classNames) as $className) {
            $tableName             = $quoteStrategy->getTableName($em->getClassMetadata($className), $platform);
            $this->sqlStatements[] = 'DELETE FROM ' . $tableName
                    . ' WHERE (' . $idColumnList . ') IN (' . $idSubselect . ')';
        }

        // 4. Store DDL for temporary identifier table.
        $this->createTempTableSql = TemporaryIdTable::getCreateSQL($tempTable, $rootClass, $em);
        $this->dropTempTableSql   = $platform->getDropTemporaryTableSQL($tempTable);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Connection $conn, array $params, array $types): int
    {
        // Create temporary id table
        $conn->executeStatement($this->createTempTableSql);

        try {
            // Insert identifiers
            $numDeleted = $conn->executeStatement($this->insertSql, $params, $types);

            // Execute DELETE statements
            foreach ($this->sqlStatements as $sql) {
                $conn->executeStatement($sql);
            }
        } catch (Throwable $exception) {
            // FAILURE! Drop temporary table to avoid possible collisions
            $conn->executeStatement($this->dropTempTableSql);

            // Re-throw exception
            throw $exception;
        }

        // Drop temporary table
        $conn->executeStatement($this->dropTempTableSql);

        return $numDeleted;
    }
}
