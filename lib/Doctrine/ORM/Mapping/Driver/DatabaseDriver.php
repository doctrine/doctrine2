<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;

use function array_diff;
use function array_keys;
use function array_merge;
use function count;
use function current;
use function in_array;
use function preg_replace;
use function sort;
use function strtolower;

/**
 * The DatabaseDriver reverse engineers the mapping metadata from a database.
 *
 * @link    www.doctrine-project.org
 */
class DatabaseDriver implements MappingDriver
{
    /**
     * Replacement for {@see Types::JSON_ARRAY}.
     *
     * To be removed as soon as support for DBAL 2 is dropped.
     */
    private const JSON_ARRAY = 'json_array';

    /** @var AbstractSchemaManager */
    private $_sm;

    /** @var array<string,Table>|null */
    private $tables = null;

    /** @var mixed[] */
    private $classToTableNames = [];

    /** @psalm-var array<string, Table> */
    private $manyToManyTables = [];

    /** @var mixed[] */
    private $classNamesForTables = [];

    /** @var mixed[] */
    private $fieldNamesForColumns = [];

    /**
     * The namespace for the generated entities.
     *
     * @var string|null
     */
    private $namespace;

    /** @var Inflector */
    private $inflector;

    public function __construct(AbstractSchemaManager $schemaManager)
    {
        $this->_sm       = $schemaManager;
        $this->inflector = InflectorFactory::create()->build();
    }

    /**
     * Set the namespace for the generated entities.
     *
     * @param string $namespace
     *
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * {@inheritDoc}
     */
    public function isTransient($className)
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getAllClassNames()
    {
        $this->reverseEngineerMappingFromDatabase();

        return array_keys($this->classToTableNames);
    }

    /**
     * Sets class name for a table.
     *
     * @param string $tableName
     * @param string $className
     *
     * @return void
     */
    public function setClassNameForTable($tableName, $className)
    {
        $this->classNamesForTables[$tableName] = $className;
    }

    /**
     * Sets field name for a column on a specific table.
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $fieldName
     *
     * @return void
     */
    public function setFieldNameForColumn($tableName, $columnName, $fieldName)
    {
        $this->fieldNamesForColumns[$tableName][$columnName] = $fieldName;
    }

    /**
     * Sets tables manually instead of relying on the reverse engineering capabilities of SchemaManager.
     *
     * @param Table[] $entityTables
     * @param Table[] $manyToManyTables
     * @psalm-param list<Table> $entityTables
     * @psalm-param list<Table> $manyToManyTables
     *
     * @return void
     */
    public function setTables($entityTables, $manyToManyTables)
    {
        $this->tables = $this->manyToManyTables = $this->classToTableNames = [];

        foreach ($entityTables as $table) {
            $className = $this->getClassNameForTable($table->getName());

            $this->classToTableNames[$className] = $table->getName();
            $this->tables[$table->getName()]     = $table;
        }

        foreach ($manyToManyTables as $table) {
            $this->manyToManyTables[$table->getName()] = $table;
        }
    }

    public function setInflector(Inflector $inflector): void
    {
        $this->inflector = $inflector;
    }

    /**
     * {@inheritDoc}
     */
    public function loadMetadataForClass($className, ClassMetadata $metadata)
    {
        $this->reverseEngineerMappingFromDatabase();

        if (! isset($this->classToTableNames[$className])) {
            throw new InvalidArgumentException('Unknown class ' . $className);
        }

        $tableName = $this->classToTableNames[$className];

        $metadata->name          = $className;
        $metadata->table['name'] = $tableName;

        $this->buildIndexes($metadata);
        $this->buildFieldMappings($metadata);
        $this->buildToOneAssociationMappings($metadata);

        foreach ($this->manyToManyTables as $manyTable) {
            foreach ($manyTable->getForeignKeys() as $foreignKey) {
                // foreign key maps to the table of the current entity, many to many association probably exists
                if (! (strtolower($tableName) === strtolower($foreignKey->getForeignTableName()))) {
                    continue;
                }

                $myFk    = $foreignKey;
                $otherFk = null;

                foreach ($manyTable->getForeignKeys() as $foreignKey) {
                    if ($foreignKey !== $myFk) {
                        $otherFk = $foreignKey;
                        break;
                    }
                }

                if (! $otherFk) {
                    // the definition of this many to many table does not contain
                    // enough foreign key information to continue reverse engineering.
                    continue;
                }

                $localColumn = current($myFk->getColumns());

                $associationMapping                 = [];
                $associationMapping['fieldName']    = $this->getFieldNameForColumn($manyTable->getName(), current($otherFk->getColumns()), true);
                $associationMapping['targetEntity'] = $this->getClassNameForTable($otherFk->getForeignTableName());

                if (current($manyTable->getColumns())->getName() === $localColumn) {
                    $associationMapping['inversedBy'] = $this->getFieldNameForColumn($manyTable->getName(), current($myFk->getColumns()), true);
                    $associationMapping['joinTable']  = [
                        'name' => strtolower($manyTable->getName()),
                        'joinColumns' => [],
                        'inverseJoinColumns' => [],
                    ];

                    $fkCols = $myFk->getForeignColumns();
                    $cols   = $myFk->getColumns();

                    for ($i = 0, $colsCount = count($cols); $i < $colsCount; $i++) {
                        $associationMapping['joinTable']['joinColumns'][] = [
                            'name' => $cols[$i],
                            'referencedColumnName' => $fkCols[$i],
                        ];
                    }

                    $fkCols = $otherFk->getForeignColumns();
                    $cols   = $otherFk->getColumns();

                    for ($i = 0, $colsCount = count($cols); $i < $colsCount; $i++) {
                        $associationMapping['joinTable']['inverseJoinColumns'][] = [
                            'name' => $cols[$i],
                            'referencedColumnName' => $fkCols[$i],
                        ];
                    }
                } else {
                    $associationMapping['mappedBy'] = $this->getFieldNameForColumn($manyTable->getName(), current($myFk->getColumns()), true);
                }

                $metadata->mapManyToMany($associationMapping);

                break;
            }
        }
    }

