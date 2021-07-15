<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\ParameterTypeInferer;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Utility\PersisterHelper;
use Throwable;

use function array_merge;
use function array_reverse;
use function array_slice;
use function implode;

/**
 * Executes the SQL statements for bulk DQL UPDATE statements on classes in
 * Class Table Inheritance (JOINED).
 */
class MultiTableUpdateExecutor extends AbstractSqlExecutor
{
    /** @var string */
    private $_createTempTableSql;

    /** @var string */
    private $_dropTempTableSql;

    /** @var string */
    private $_insertSql;

    /** @var mixed[] */
    private $_sqlParameters = [];

    /** @var int */
    private $_numParametersInUpdateClause = 0;

    /**
     * Initializes a new <tt>MultiTableUpdateExecutor</tt>.
     *
     * Internal note: Any SQL construction and preparation takes place in the constructor for
     *                best performance. With a query cache the executor will be cached.
     *
     * @param UpdateStatement $AST       The root AST node of the DQL query.
     * @param SqlWalker       $sqlWalker The walker used for SQL generation from the AST.
     */
    public function __construct(AST\Node $AST, $sqlWalker)
    {
        $em            = $sqlWalker->getEntityManager();
        $conn          = $em->getConnection();
        $platform      = $conn->getDatabasePlatform();
        $quoteStrategy = $em->getConfiguration()->getQuoteStrategy();

        $updateClause = $AST->updateClause;
        $primaryClass = $sqlWalker->getEntityManager()->getClassMetadata($updateClause->abstractSchemaName);
        $rootClass    = $em->getClassMetadata($primaryClass->rootEntityName);

        $updateItems = $updateClause->updateItems;

        $tempTable     = $platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $idColumnList  = implode(', ', $idColumnNames);

        // 1. Create an INSERT INTO temptable ... SELECT identifiers WHERE $AST->getWhereClause()
        $sqlWalker->setSQLTableAlias($primaryClass->getTableName(), 't0', $updateClause->aliasIdentificationVariable);

        $this->_insertSql = 'INSERT INTO ' . $tempTable . ' (' . $idColumnList . ')'
                . ' SELECT t0.' . implode(', t0.', $idColumnNames);

        $rangeDecl  = new AST\RangeVariableDeclaration($primaryClass->name, $updateClause->aliasIdentificationVariable);
        $fromClause = new AST\FromClause([new AST\IdentificationVariableDeclaration($rangeDecl, null, [])]);

        $this->_insertSql .= $sqlWalker->walkFromClause($fromClause);

        // 2. Create ID subselect statement used in UPDATE ... WHERE ... IN (subselect)
        $idSubselect = 'SELECT ' . $idColumnList . ' FROM ' . $tempTable;

        // 3. Create and store UPDATE statements
        $classNames = array_merge($primaryClass->parentClasses, [$primaryClass->name], $primaryClass->subClasses);
        $i          = -1;

        foreach (array_reverse($classNames) as $className) {
            $affected  = false;
            $class     = $em->getClassMetadata($className);
            $updateSql = 'UPDATE ' . $quoteStrategy->getTableName($class, $platform) . ' SET ';

            foreach ($updateItems as $updateItem) {
                $field = $updateItem->pathExpression->field;

                if (
                    (isset($class->fieldMappings[$field]) && ! isset($class->fieldMappings[$field]['inherited'])) ||
                    (isset($class->associationMappings[$field]) && ! isset($class->associationMappings[$field]['inherited']))
                ) {
                    $newValue = $updateItem->newValue;

                    if (! $affected) {
                        $affected = true;
                        ++$i;
                    } else {
                        $updateSql .= ', ';
                    }

                    $updateSql .= $sqlWalker->walkUpdateItem($updateItem);

                    if ($newValue instanceof AST\InputParameter) {
                        $this->_sqlParameters[$i][] = $newValue->name;

                        ++$this->_numParametersInUpdateClause;
                    }
                }
            }

            if ($affected) {
                $this->_sqlStatements[$i] = $updateSql . ' WHERE (' . $idColumnList . ') IN (' . $idSubselect . ')';
            }
        }

        // Append WHERE clause to insertSql, if there is one.
        if ($AST->whereClause) {
            $this->_insertSql .= $sqlWalker->walkWhereClause($AST->whereClause);
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

        $this->_dropTempTableSql = $platform->getDropTemporaryTableSQL($tempTable);
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
            // Insert identifiers. Parameters from the update clause are cut off.
            $numUpdated = $conn->executeStatement(
                $this->_insertSql,
                array_slice($params, $this->_numParametersInUpdateClause),
                array_slice($types, $this->_numParametersInUpdateClause)
            );

            // Execute UPDATE statements
            foreach ($this->_sqlStatements as $key => $statement) {
                $paramValues = [];
                $paramTypes  = [];

                if (isset($this->_sqlParameters[$key])) {
                    foreach ($this->_sqlParameters[$key] as $parameterKey => $parameterName) {
                        $paramValues[] = $params[$parameterKey];
                        $paramTypes[]  = $types[$parameterKey] ?? ParameterTypeInferer::inferType($params[$parameterKey]);
                    }
                }

                $conn->executeStatement($statement, $paramValues, $paramTypes);
            }
        } finally {
            // Drop temporary table
            $conn->executeStatement($this->_dropTempTableSql);
        }

        return $numUpdated;
    }
}
