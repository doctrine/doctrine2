<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Common\Util\Inflector;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping;

/**
 * The DatabaseDriver reverse engineers the mapping metadata from a database.
 *
 * @link    www.doctrine-project.org
 * @since   2.0
 * @author  Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author  Jonathan Wage <jonwage@gmail.com>
 * @author  Benjamin Eberlei <kontakt@beberlei.de>
 */
class DatabaseDriver implements MappingDriver
{
    /**
     * @var AbstractSchemaManager
     */
    private $sm;

    /**
     * @var array|null
     */
    private $tables = null;

    /**
     * @var array
     */
    private $classToTableNames = [];

    /**
     * @var array
     */
    private $manyToManyTables = [];

    /**
     * @var array
     */
    private $classNamesForTables = [];

    /**
     * @var array
     */
    private $fieldNamesForColumns = [];

    /**
     * The namespace for the generated entities.
     *
     * @var string|null
     */
    private $namespace;

    /**
     * @param AbstractSchemaManager $schemaManager
     */
    public function __construct(AbstractSchemaManager $schemaManager)
    {
        $this->sm = $schemaManager;
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
     * @param array $entityTables
     * @param array $manyToManyTables
     *
     * @return void
     */
    public function setTables($entityTables, $manyToManyTables)
    {
        $this->tables = $this->manyToManyTables = $this->classToTableNames = [];

        foreach ($entityTables as $table) {
            $className = $this->getClassNameForTable($table->getName());

            $this->classToTableNames[$className] = $table->getName();
            $this->tables[$table->getName()] = $table;
        }

        foreach ($manyToManyTables as $table) {
            $this->manyToManyTables[$table->getName()] = $table;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function loadMetadataForClass(
        string $className,
        Mapping\ClassMetadata $metadata,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    )
    {
        $this->reverseEngineerMappingFromDatabase();

        if ( ! isset($this->classToTableNames[$className])) {
            throw new \InvalidArgumentException("Unknown class " . $className);
        }

        // @todo guilhermeblanco This should somehow disappear... =)
        $metadata->setClassName($className);

        $this->buildTable($metadata);
        $this->buildFieldMappings($metadata);
        $this->buildToOneAssociationMappings($metadata);

        $loweredTableName = strtolower($metadata->getTableName());

        foreach ($this->manyToManyTables as $manyTable) {
            foreach ($manyTable->getForeignKeys() as $foreignKey) {
                // foreign key maps to the table of the current entity, many to many association probably exists
                if ( ! ($loweredTableName === strtolower($foreignKey->getForeignTableName()))) {
                    continue;
                }

                $myFk = $foreignKey;
                $otherFk = null;

                foreach ($manyTable->getForeignKeys() as $manyTableForeignKey) {
                    if ($manyTableForeignKey !== $myFk) {
                        $otherFk = $manyTableForeignKey;

                        break;
                    }
                }

                if ( ! $otherFk) {
                    // the definition of this many to many table does not contain
                    // enough foreign key information to continue reverse engineering.
                    continue;
                }

                $localColumn = current($myFk->getColumns());

                $associationMapping = [];
                $associationMapping['fieldName'] = $this->getFieldNameForColumn($manyTable->getName(), current($otherFk->getColumns()), true);
                $associationMapping['targetEntity'] = $this->getClassNameForTable($otherFk->getForeignTableName());

                if (current($manyTable->getColumns())->getName() === $localColumn) {
                    $associationMapping['inversedBy'] = $this->getFieldNameForColumn($manyTable->getName(), current($myFk->getColumns()), true);
                    $associationMapping['joinTable']  = new Mapping\JoinTableMetadata();

                    $joinTable = $associationMapping['joinTable'];
                    $joinTable->setName(strtolower($manyTable->getName()));

                    $fkCols = $myFk->getForeignColumns();
                    $cols   = $myFk->getColumns();

                    for ($i = 0, $l = count($cols); $i < $l; $i++) {
                        $joinColumn = new Mapping\JoinColumnMetadata();

                        $joinColumn->setColumnName($cols[$i]);
                        $joinColumn->setReferencedColumnName($fkCols[$i]);

                        $joinTable->addJoinColumn($joinColumn);
                    }

                    $fkCols = $otherFk->getForeignColumns();
                    $cols = $otherFk->getColumns();

                    for ($i = 0, $l = count($cols); $i < $l; $i++) {
                        $joinColumn = new Mapping\JoinColumnMetadata();

                        $joinColumn->setColumnName($cols[$i]);
                        $joinColumn->setReferencedColumnName($fkCols[$i]);

                        $joinTable->addInverseJoinColumn($joinColumn);
                    }
                } else {
                    $associationMapping['mappedBy'] = $this->getFieldNameForColumn($manyTable->getName(), current($myFk->getColumns()), true);
                }

                $metadata->addProperty($associationMapping);

                break;
            }
        }
    }

    /**
     * @return void
     *
     * @throws Mapping\MappingException
     */
    private function reverseEngineerMappingFromDatabase()
    {
        if ($this->tables !== null) {
            return;
        }

        $tables = [];

        foreach ($this->sm->listTableNames() as $tableName) {
            $tables[$tableName] = $this->sm->listTableDetails($tableName);
        }

        $this->tables = $this->manyToManyTables = $this->classToTableNames = [];

        foreach ($tables as $tableName => $table) {
            $foreignKeys = ($this->sm->getDatabasePlatform()->supportsForeignKeyConstraints())
                ? $table->getForeignKeys()
                : [];

            $allForeignKeyColumns = [];

            foreach ($foreignKeys as $foreignKey) {
                $allForeignKeyColumns = array_merge($allForeignKeyColumns, $foreignKey->getLocalColumns());
            }

            if ( ! $table->hasPrimaryKey()) {
                throw new Mapping\MappingException(
                    "Table " . $table->getName() . " has no primary key. Doctrine does not ".
                    "support reverse engineering from tables that don't have a primary key."
                );
            }

            $pkColumns = $table->getPrimaryKey()->getColumns();

            sort($pkColumns);
            sort($allForeignKeyColumns);

            if ($pkColumns == $allForeignKeyColumns && count($foreignKeys) == 2) {
                $this->manyToManyTables[$tableName] = $table;
            } else {
                // lower-casing is necessary because of Oracle Uppercase Tablenames,
                // assumption is lower-case + underscore separated.
                $className = $this->getClassNameForTable($tableName);

                $this->tables[$tableName] = $table;
                $this->classToTableNames[$className] = $tableName;
            }
        }
    }

    /**
     * Build table from a class metadata.
     *
     * @param Mapping\ClassMetadata $metadata
     */
    private function buildTable(Mapping\ClassMetadata $metadata)
    {
        $tableName      = $this->classToTableNames[$metadata->getClassName()];
        $indexes        = $this->tables[$tableName]->getIndexes();
        $tableMetadata  = new Mapping\TableMetadata();

        $tableMetadata->setName($this->classToTableNames[$metadata->getClassName()]);

        foreach ($indexes as $index) {
            /** @var Index $index */
            if ($index->isPrimary()) {
                continue;
            }

            $tableMetadata->addIndex([
                'name'    => $index->getName(),
                'columns' => $index->getColumns(),
                'unique'  => $index->isUnique(),
                'options' => $index->getOptions(),
                'flags'   => $index->getFlags(),
            ]);
        }

        $metadata->setTable($tableMetadata);
    }

    /**
     * Build field mapping from class metadata.
     *
     * @param Mapping\ClassMetadata $metadata
     */
    private function buildFieldMappings(Mapping\ClassMetadata $metadata)
    {
        $tableName      = $metadata->getTableName();
        $columns        = $this->tables[$tableName]->getColumns();
        $primaryKeys    = $this->getTablePrimaryKeys($this->tables[$tableName]);
        $foreignKeys    = $this->getTableForeignKeys($this->tables[$tableName]);
        $allForeignKeys = [];

        foreach ($foreignKeys as $foreignKey) {
            $allForeignKeys = array_merge($allForeignKeys, $foreignKey->getLocalColumns());
        }

        $ids = [];

        foreach ($columns as $column) {
            if (in_array($column->getName(), $allForeignKeys)) {
                continue;
            }

            $fieldName     = $this->getFieldNameForColumn($tableName, $column->getName(), false);
            $fieldMetadata = $this->convertColumnAnnotationToFieldMetadata($tableName, $column, $fieldName);

            if ($primaryKeys && in_array($column->getName(), $primaryKeys)) {
                $fieldMetadata->setPrimaryKey(true);

                $ids[] = $fieldMetadata;
            }

            $metadata->addProperty($fieldMetadata);
        }

        // We need to check for the columns here, because we might have associations as id as well.
        if ($ids && count($primaryKeys) === 1) {
            $ids[0]->setValueGenerator(new Mapping\ValueGeneratorMetadata(Mapping\GeneratorType::AUTO));
        }
    }

    /**
     * Parse the given Column as FieldMetadata
     *
     * @param string $tableName
     * @param Column $column
     * @param string $fieldName
     *
     * @return Mapping\FieldMetadata
     */
    private function convertColumnAnnotationToFieldMetadata(string $tableName, Column $column, string $fieldName)
    {
        $options       = [];
        $fieldMetadata = new Mapping\FieldMetadata($fieldName);

        $fieldMetadata->setType($column->getType());
        $fieldMetadata->setTableName($tableName);
        $fieldMetadata->setColumnName($column->getName());

        // Type specific elements
        switch ($column->getType()->getName()) {
            case Type::TARRAY:
            case Type::BLOB:
            case Type::GUID:
            case Type::JSON_ARRAY:
            case Type::OBJECT:
            case Type::SIMPLE_ARRAY:
            case Type::STRING:
            case Type::TEXT:
                if ($column->getLength()) {
                    $fieldMetadata->setLength($column->getLength());
                }

                $options['fixed'] = $column->getFixed();
                break;

            case Type::DECIMAL:
            case Type::FLOAT:
                $fieldMetadata->setScale($column->getScale());
                $fieldMetadata->setPrecision($column->getPrecision());
                break;

            case Type::INTEGER:
            case Type::BIGINT:
            case Type::SMALLINT:
                $options['unsigned'] = $column->getUnsigned();
                break;
        }

        // Comment
        if (($comment = $column->getComment()) !== null) {
            $options['comment'] = $comment;
        }

        // Default
        if (($default = $column->getDefault()) !== null) {
            $options['default'] = $default;
        }

        $fieldMetadata->setOptions($options);

        return $fieldMetadata;
    }

    /**
     * Build to one (one to one, many to one) association mapping from class metadata.
     *
     * @param Mapping\ClassMetadata $metadata
     */
    private function buildToOneAssociationMappings(Mapping\ClassMetadata $metadata)
    {
        $tableName   = $metadata->getTableName();
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

            if ($metadata->getProperty($associationMapping['fieldName'])) {
                $associationMapping['fieldName'] .= '2'; // "foo" => "foo2"
            }

            if ($primaryKeys && in_array($localColumn, $primaryKeys)) {
                $associationMapping['id'] = true;
            }

            for ($i = 0, $l = count($fkColumns); $i < $l; $i++) {
                $joinColumn = new Mapping\JoinColumnMetadata();

                $joinColumn->setColumnName($fkColumns[$i]);
                $joinColumn->setReferencedColumnName($fkForeignColumns[$i]);

                $associationMapping['joinColumns'][] = $joinColumn;
            }

            // Here we need to check if $fkColumns are the same as $primaryKeys
            if ( ! array_diff($fkColumns, $primaryKeys)) {
                $metadata->addProperty($associationMapping);
            } else {
                $metadata->addProperty($associationMapping);
            }
        }
    }

    /**
     * Retrieve schema table definition foreign keys.
     *
     * @param \Doctrine\DBAL\Schema\Table $table
     *
     * @return array
     */
    private function getTableForeignKeys(Table $table)
    {
        return ($this->sm->getDatabasePlatform()->supportsForeignKeyConstraints())
            ? $table->getForeignKeys()
            : [];
    }

    /**
     * Retrieve schema table definition primary keys.
     *
     * @param \Doctrine\DBAL\Schema\Table $table
     *
     * @return array
     */
    private function getTablePrimaryKeys(Table $table)
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
     * @param string $tableName
     *
     * @return string
     */
    private function getClassNameForTable($tableName)
    {
        if (isset($this->classNamesForTables[$tableName])) {
            return $this->namespace . $this->classNamesForTables[$tableName];
        }

        return $this->namespace . Inflector::classify(strtolower($tableName));
    }

    /**
     * Return the mapped field name for a column, if it exists. Otherwise return camelized version.
     *
     * @param string  $tableName
     * @param string  $columnName
     * @param boolean $fk Whether the column is a foreignkey or not.
     *
     * @return string
     */
    private function getFieldNameForColumn($tableName, $columnName, $fk = false)
    {
        if (isset($this->fieldNamesForColumns[$tableName], $this->fieldNamesForColumns[$tableName][$columnName])) {
            return $this->fieldNamesForColumns[$tableName][$columnName];
        }

        $columnName = strtolower($columnName);

        // Replace _id if it is a foreignkey column
        if ($fk) {
            $columnName = str_replace('_id', '', $columnName);
        }

        return Inflector::camelize($columnName);
    }
}