    /**
     * @throws MappingException
     */
    private function reverseEngineerMappingFromDatabase(): void
    {
        if ($this->tables !== null) {
            return;
        }

        $tables = [];

        foreach ($this->_sm->listTableNames() as $tableName) {
            $tables[$tableName] = $this->_sm->listTableDetails($tableName);
        }

        $this->tables = $this->manyToManyTables = $this->classToTableNames = [];

        foreach ($tables as $tableName => $table) {
            $foreignKeys = $this->_sm->getDatabasePlatform()->supportsForeignKeyConstraints()
                ? $table->getForeignKeys()
                : [];

            $allForeignKeyColumns = [];

            foreach ($foreignKeys as $foreignKey) {
                $allForeignKeyColumns = array_merge($allForeignKeyColumns, $foreignKey->getLocalColumns());
            }

            if (! $table->hasPrimaryKey()) {
                throw new MappingException(
                    'Table ' . $table->getName() . ' has no primary key. Doctrine does not ' .
                    "support reverse engineering from tables that don't have a primary key."
                );
            }

            $pkColumns = $table->getPrimaryKey()->getColumns();

            sort($pkColumns);
            sort($allForeignKeyColumns);

            if ($pkColumns === $allForeignKeyColumns && count($foreignKeys) === 2) {
                $this->manyToManyTables[$tableName] = $table;
            } else {
                // lower-casing is necessary because of Oracle Uppercase Tablenames,
                // assumption is lower-case + underscore separated.
                $className = $this->getClassNameForTable($tableName);

                $this->tables[$tableName]            = $table;
                $this->classToTableNames[$className] = $tableName;
            }
        }
    }

    /**
     * Build indexes from a class metadata.
     */
    private function buildIndexes(ClassMetadataInfo $metadata): void
    {
        $tableName = $metadata->table['name'];
        $indexes   = $this->tables[$tableName]->getIndexes();

        foreach ($indexes as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $indexName      = $index->getName();
            $indexColumns   = $index->getColumns();
            $constraintType = $index->isUnique()
                ? 'uniqueConstraints'
                : 'indexes';

            $metadata->table[$constraintType][$indexName]['columns'] = $indexColumns;
        }
    }

    /**
     * Build field mapping from class metadata.
     */
    private function buildFieldMappings(ClassMetadataInfo $metadata): void
    {
        $tableName      = $metadata->table['name'];
        $columns        = $this->tables[$tableName]->getColumns();
        $primaryKeys    = $this->getTablePrimaryKeys($this->tables[$tableName]);
        $foreignKeys    = $this->getTableForeignKeys($this->tables[$tableName]);
        $allForeignKeys = [];

        foreach ($foreignKeys as $foreignKey) {
            $allForeignKeys = array_merge($allForeignKeys, $foreignKey->getLocalColumns());
        }

        $ids           = [];
        $fieldMappings = [];

        foreach ($columns as $column) {
            if (in_array($column->getName(), $allForeignKeys)) {
                continue;
            }

            $fieldMapping = $this->buildFieldMapping($tableName, $column);

            if ($primaryKeys && in_array($column->getName(), $primaryKeys)) {
                $fieldMapping['id'] = true;
                $ids[]              = $fieldMapping;
            }

            $fieldMappings[] = $fieldMapping;
        }

        // We need to check for the columns here, because we might have associations as id as well.
        if ($ids && count($primaryKeys) === 1) {
            $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
        }

        foreach ($fieldMappings as $fieldMapping) {
            $metadata->mapField($fieldMapping);
        }
    }

