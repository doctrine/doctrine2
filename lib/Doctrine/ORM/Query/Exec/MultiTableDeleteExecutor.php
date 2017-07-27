<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ColumnMetadata;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Utility\PersisterHelper;

/**
 * Executes the SQL statements for bulk DQL DELETE statements on classes in
 * Class Table Inheritance (JOINED).
 *
 * @author      Roman Borschel <roman@code-factory.org>
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 * @link        http://www.doctrine-project.org
 * @since       2.0
 */
class MultiTableDeleteExecutor extends AbstractSqlExecutor
{
    /**
     * @var string
     */
    private $createTempTableSql;

    /**
     * @var string
     */
    private $dropTempTableSql;

    /**
     * @var string
     */
    private $insertSql;

    /**
     * Initializes a new <tt>MultiTableDeleteExecutor</tt>.
     *
     * Internal note: Any SQL construction and preparation takes place in the constructor for
     *                best performance. With a query cache the executor will be cached.
     *
     * @param \Doctrine\ORM\Query\AST\Node  $AST       The root AST node of the DQL query.
     * @param \Doctrine\ORM\Query\SqlWalker $sqlWalker The walker used for SQL generation from the AST.
     */
    public function __construct(AST\Node $AST, $sqlWalker)
    {
        $em             = $sqlWalker->getEntityManager();
        $conn           = $em->getConnection();
        $platform       = $conn->getDatabasePlatform();

        $primaryClass    = $em->getClassMetadata($AST->deleteClause->abstractSchemaName);
        $primaryDqlAlias = $AST->deleteClause->aliasIdentificationVariable;
        $rootClass       = $em->getClassMetadata($primaryClass->getRootClassName());

        $tempTable        = $platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumns        = $rootClass->getIdentifierColumns($em);
        $idColumnNameList = implode(', ', array_keys($idColumns));

        // 1. Create an INSERT INTO temptable ... SELECT identifiers WHERE $AST->getWhereClause()
        $sqlWalker->setSQLTableAlias($primaryClass->getTableName(), 'i0', $primaryDqlAlias);

        $this->insertSql = 'INSERT INTO ' . $tempTable . ' (' . $idColumnNameList . ')'
                . ' SELECT i0.' . implode(', i0.', array_keys($idColumns));

        $rangeDecl = new AST\RangeVariableDeclaration($primaryClass->getClassName(), $primaryDqlAlias);
        $fromClause = new AST\FromClause([new AST\IdentificationVariableDeclaration($rangeDecl, null, [])]);
        $this->insertSql .= $sqlWalker->walkFromClause($fromClause);

        // Append WHERE clause, if there is one.
        if ($AST->whereClause) {
            $this->insertSql .= $sqlWalker->walkWhereClause($AST->whereClause);
        }

        // 2. Create ID subselect statement used in DELETE ... WHERE ... IN (subselect)
        $idSubselect = 'SELECT ' . $idColumnNameList . ' FROM ' . $tempTable;

        // 3. Create and store DELETE statements
        $classNames = array_merge(
            $primaryClass->getParentClasses(),
            [$primaryClass->getClassName()],
            $primaryClass->getSubClasses()
        );

        foreach (array_reverse($classNames) as $className) {
            $parentClass = $em->getClassMetadata($className);
            $tableName   = $parentClass->table->getQuotedQualifiedName($platform);

            $this->sqlStatements[] = 'DELETE FROM ' . $tableName
                . ' WHERE (' . $idColumnNameList . ') IN (' . $idSubselect . ')';
        }

        // 4. Store DDL for temporary identifier table.
        $columnDefinitions = [];

        foreach ($idColumns as $columnName => $column) {
            $columnDefinitions[$columnName] = [
                'notnull' => true,
                'type'    => $column->getType(),
            ];
        }

        $this->createTempTableSql = $platform->getCreateTemporaryTableSnippetSQL() . ' ' . $tempTable . ' ('
                . $platform->getColumnDeclarationListSQL($columnDefinitions) . ')';

        $this->dropTempTableSql = $platform->getDropTemporaryTableSQL($tempTable);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Connection $conn, array $params, array $types)
    {
        // Create temporary id table
        $conn->executeUpdate($this->createTempTableSql);

        try {
            // Insert identifiers
            $numDeleted = $conn->executeUpdate($this->insertSql, $params, $types);

            // Execute DELETE statements
            foreach ($this->sqlStatements as $sql) {
                $conn->executeUpdate($sql);
            }
        } catch (\Exception $exception) {
            // FAILURE! Drop temporary table to avoid possible collisions
            $conn->executeUpdate($this->dropTempTableSql);

            // Re-throw exception
            throw $exception;
        }

        // Drop temporary table
        $conn->executeUpdate($this->dropTempTableSql);

        return $numDeleted;
    }
}
