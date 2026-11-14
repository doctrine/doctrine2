<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\ORM\Internal\Query\TemporaryIdTable;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\Query\SqlWalker;

use function array_reverse;
use function array_slice;
use function implode;

/**
 * Executes the SQL statements for bulk DQL UPDATE statements on classes in
 * Class Table Inheritance (JOINED).
 */
class MultiTableUpdateExecutor extends AbstractSqlExecutor
{
    private readonly string $createTempTableSql;
    private readonly string $dropTempTableSql;
    private readonly string $insertSql;

    /** @var mixed[] */
    private array $sqlParameters             = [];
    private int $numParametersInUpdateClause = 0;

    /**
     * Initializes a new <tt>MultiTableUpdateExecutor</tt>.
     *
     * Internal note: Any SQL construction and preparation takes place in the constructor for
     *                best performance. With a query cache the executor will be cached.
     *
     * @param UpdateStatement $AST       The root AST node of the DQL query.
     * @param SqlWalker       $sqlWalker The walker used for SQL generation from the AST.
     */
    public function __construct(AST\Node $AST, SqlWalker $sqlWalker)
    {
        $em                  = $sqlWalker->getEntityManager();
        $conn                = $em->getConnection();
        $platform            = $conn->getDatabasePlatform();
        $quoteStrategy       = $em->getConfiguration()->getQuoteStrategy();
        $this->sqlStatements = [];

        if ($conn instanceof PrimaryReadReplicaConnection) {
            $conn->ensureConnectedToPrimary();
        }

        $updateClause = $AST->updateClause;
        $primaryClass = $sqlWalker->getEntityManager()->getClassMetadata($updateClause->abstractSchemaName);
        $rootClass    = $em->getClassMetadata($primaryClass->rootEntityName);

        $updateItems = $updateClause->updateItems;

        $tempTable     = $platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $idColumnList  = implode(', ', $idColumnNames);

        // 1. Create an INSERT INTO temptable ... SELECT identifiers WHERE $AST->getWhereClause()
        $sqlWalker->setSQLTableAlias($primaryClass->getTableName(), 't0', $updateClause->aliasIdentificationVariable);

        $insertSql = 'INSERT INTO ' . $tempTable . ' (' . $idColumnList . ')'
                . ' SELECT t0.' . implode(', t0.', $idColumnNames);

        $rangeDecl  = new AST\RangeVariableDeclaration($primaryClass->name, $updateClause->aliasIdentificationVariable);
        $fromClause = new AST\FromClause([new AST\IdentificationVariableDeclaration($rangeDecl, null, [])]);

        $insertSql .= $sqlWalker->walkFromClause($fromClause);

        // 2. Create ID subselect statement used in UPDATE ... WHERE ... IN (subselect)
        $idSubselect = 'SELECT ' . $idColumnList . ' FROM ' . $tempTable;

        // 3. Create and store UPDATE statements
        $classNames = [...$primaryClass->parentClasses, ...[$primaryClass->name], ...$primaryClass->subClasses];

        foreach (array_reverse($classNames) as $className) {
            $affected  = false;
            $class     = $em->getClassMetadata($className);
            $updateSql = 'UPDATE ' . $quoteStrategy->getTableName($class, $platform) . ' SET ';

            $sqlParameters = [];
            foreach ($updateItems as $updateItem) {
                $field = $updateItem->pathExpression->field;

                if (
                    (isset($class->fieldMappings[$field]) && ! isset($class->fieldMappings[$field]->inherited)) ||
                    (isset($class->associationMappings[$field]) && ! isset($class->associationMappings[$field]->inherited))
                ) {
                    $newValue = $updateItem->newValue;

                    if (! $affected) {
                        $affected = true;
                    } else {
                        $updateSql .= ', ';
                    }

                    $updateSql .= $sqlWalker->walkUpdateItem($updateItem);

                    if ($newValue instanceof AST\InputParameter) {
                        $sqlParameters[] = $newValue->name;

                        ++$this->numParametersInUpdateClause;
                    }
                }
            }

            if ($affected) {
                $this->sqlParameters[] = $sqlParameters;
                $this->sqlStatements[] = $updateSql . ' WHERE (' . $idColumnList . ') IN (' . $idSubselect . ')';
            }
        }

        // Append WHERE clause to insertSql, if there is one.
        if ($AST->whereClause) {
            $insertSql .= $sqlWalker->walkWhereClause($AST->whereClause);
        }

        $this->insertSql = $insertSql;

        // 4. Store DDL for temporary identifier table.
        $this->createTempTableSql = TemporaryIdTable::getCreateSQL($tempTable, $rootClass, $em);

        $this->dropTempTableSql = $platform->getDropTemporaryTableSQL($tempTable);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Connection $conn, array $params, array $types): int
    {
        // Create temporary id table
        $conn->executeStatement($this->createTempTableSql);

        try {
            // Insert identifiers. Parameters from the update clause are cut off.
            $numUpdated = $conn->executeStatement(
                $this->insertSql,
                array_slice($params, $this->numParametersInUpdateClause),
                array_slice($types, $this->numParametersInUpdateClause),
            );

            // Execute UPDATE statements
            foreach ($this->sqlStatements as $key => $statement) {
                $paramValues = [];
                $paramTypes  = [];

                if (isset($this->sqlParameters[$key])) {
                    foreach ($this->sqlParameters[$key] as $parameterKey => $parameterName) {
                        $paramValues[] = $params[$parameterKey];
                        $paramTypes[]  = $types[$parameterKey] ?? ParameterTypeInferer::inferType($params[$parameterKey]);
                    }
                }

                $conn->executeStatement($statement, $paramValues, $paramTypes);
            }
        } finally {
            // Drop temporary table
            $conn->executeStatement($this->dropTempTableSql);
        }

        return $numUpdated;
    }
}
