<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Visitor\DropSchemaSqlCollector;
use Doctrine\DBAL\Schema\Visitor\RemoveNamespacedAssets;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMetadata;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\Exception\MissingColumnException;
use Doctrine\ORM\Tools\Exception\NotSupported;
use Throwable;

use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function assert;
use function count;
use function current;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function reset;
use function strtolower;

/**
 * The SchemaTool is a tool to create/drop/update database schemas based on
 * <tt>ClassMetadata</tt> class descriptors.
 *
 * @link    www.doctrine-project.org
 */
class SchemaTool
{
    private const KNOWN_COLUMN_OPTIONS = ['comment', 'unsigned', 'fixed', 'default'];

    /** @var EntityManagerInterface */
    private $em;

    /** @var AbstractPlatform */
    private $platform;

    /**
     * The quote strategy.
     *
     * @var QuoteStrategy
     */
    private $quoteStrategy;

    /**
     * Initializes a new SchemaTool instance that uses the connection of the
     * provided EntityManager.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em            = $em;
        $this->platform      = $em->getConnection()->getDatabasePlatform();
        $this->quoteStrategy = $em->getConfiguration()->getQuoteStrategy();
    }

    /**
     * Creates the database schema for the given array of ClassMetadata instances.
     *
     * @psalm-param list<ClassMetadata> $classes
     *
     * @return void
     *
     * @throws ToolsException
     */
    public function createSchema(array $classes)
    {
        $createSchemaSql = $this->getCreateSchemaSql($classes);
        $conn            = $this->em->getConnection();

        foreach ($createSchemaSql as $sql) {
            try {
                $conn->executeQuery($sql);
            } catch (Throwable $e) {
                throw ToolsException::schemaToolFailure($sql, $e);
            }
        }
    }

    /**
     * Gets the list of DDL statements that are required to create the database schema for
     * the given list of ClassMetadata instances.
     *
     * @psalm-param list<ClassMetadata> $classes
     *
     * @return string[] The SQL statements needed to create the schema for the classes.
     */
    public function getCreateSchemaSql(array $classes)
    {
        $schema = $this->getSchemaFromMetadata($classes);

        return $schema->toSql($this->platform);
    }

    /**
     * Detects instances of ClassMetadata that don't need to be processed in the SchemaTool context.
     *
     * @psalm-param array<string, bool> $processedClasses
     */
    private function processingNotRequired(
        ClassMetadata $class,
        array $processedClasses
    ): bool {
        return isset($processedClasses[$class->name]) ||
            $class->isMappedSuperclass ||
            $class->isEmbeddedClass ||
            ($class->isInheritanceTypeSingleTable() && $class->name !== $class->rootEntityName);
    }