    /**
     * Build field mapping from a schema column definition
     *
     * @return mixed[]
     * @psalm-return array{
     *     fieldName: string,
     *     columnName: string,
     *     type: string,
     *     nullable: bool,
     *     options?: array{
     *         unsigned?: bool,
     *         fixed?: bool,
     *         comment?: string,
     *         default?: string
     *     },
     *     precision?: int,
     *     scale?: int,
     *     length?: int|null
     * }
     */
    private function buildFieldMapping(string $tableName, Column $column): array
    {
        $fieldMapping = [
            'fieldName'  => $this->getFieldNameForColumn($tableName, $column->getName(), false),
            'columnName' => $column->getName(),
            'type'       => $column->getType()->getName(),
            'nullable'   => ! $column->getNotnull(),
        ];

        // Type specific elements
        switch ($fieldMapping['type']) {
            case Types::ARRAY:
            case Types::BLOB:
            case Types::GUID:
            case self::JSON_ARRAY:
            case Types::OBJECT:
            case Types::SIMPLE_ARRAY:
            case Types::STRING:
            case Types::TEXT:
                $fieldMapping['length']           = $column->getLength();
                $fieldMapping['options']['fixed'] = $column->getFixed();
                break;

            case Types::DECIMAL:
            case Types::FLOAT:
                $fieldMapping['precision'] = $column->getPrecision();
                $fieldMapping['scale']     = $column->getScale();
                break;

            case Types::INTEGER:
            case Types::BIGINT:
            case Types::SMALLINT:
                $fieldMapping['options']['unsigned'] = $column->getUnsigned();
                break;
        }

        // Comment
        $comment = $column->getComment();
        if ($comment !== null) {
            $fieldMapping['options']['comment'] = $comment;
        }

        // Default
        $default = $column->getDefault();
        if ($default !== null) {
            $fieldMapping['options']['default'] = $default;
        }

        return $fieldMapping;
    }

    /**
     * Build to one (one to one, many to one) association mapping from class metadata.
     *
     * @return void
     */
    private function buildToOneAssociationMappings(ClassMetadataInfo $metadata)
    {
        $tableName   = $metadata->table['name'];
        $primaryKeys = $this->getTablePrimaryKeys($this->tables[$tableName]);
        $foreignKeys = $this->getTableForeignKeys($this->tables[$tableName]);

        foreach ($foreignKeys as $foreignKey) {
            $foreignTableName   = $foreignKey->getForeignTableName();
            $fkColumns          = $foreignKey->getColumns();
            $fkForeignColumns   = $foreignKey->getForeignColumns();
            $localColumn        = current($fkColumns);
            $associationMapping = [
                'fieldName'    => $this->getFieldNameForColumn($tableName, $localColumn, true),
                'targetEntity' => $this->getClassNameForTable($foreignTableName),
            ];

            if (isset($metadata->fieldMappings[$associationMapping['fieldName']])) {
                $associationMapping['fieldName'] .= '2'; // "foo" => "foo2"
            }

            if ($primaryKeys && in_array($localColumn, $primaryKeys)) {
                $associationMapping['id'] = true;
            }

            for ($i = 0, $fkColumnsCount = count($fkColumns); $i < $fkColumnsCount; $i++) {
                $associationMapping['joinColumns'][] = [
                    'name'                 => $fkColumns[$i],
                    'referencedColumnName' => $fkForeignColumns[$i],
                ];
            }

            // Here we need to check if $fkColumns are the same as $primaryKeys
            if (! array_diff($fkColumns, $primaryKeys)) {
                $metadata->mapOneToOne($associationMapping);
            } else {
                $metadata->mapManyToOne($associationMapping);
            }
        }
    }

    /**
     * Retrieve schema table definition foreign keys.
     *
     * @return ForeignKeyConstraint[]
     * @psalm-return array<string, ForeignKeyConstraint>
     */
    private function getTableForeignKeys(Table $table): array
    {
        return $this->_sm->getDatabasePlatform()->supportsForeignKeyConstraints()
            ? $table->getForeignKeys()
            : [];
    }

    /**
     * Retrieve schema table definition primary keys.
     *
     * @return string[]
     */
    private function getTablePrimaryKeys(Table $table): array
    {
        try {
            return $table->getPrimaryKey()->getColumns();
        } catch (SchemaException $e) {
            // Do nothing
        }

        return [];
    }

    /**
     * Returns the mapped class name for a table if it exists. Otherwise return "classified" version.
     *
     * @psalm-return class-string
     */
    private function getClassNameForTable(string $tableName): string
    {
        if (isset($this->classNamesForTables[$tableName])) {
            return $this->namespace . $this->classNamesForTables[$tableName];
        }

        return $this->namespace . $this->inflector->classify(strtolower($tableName));
    }

    /**
     * Return the mapped field name for a column, if it exists. Otherwise return camelized version.
     *
     * @param bool $fk Whether the column is a foreignkey or not.
     */
    private function getFieldNameForColumn(
        string $tableName,
        string $columnName,
        bool $fk = false
    ): string {
        if (isset($this->fieldNamesForColumns[$tableName], $this->fieldNamesForColumns[$tableName][$columnName])) {
            return $this->fieldNamesForColumns[$tableName][$columnName];
        }

        $columnName = strtolower($columnName);

        // Replace _id if it is a foreignkey column
        if ($fk) {
            $columnName = preg_replace('/_id$/', '', $columnName);
        }

        return $this->inflector->camelize($columnName);
    }
}
