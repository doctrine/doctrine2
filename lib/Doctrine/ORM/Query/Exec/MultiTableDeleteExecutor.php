<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Utility\PersisterHelper;
use Throwable;

use function array_merge;
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
    /** @var string */
    private $_createTempTableSql;

    /** @var string */
    private $_dropTempTableSql;

    /** @var string */
    private $_insertSql;

    /**
     * Initializes a new <tt>MultiTableDeleteExecutor</tt>.
     *
     * Internal note: Any SQL construction and preparation takes place in the constructor for
     *                best performance. With a query cache the executor will be cached.
     *
     * @param DeleteStatement $AST       The root AST node of the DQL query.
     * @param SqlWalker       $sqlWalker The walker used for SQL generation from the AST.
     */
    public function __construct(AST\Node $AST, $sqlWalker)
    {
        $em            = $sqlWalker->getEntityManager();
        $conn          = $em->getConnection();
        $platform      = $conn->getDatabasePlatform();
        $quoteStrategy = $em->getConfiguration()->getQuoteStrategy();

        $primaryClass    = $em->getClassMetadata($AST->deleteClause->abstractSchemaName);
        $primaryDqlAlias = $AST->deleteClause->aliasIdentificationVariable;
        $rootClass       = $em->getClassMetadata($primaryClass->rootEntityName);

        $tempTable     = $platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $idColumnList  = implode(', ', $idColumnNames);

        // 1. Create an INSERT INTO temptable ... SELECT identifiers WHERE $AST->getWhereClause()
        $sqlWalker->setSQLTableAlias($primaryClass->getTableName(), 't0', $primaryDqlAlias);

        $this->_insertSql = 'INSERT INTO ' . $tempTable . ' (' . $idColumnList . ')'
                . ' SELECT t0.' . implode(', t0.', $idColumnNames);

        $rangeDecl         = new AST\RangeVariableDeclaration($primaryClass->name, $primaryDqlAlias);
        $fromClause        = new AST\FromClause([new AST\IdentificationVariableDeclaration($rangeDecl, null, [])]);
        $this->_insertSql .= $sqlWalker->walkFromClause($fromClause);

        // Append WHERE clause, if there is one.
        if ($AST->whereClause) {
            $this->_insertSql .= $sqlWalker->walkWhereClause($AST->whereClause);
        }

        // 2. Create ID subselect statement used in DELETE ... WHERE ... IN (subselect)
        $idSubselect = 'SELECT ' . $idColumnList . ' FROM ' . $tempTable;

        // 3. Create and store DELETE statements
        $classNames = array_merge($primaryClass->parentClasses, [$primaryClass->name], $primaryClass->subClasses);
        foreach (array_reverse($classNames) as $className) {
            $tableName              = $quoteStrategy->getTableName($em->getClassMetadata($className), $platform);
            $this->_sqlStatements[] = 'DELETE FROM ' . $tableName
                    . ' WHERE (' . $idColumnList . ') IN (' . $idSubselect . ')';
        }

        // 4. Store DDL for temporary identifier table.
        $columnDefinitions = [];
        foreach ($idColumnNames as $idColumnName) {
            $columnDefinitions[$idColumnName] = [
                'notnull' => true,
                'type'    => Type::getType(PersisterHelper::getTypeOfColumn($idColumnName, $rootClass, $em)),
            ];
        }

        $this->_createTempTableSql = $platform->getCreateTemporaryTableSnippetSQL() . ' ' . $tempTable . ' ('
                . $platform->getColumnDeclarationListSQL($columnDefinitions) . ')';
        $this->_dropTempTableSql   = $platform->getDropTemporaryTableSQL($tempTable);
    }

    /**
     * {@inheritDoc}
     *
     * @return int
     */
    public function execute(Connection $conn, array $params, array $types)
    {
        // Create temporary id table
        $conn->executeStatement($this->_createTempTableSql);

        try {
            // Insert identifiers
            $numDeleted = $conn->executeStatement($this->_insertSql, $params, $types);

            // Execute DELETE statements
            foreach ($this->_sqlStatements as $sql) {
                $conn->executeStatement($sql);
            }
        } catch (Throwable $exception) {
            // FAILURE! Drop temporary table to avoid possible collisions
            $conn->executeStatement($this->_dropTempTableSql);

            // Re-throw exception
            throw $exception;
        }

        // Drop temporary table
        $conn->executeStatement($this->_dropTempTableSql);

        return $numDeleted;
    }
}