    /**
     * Resolves fields in index mapping to column names
     *
     * @param mixed[] $indexData index or unique constraint data
     *
     * @return string[] Column names from combined fields and columns mappings
     */
    private function getIndexColumns(ClassMetadata $class, array $indexData): array
    {
        $columns = [];

        if (
            isset($indexData['columns'], $indexData['fields'])
            || (
                ! isset($indexData['columns'])
                && ! isset($indexData['fields'])
            )
        ) {
            throw MappingException::invalidIndexConfiguration(
                $class,
                $indexData['name'] ?? 'unnamed'
            );
        }

        if (isset($indexData['columns'])) {
            $columns = $indexData['columns'];
        }

        if (isset($indexData['fields'])) {
            foreach ($indexData['fields'] as $fieldName) {
                if ($class->hasField($fieldName)) {
                    $columns[] = $this->quoteStrategy->getColumnName($fieldName, $class, $this->platform);
                } elseif ($class->hasAssociation($fieldName)) {
                    foreach ($class->getAssociationMapping($fieldName)['joinColumns'] as $joinColumn) {
                        $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Creates a Schema instance from a given set of metadata classes.
     *
     * @psalm-param list<ClassMetadata> $classes
     *
     * @return Schema
     *
     * @throws NotSupported
     */
    public function getSchemaFromMetadata(array $classes)
    {
        // Reminder for processed classes, used for hierarchies
        $processedClasses     = [];
        $eventManager         = $this->em->getEventManager();
        $schemaManager        = $this->em->getConnection()->getSchemaManager();
        $metadataSchemaConfig = $schemaManager->createSchemaConfig();

        $metadataSchemaConfig->setExplicitForeignKeyIndexes(false);
        $schema = new Schema([], [], $metadataSchemaConfig);

        $addedFks       = [];
        $blacklistedFks = [];

        foreach ($classes as $class) {
            if ($this->processingNotRequired($class, $processedClasses)) {
                continue;
            }

            $table = $schema->createTable($this->quoteStrategy->getTableName($class, $this->platform));

            if ($class->isInheritanceTypeSingleTable()) {
                $this->gatherColumns($class, $table);
                $this->gatherRelationsSql($class, $table, $schema, $addedFks, $blacklistedFks);

                // Add the discriminator column
                $this->addDiscriminatorColumnDefinition($class, $table);

                // Aggregate all the information from all classes in the hierarchy
                foreach ($class->parentClasses as $parentClassName) {
                    // Parent class information is already contained in this class
                    $processedClasses[$parentClassName] = true;
                }

                foreach ($class->subClasses as $subClassName) {
                    $subClass = $this->em->getClassMetadata($subClassName);
                    $this->gatherColumns($subClass, $table);
                    $this->gatherRelationsSql($subClass, $table, $schema, $addedFks, $blacklistedFks);
                    $processedClasses[$subClassName] = true;
                }
            } elseif ($class->isInheritanceTypeJoined()) {
                // Add all non-inherited fields as columns
                foreach ($class->fieldMappings as $fieldName => $mapping) {
                    if (! isset($mapping['inherited'])) {
                        $this->gatherColumn($class, $mapping, $table);
                    }
                }

                $this->gatherRelationsSql($class, $table, $schema, $addedFks, $blacklistedFks);

                // Add the discriminator column only to the root table
                if ($class->name === $class->rootEntityName) {
                    $this->addDiscriminatorColumnDefinition($class, $table);
                } else {
                    // Add an ID FK column to child tables
                    $pkColumns           = [];
                    $inheritedKeyColumns = [];

                    foreach ($class->identifier as $identifierField) {
                        if (isset($class->fieldMappings[$identifierField]['inherited'])) {
                            $idMapping = $class->fieldMappings[$identifierField];
                            $this->gatherColumn($class, $idMapping, $table);
                            $columnName = $this->quoteStrategy->getColumnName(
                                $identifierField,
                                $class,
                                $this->platform
                            );
                            // TODO: This seems rather hackish, can we optimize it?
                            $table->getColumn($columnName)->setAutoincrement(false);

                            $pkColumns[]           = $columnName;
                            $inheritedKeyColumns[] = $columnName;

                            continue;
                        }

                        if (isset($class->associationMappings[$identifierField]['inherited'])) {
                            $idMapping = $class->associationMappings[$identifierField];

                            $targetEntity = current(
                                array_filter(
                                    $classes,
                                    static function (ClassMetadata $class) use ($idMapping): bool {
                                        return $class->name === $idMapping['targetEntity'];
                                    }
                                )
                            );

                            foreach ($idMapping['joinColumns'] as $joinColumn) {
                                if (isset($targetEntity->fieldMappings[$joinColumn['referencedColumnName']])) {
                                    $columnName = $this->quoteStrategy->getJoinColumnName(
                                        $joinColumn,
                                        $class,
                                        $this->platform
                                    );

                                    $pkColumns[]           = $columnName;
                                    $inheritedKeyColumns[] = $columnName;
                                }
                            }
                        }
                    }

                    if ($inheritedKeyColumns !== []) {
                        // Add a FK constraint on the ID column
                        $table->addForeignKeyConstraint(
                            $this->quoteStrategy->getTableName(
                                $this->em->getClassMetadata($class->rootEntityName),
                                $this->platform
                            ),
                            $inheritedKeyColumns,
                            $inheritedKeyColumns,
                            ['onDelete' => 'CASCADE']
                        );
                    }

                    if ($pkColumns !== []) {
                        $table->setPrimaryKey($pkColumns);
                    }
                }
            } elseif ($class->isInheritanceTypeTablePerClass()) {
                throw NotSupported::create();
            } else {
                $this->gatherColumns($class, $table);
                $this->gatherRelationsSql($class, $table, $schema, $addedFks, $blacklistedFks);
            }

            $pkColumns = [];

            foreach ($class->identifier as $identifierField) {
                if (isset($class->fieldMappings[$identifierField])) {
                    $pkColumns[] = $this->quoteStrategy->getColumnName($identifierField, $class, $this->platform);
                } elseif (isset($class->associationMappings[$identifierField])) {
                    $assoc = $class->associationMappings[$identifierField];
                    assert(is_array($assoc));

                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        $pkColumns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                    }
                }
            }

            if (! $table->hasIndex('primary')) {
                $table->setPrimaryKey($pkColumns);
            }

            // there can be unique indexes automatically created for join column
            // if join column is also primary key we should keep only primary key on this column
            // so, remove indexes overruled by primary key
            $primaryKey = $table->getIndex('primary');

            foreach ($table->getIndexes() as $idxKey => $existingIndex) {
                if ($primaryKey->overrules($existingIndex)) {
                    $table->dropIndex($idxKey);
                }
            }

            if (isset($class->table['indexes'])) {
                foreach ($class->table['indexes'] as $indexName => $indexData) {
                    if (! isset($indexData['flags'])) {
                        $indexData['flags'] = [];
                    }

                    $table->addIndex(
                        $this->getIndexColumns($class, $indexData),
                        is_numeric($indexName) ? null : $indexName,
                        (array) $indexData['flags'],
                        $indexData['options'] ?? []
                    );
                }
            }

            if (isset($class->table['uniqueConstraints'])) {
                foreach ($class->table['uniqueConstraints'] as $indexName => $indexData) {
                    $uniqIndex = new Index($indexName, $this->getIndexColumns($class, $indexData), true, false, [], $indexData['options'] ?? []);

                    foreach ($table->getIndexes() as $tableIndexName => $tableIndex) {
                        if ($tableIndex->isFullfilledBy($uniqIndex)) {
                            $table->dropIndex($tableIndexName);
                            break;
                        }
                    }

                    $table->addUniqueIndex($uniqIndex->getColumns(), is_numeric($indexName) ? null : $indexName, $indexData['options'] ?? []);
                }
            }

            if (isset($class->table['options'])) {
                foreach ($class->table['options'] as $key => $val) {
                    $table->addOption($key, $val);
                }
            }

            $processedClasses[$class->name] = true;

            if ($class->isIdGeneratorSequence() && $class->name === $class->rootEntityName) {
                $seqDef     = $class->sequenceGeneratorDefinition;
                $quotedName = $this->quoteStrategy->getSequenceName($seqDef, $class, $this->platform);
                if (! $schema->hasSequence($quotedName)) {
                    $schema->createSequence(
                        $quotedName,
                        (int) $seqDef['allocationSize'],
                        (int) $seqDef['initialValue']
                    );
                }
            }

            if ($eventManager->hasListeners(ToolEvents::postGenerateSchemaTable)) {
                $eventManager->dispatchEvent(
                    ToolEvents::postGenerateSchemaTable,
                    new GenerateSchemaTableEventArgs($class, $schema, $table)
                );
            }
        }

        if (! $this->platform->supportsSchemas() && ! $this->platform->canEmulateSchemas()) {
            $schema->visit(new RemoveNamespacedAssets());
        }

        if ($eventManager->hasListeners(ToolEvents::postGenerateSchema)) {
            $eventManager->dispatchEvent(
                ToolEvents::postGenerateSchema,
                new GenerateSchemaEventArgs($this->em, $schema)
            );
        }

        return $schema;
    }

    /**
     * Gets a portable column definition as required by the DBAL for the discriminator
     * column of a class.
     */
    private function addDiscriminatorColumnDefinition(ClassMetadata $class, Table $table): void
    {
        $discrColumn = $class->discriminatorColumn;

        if (
            ! isset($discrColumn['type']) ||
            (strtolower($discrColumn['type']) === 'string' && ! isset($discrColumn['length']))
        ) {
            $discrColumn['type']   = 'string';
            $discrColumn['length'] = 255;
        }

        $options = [
            'length'    => $discrColumn['length'] ?? null,
            'notnull'   => true,
        ];

        if (isset($discrColumn['columnDefinition'])) {
            $options['columnDefinition'] = $discrColumn['columnDefinition'];
        }

        $table->addColumn($discrColumn['name'], $discrColumn['type'], $options);
    }

    /**
     * Gathers the column definitions as required by the DBAL of all field mappings
     * found in the given class.
     */
    private function gatherColumns(ClassMetadata $class, Table $table): void
    {
        $pkColumns = [];

        foreach ($class->fieldMappings as $mapping) {
            if ($class->isInheritanceTypeSingleTable() && isset($mapping['inherited'])) {
                continue;
            }

            $this->gatherColumn($class, $mapping, $table);

            if ($class->isIdentifier($mapping['fieldName'])) {
                $pkColumns[] = $this->quoteStrategy->getColumnName($mapping['fieldName'], $class, $this->platform);
            }
        }
    }

    /**
     * Creates a column definition as required by the DBAL from an ORM field mapping definition.
     *
     * @param ClassMetadata $class The class that owns the field mapping.
     * @psalm-param array<string, mixed> $mapping The field mapping.
     */
    private function gatherColumn(
        ClassMetadata $class,
        array $mapping,
        Table $table
    ): void {
        $columnName = $this->quoteStrategy->getColumnName($mapping['fieldName'], $class, $this->platform);
        $columnType = $mapping['type'];

        $options            = [];
        $options['length']  = $mapping['length'] ?? null;
        $options['notnull'] = isset($mapping['nullable']) ? ! $mapping['nullable'] : true;
        if ($class->isInheritanceTypeSingleTable() && $class->parentClasses) {
            $options['notnull'] = false;
        }

        $options['platformOptions']            = [];
        $options['platformOptions']['version'] = $class->isVersioned && $class->versionField === $mapping['fieldName'];

        if (strtolower($columnType) === 'string' && $options['length'] === null) {
            $options['length'] = 255;
        }

        if (isset($mapping['precision'])) {
            $options['precision'] = $mapping['precision'];
        }

        if (isset($mapping['scale'])) {
            $options['scale'] = $mapping['scale'];
        }

        if (isset($mapping['default'])) {
            $options['default'] = $mapping['default'];
        }

        if (isset($mapping['columnDefinition'])) {
            $options['columnDefinition'] = $mapping['columnDefinition'];
        }

        // the 'default' option can be overwritten here
        $options = $this->gatherColumnOptions($mapping) + $options;

        if ($class->isIdGeneratorIdentity() && $class->getIdentifierFieldNames() === [$mapping['fieldName']]) {
            $options['autoincrement'] = true;
        }

        if ($class->isInheritanceTypeJoined() && $class->name !== $class->rootEntityName) {
            $options['autoincrement'] = false;
        }

        if ($table->hasColumn($columnName)) {
            // required in some inheritance scenarios
            $table->changeColumn($columnName, $options);
        } else {
            $table->addColumn($columnName, $columnType, $options);
        }

        $isUnique = $mapping['unique'] ?? false;
        if ($isUnique) {
            $table->addUniqueIndex([$columnName]);
        }
    }

    /**
     * Gathers the SQL for properly setting up the relations of the given class.
     * This includes the SQL for foreign key constraints and join tables.
     *
     * @psalm-param array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>
     *              }>                               $addedFks
     * @psalm-param array<string, bool>              $blacklistedFks
     *
     * @throws NotSupported
     */
    private function gatherRelationsSql(
        ClassMetadata $class,
        Table $table,
        Schema $schema,
        array &$addedFks,
        array &$blacklistedFks
    ): void {
        foreach ($class->associationMappings as $id => $mapping) {
            if (isset($mapping['inherited']) && ! in_array($id, $class->identifier, true)) {
                continue;
            }

            $foreignClass = $this->em->getClassMetadata($mapping['targetEntity']);

            if ($mapping['type'] & ClassMetadata::TO_ONE && $mapping['isOwningSide']) {
                $primaryKeyColumns = []; // PK is unnecessary for this relation-type

                $this->gatherRelationJoinColumns(
                    $mapping['joinColumns'],
                    $table,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks
                );
            } elseif ($mapping['type'] === ClassMetadata::ONE_TO_MANY && $mapping['isOwningSide']) {
                //... create join table, one-many through join table supported later
                throw NotSupported::create();
            } elseif ($mapping['type'] === ClassMetadata::MANY_TO_MANY && $mapping['isOwningSide']) {
                // create join table
                $joinTable = $mapping['joinTable'];

                $theJoinTable = $schema->createTable(
                    $this->quoteStrategy->getJoinTableName($mapping, $foreignClass, $this->platform)
                );

                $primaryKeyColumns = [];

                // Build first FK constraint (relation table => source table)
                $this->gatherRelationJoinColumns(
                    $joinTable['joinColumns'],
                    $theJoinTable,
                    $class,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks
                );

                // Build second FK constraint (relation table => target table)
                $this->gatherRelationJoinColumns(
                    $joinTable['inverseJoinColumns'],
                    $theJoinTable,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks
                );

                $theJoinTable->setPrimaryKey($primaryKeyColumns);
            }
        }
    }

    /**
     * Gets the class metadata that is responsible for the definition of the referenced column name.
     *
     * Previously this was a simple task, but with DDC-117 this problem is actually recursive. If its
     * not a simple field, go through all identifier field names that are associations recursively and
     * find that referenced column name.
     *
     * TODO: Is there any way to make this code more pleasing?
     *
     * @psalm-return array{ClassMetadata, string}|null
     */
    private function getDefiningClass(ClassMetadata $class, string $referencedColumnName): ?array
    {
        $referencedFieldName = $class->getFieldName($referencedColumnName);

        if ($class->hasField($referencedFieldName)) {
            return [$class, $referencedFieldName];
        }

        if (in_array($referencedColumnName, $class->getIdentifierColumnNames())) {
            // it seems to be an entity as foreign key
            foreach ($class->getIdentifierFieldNames() as $fieldName) {
                if (
                    $class->hasAssociation($fieldName)
                    && $class->getSingleAssociationJoinColumnName($fieldName) === $referencedColumnName
                ) {
                    return $this->getDefiningClass(
                        $this->em->getClassMetadata($class->associationMappings[$fieldName]['targetEntity']),
                        $class->getSingleAssociationReferencedJoinColumnName($fieldName)
                    );
                }
            }
        }

        return null;
    }

    /**
     * Gathers columns and fk constraints that are required for one part of relationship.
     *
     * @psalm-param array<string, mixed>             $joinColumns
     * @psalm-param array<string, mixed>             $mapping
     * @psalm-param list<string>                     $primaryKeyColumns
     * @psalm-param array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>
     *              }>                               $addedFks
     * @psalm-param array<string,bool>               $blacklistedFks
     *
     * @throws MissingColumnException
     */
    private function gatherRelationJoinColumns(
        array $joinColumns,
        Table $theJoinTable,
        ClassMetadata $class,
        array $mapping,
        array &$primaryKeyColumns,
        array &$addedFks,
        array &$blacklistedFks
    ): void {
        $localColumns      = [];
        $foreignColumns    = [];
        $fkOptions         = [];
        $foreignTableName  = $this->quoteStrategy->getTableName($class, $this->platform);
        $uniqueConstraints = [];

        foreach ($joinColumns as $joinColumn) {
            [$definingClass, $referencedFieldName] = $this->getDefiningClass(
                $class,
                $joinColumn['referencedColumnName']
            );

            if (! $definingClass) {
                throw MissingColumnException::fromColumnSourceAndTarget(
                    $joinColumn['referencedColumnName'],
                    $mapping['sourceEntity'],
                    $mapping['targetEntity']
                );
            }

            $quotedColumnName    = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            $quotedRefColumnName = $this->quoteStrategy->getReferencedJoinColumnName(
                $joinColumn,
                $class,
                $this->platform
            );

            $primaryKeyColumns[] = $quotedColumnName;
            $localColumns[]      = $quotedColumnName;
            $foreignColumns[]    = $quotedRefColumnName;

            if (! $theJoinTable->hasColumn($quotedColumnName)) {
                // Only add the column to the table if it does not exist already.
                // It might exist already if the foreign key is mapped into a regular
                // property as well.

                $fieldMapping = $definingClass->getFieldMapping($referencedFieldName);

                $columnDef = null;
                if (isset($joinColumn['columnDefinition'])) {
                    $columnDef = $joinColumn['columnDefinition'];
                } elseif (isset($fieldMapping['columnDefinition'])) {
                    $columnDef = $fieldMapping['columnDefinition'];
                }

                $columnOptions = ['notnull' => false, 'columnDefinition' => $columnDef];

                if (isset($joinColumn['nullable'])) {
                    $columnOptions['notnull'] = ! $joinColumn['nullable'];
                }

                $columnOptions += $this->gatherColumnOptions($fieldMapping);

                if ($fieldMapping['type'] === 'string' && isset($fieldMapping['length'])) {
                    $columnOptions['length'] = $fieldMapping['length'];
                } elseif ($fieldMapping['type'] === 'decimal') {
                    $columnOptions['scale']     = $fieldMapping['scale'];
                    $columnOptions['precision'] = $fieldMapping['precision'];
                }

                $theJoinTable->addColumn($quotedColumnName, $fieldMapping['type'], $columnOptions);
            }

            if (isset($joinColumn['unique']) && $joinColumn['unique'] === true) {
                $uniqueConstraints[] = ['columns' => [$quotedColumnName]];
            }

            if (isset($joinColumn['onDelete'])) {
                $fkOptions['onDelete'] = $joinColumn['onDelete'];
            }
        }

        // Prefer unique constraints over implicit simple indexes created for foreign keys.
        // Also avoids index duplication.
        foreach ($uniqueConstraints as $indexName => $unique) {
            $theJoinTable->addUniqueIndex($unique['columns'], is_numeric($indexName) ? null : $indexName);
        }

        $compositeName = $theJoinTable->getName() . '.' . implode('', $localColumns);
        if (
            isset($addedFks[$compositeName])
            && ($foreignTableName !== $addedFks[$compositeName]['foreignTableName']
            || 0 < count(array_diff($foreignColumns, $addedFks[$compositeName]['foreignColumns'])))
        ) {
            foreach ($theJoinTable->getForeignKeys() as $fkName => $key) {
                if (
                    count(array_diff($key->getLocalColumns(), $localColumns)) === 0
                    && (($key->getForeignTableName() !== $foreignTableName)
                    || 0 < count(array_diff($key->getForeignColumns(), $foreignColumns)))
                ) {
                    $theJoinTable->removeForeignKey($fkName);
                    break;
                }
            }

            $blacklistedFks[$compositeName] = true;
        } elseif (! isset($blacklistedFks[$compositeName])) {
            $addedFks[$compositeName] = ['foreignTableName' => $foreignTableName, 'foreignColumns' => $foreignColumns];
            $theJoinTable->addForeignKeyConstraint(
                $foreignTableName,
                $localColumns,
                $foreignColumns,
                $fkOptions
            );
        }
    }

    /**
     * @param mixed[] $mapping
     *
     * @return mixed[]
     */
    private function gatherColumnOptions(array $mapping): array
    {
        if (! isset($mapping['options'])) {
            return [];
        }

        $options                        = array_intersect_key($mapping['options'], array_flip(self::KNOWN_COLUMN_OPTIONS));
        $options['customSchemaOptions'] = array_diff_key($mapping['options'], $options);

        return $options;
    }

    /**
     * Drops the database schema for the given classes.
     *
     * In any way when an exception is thrown it is suppressed since drop was
     * issued for all classes of the schema and some probably just don't exist.
     *
     * @psalm-param list<ClassMetadata> $classes
     *
     * @return void
     */
    public function dropSchema(array $classes)
    {
        $dropSchemaSql = $this->getDropSchemaSQL($classes);
        $conn          = $this->em->getConnection();

        foreach ($dropSchemaSql as $sql) {
            try {
                $conn->executeQuery($sql);
            } catch (Throwable $e) {
                // ignored
            }
        }
    }

    /**
     * Drops all elements in the database of the current connection.
     *
     * @return void
     */
    public function dropDatabase()
    {
        $dropSchemaSql = $this->getDropDatabaseSQL();
        $conn          = $this->em->getConnection();

        foreach ($dropSchemaSql as $sql) {
            $conn->executeQuery($sql);
        }
    }

    /**
     * Gets the SQL needed to drop the database schema for the connections database.
     *
     * @return string[]
     */
    public function getDropDatabaseSQL()
    {
        $sm     = $this->em->getConnection()->getSchemaManager();
        $schema = $sm->createSchema();

        $visitor = new DropSchemaSqlCollector($this->platform);
        $schema->visit($visitor);

        return $visitor->getQueries();
    }

    /**
     * Gets SQL to drop the tables defined by the passed classes.
     *
     * @psalm-param list<ClassMetadata> $classes
     *
     * @return string[]
     */
    public function getDropSchemaSQL(array $classes)
    {
        $visitor = new DropSchemaSqlCollector($this->platform);
        $schema  = $this->getSchemaFromMetadata($classes);

        $sm         = $this->em->getConnection()->getSchemaManager();
        $fullSchema = $sm->createSchema();

        foreach ($fullSchema->getTables() as $table) {
            if (! $schema->hasTable($table->getName())) {
                foreach ($table->getForeignKeys() as $foreignKey) {
                    if ($schema->hasTable($foreignKey->getForeignTableName())) {
                        $visitor->acceptForeignKey($table, $foreignKey);
                    }
                }
            } else {
                $visitor->acceptTable($table);
                foreach ($table->getForeignKeys() as $foreignKey) {
                    $visitor->acceptForeignKey($table, $foreignKey);
                }
            }
        }

        if ($this->platform->supportsSequences()) {
            foreach ($schema->getSequences() as $sequence) {
                $visitor->acceptSequence($sequence);
            }

            foreach ($schema->getTables() as $table) {
                if ($table->hasPrimaryKey()) {
                    $columns = $table->getPrimaryKey()->getColumns();
                    if (count($columns) === 1) {
                        $checkSequence = $table->getName() . '_' . $columns[0] . '_seq';
                        if ($fullSchema->hasSequence($checkSequence)) {
                            $visitor->acceptSequence($fullSchema->getSequence($checkSequence));
                        }
                    }
                }
            }
        }

        return $visitor->getQueries();
    }

    /**
     * Updates the database schema of the given classes by comparing the ClassMetadata
     * instances to the current database schema that is inspected.
     *
     * @param mixed[] $classes
     * @param bool    $saveMode If TRUE, only performs a partial update
     *                           without dropping assets which are scheduled for deletion.
     *
     * @return void
     */
    public function updateSchema(array $classes, $saveMode = false)
    {
        $updateSchemaSql = $this->getUpdateSchemaSql($classes, $saveMode);
        $conn            = $this->em->getConnection();

        foreach ($updateSchemaSql as $sql) {
            $conn->executeQuery($sql);
        }
    }

    /**
     * Gets the sequence of SQL statements that need to be performed in order
     * to bring the given class mappings in-synch with the relational schema.
     *
     * @param mixed[] $classes  The classes to consider.
     * @param bool    $saveMode If TRUE, only generates SQL for a partial update
     *                           that does not include SQL for dropping assets which are scheduled for deletion.
     *
     * @return string[] The sequence of SQL statements.
     */
    public function getUpdateSchemaSql(array $classes, $saveMode = false)
    {
        $toSchema   = $this->getSchemaFromMetadata($classes);
        $fromSchema = $this->createSchemaForComparison($toSchema);

        $comparator = new Comparator();
        $schemaDiff = $comparator->compare($fromSchema, $toSchema);

        if ($saveMode) {
            return $schemaDiff->toSaveSql($this->platform);
        }

        return $schemaDiff->toSql($this->platform);
    }

    /**
     * Creates the schema from the database, ensuring tables from the target schema are whitelisted for comparison.
     */
    private function createSchemaForComparison(Schema $toSchema): Schema
    {
        $connection    = $this->em->getConnection();
        $schemaManager = $connection->getSchemaManager();

        // backup schema assets filter
        $config         = $connection->getConfiguration();
        $previousFilter = $config->getSchemaAssetsFilter();

        if ($previousFilter === null) {
            return $schemaManager->createSchema();
        }

        // whitelist assets we already know about in $toSchema, use the existing filter otherwise
        $config->setSchemaAssetsFilter(static function ($asset) use ($previousFilter, $toSchema): bool {
            $assetName = $asset instanceof AbstractAsset ? $asset->getName() : $asset;

            return $toSchema->hasTable($assetName) || $toSchema->hasSequence($assetName) || $previousFilter($asset);
        });

        try {
            return $schemaManager->createSchema();
        } finally {
            // restore schema assets filter
            $config->setSchemaAssetsFilter($previousFilter);
        }
    }
}
