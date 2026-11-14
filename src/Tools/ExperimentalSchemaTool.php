<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use BackedEnum;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\DefaultExpression;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentDate;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTime;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\IndexEditor;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaConfig;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\NotSupported as NotSupportedException;
use Doctrine\ORM\Internal\Name\NameParser;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DiscriminatorColumnMapping;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\Exception\MissingColumnException;
use Doctrine\ORM\Tools\Exception\NotSupported;
use InvalidArgumentException;
use RuntimeException;

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
use function interface_exists;
use function is_int;
use function is_numeric;
use function method_exists;
use function sprintf;
use function strtolower;

/**
 * @internal
 *
 * @phpstan-import-type IndexMapping from ClassMetadata
 */
final class ExperimentalSchemaTool
{
    private const KNOWN_COLUMN_OPTIONS = ['comment', 'unsigned', 'fixed', 'default', 'values'];

    private readonly AbstractPlatform $platform;
    private readonly QuoteStrategy $quoteStrategy;
    private readonly AbstractSchemaManager $schemaManager;

    /**
     * Table currently being built and the unique indexes declared on it through unique constraints.
     * Ordinary indexes made redundant by those are not created in the first place.
     */
    private TableEditor|null $uniqueConstraintsTable = null;

    /** @var list<Index> */
    private array $tableUniqueIndexes = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->platform      = $em->getConnection()->getDatabasePlatform();
        $this->quoteStrategy = $em->getConfiguration()->getQuoteStrategy();
        $this->schemaManager = $em->getConnection()->createSchemaManager();
    }

    /**
     * Detects instances of ClassMetadata that don't need to be processed in the SchemaTool context.
     *
     * @phpstan-param array<string, bool> $processedClasses
     */
    private function processingNotRequired(
        ClassMetadata $class,
        array $processedClasses,
    ): bool {
        return isset($processedClasses[$class->name]) ||
            $class->isMappedSuperclass ||
            $class->isEmbeddedClass ||
            ($class->isInheritanceTypeSingleTable() && $class->name !== $class->rootEntityName) ||
            in_array($class->name, $this->em->getConfiguration()->getSchemaIgnoreClasses());
    }

    /**
     * Resolves fields in index mapping to column names
     *
     * @param IndexMapping $indexData index or unique constraint data
     *
     * @return non-empty-list<string> Column names from combined fields and columns mappings
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
                (string) $class,
                $indexData['name'] ?? 'unnamed',
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
                    $assoc = $class->getAssociationMapping($fieldName);
                    assert($assoc->isToOneOwningSide());
                    foreach ($assoc->joinColumns as $joinColumn) {
                        $columns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                    }
                }
            }
        }

        if ($columns === []) {
            throw MappingException::invalidIndexConfiguration(
                (string) $class,
                $indexData['name'] ?? 'unnamed',
            );
        }

        return $columns;
    }

    /**
     * Creates a Schema instance from a given set of metadata classes, using the schema editor API
     *
     * @phpstan-param list<ClassMetadata> $classes
     *
     * @throws NotSupported
     */
    public function getSchemaFromMetadata(array $classes, SchemaOperation|null $operation): Schema
    {
        // Reminder for processed classes, used for hierarchies
        $processedClasses     = [];
        $eventManager         = $this->em->getEventManager();
        $metadataSchemaConfig = $this->schemaManager->createSchemaConfig();

        $schema = new Schema([], [], $metadataSchemaConfig);

        $addedFks       = [];
        $blacklistedFks = [];

        foreach ($classes as $class) {
            $columnNames   = [];
            $hasPrimaryKey = false;
            if ($this->processingNotRequired($class, $processedClasses)) {
                continue;
            }

            $tableName = $this->quoteStrategy->getTableName($class, $this->platform);

            $table = Table::editor()
                ->setName(NameParser::parseOptionallyQualifiedName($tableName))
                ->setConfiguration($metadataSchemaConfig->toTableConfiguration())
                ->setOptions($metadataSchemaConfig->getDefaultTableOptions());

            $this->uniqueConstraintsTable = $table;
            $this->tableUniqueIndexes     = [];

            foreach ($class->table['uniqueConstraints'] ?? [] as $indexName => $indexData) {
                $this->tableUniqueIndexes[] = new Index('tmp__' . $indexName, $this->getIndexColumns($class, $indexData), true, false, [], $indexData['options'] ?? []);
            }

            if ($class->isInheritanceTypeSingleTable()) {
                $joinTablesToAdd = [];

                $this->gatherColumns($class, $table, $columnNames);
                $this->gatherRelationsSql(
                    $class,
                    $table,
                    $tableName,
                    $columnNames,
                    $schema,
                    $addedFks,
                    $blacklistedFks,
                    $metadataSchemaConfig,
                    $joinTablesToAdd,
                );

                // Add the discriminator column
                $this->addDiscriminatorColumnDefinition($class, $table);

                // Aggregate all the information from all classes in the hierarchy
                foreach ($class->parentClasses as $parentClassName) {
                    // Parent class information is already contained in this class
                    $processedClasses[$parentClassName] = true;
                }

                foreach ($class->subClasses as $subClassName) {
                    $subClass = $this->em->getClassMetadata($subClassName);
                    $this->gatherColumns($subClass, $table, $columnNames);
                    $this->gatherRelationsSql(
                        $subClass,
                        $table,
                        $tableName,
                        $columnNames,
                        $schema,
                        $addedFks,
                        $blacklistedFks,
                        $metadataSchemaConfig,
                        $joinTablesToAdd,
                    );
                    $processedClasses[$subClassName] = true;
                }
            } elseif ($class->isInheritanceTypeJoined()) {
                $joinTablesToAdd = [];

                // Add all non-inherited fields as columns
                foreach ($class->fieldMappings as $fieldName => $mapping) {
                    if (! isset($mapping->inherited)) {
                        $this->gatherColumn($class, $mapping, $table, $columnNames);
                    }
                }

                $this->gatherRelationsSql(
                    $class,
                    $table,
                    $tableName,
                    $columnNames,
                    $schema,
                    $addedFks,
                    $blacklistedFks,
                    $metadataSchemaConfig,
                    $joinTablesToAdd,
                );

                // Add the discriminator column only to the root table
                if ($class->name === $class->rootEntityName) {
                    $this->addDiscriminatorColumnDefinition($class, $table);
                } else {
                    // Add an ID FK column to child tables
                    $pkColumns           = [];
                    $inheritedKeyColumns = [];

                    foreach ($class->identifier as $identifierField) {
                        if (isset($class->fieldMappings[$identifierField]->inherited)) {
                            $idMapping = $class->fieldMappings[$identifierField];
                            $this->gatherColumn($class, $idMapping, $table, $columnNames);
                            $columnName = $this->quoteStrategy->getColumnName(
                                $identifierField,
                                $class,
                                $this->platform,
                            );
                            // TODO: This seems rather hackish, can we optimize it?
                            // New API: modify column using table editor (creates new table object)
                            // This is safe because we'll add the table to schema later after all modifications
                            $table->modifyColumnByUnquotedName(
                                $columnName,
                                static function (ColumnEditor $column): void {
                                    $column->setAutoincrement(false);
                                },
                            );

                            $pkColumns[]           = $columnName;
                            $inheritedKeyColumns[] = $columnName;

                            continue;
                        }

                        if (isset($class->associationMappings[$identifierField]->inherited)) {
                            $idMapping = $class->associationMappings[$identifierField];
                            assert($idMapping->isToOneOwningSide());

                            $targetEntity = current(
                                array_filter(
                                    $classes,
                                    static fn (ClassMetadata $class): bool => $class->name === $idMapping->targetEntity,
                                ),
                            );

                            foreach ($idMapping->joinColumns as $joinColumn) {
                                if (isset($targetEntity->fieldMappings[$joinColumn->referencedColumnName])) {
                                    $columnName = $this->quoteStrategy->getJoinColumnName(
                                        $joinColumn,
                                        $class,
                                        $this->platform,
                                    );

                                    $pkColumns[]           = $columnName;
                                    $inheritedKeyColumns[] = $columnName;
                                }
                            }
                        }
                    }

                    if ($inheritedKeyColumns !== []) {
                        // A FK collected on the same columns (e.g. for an inherited identifier association)
                        // would get the same generated name and replace the FK to the root table.
                        unset($addedFks[self::getTableKey($tableName) . '.' . implode('', $inheritedKeyColumns)]);

                        // Add a FK constraint on the ID column
                        $this->addForeignKeyConstraint(
                            $table,
                            $this->quoteStrategy->getTableName(
                                $this->em->getClassMetadata($class->rootEntityName),
                                $this->platform,
                            ),
                            $inheritedKeyColumns,
                            $inheritedKeyColumns,
                            ['onDelete' => 'CASCADE'],
                        );
                    }

                    if ($pkColumns !== []) {
                        self::addPrimaryKeyConstraint($table, $pkColumns);
                        $hasPrimaryKey = true;
                    }
                }
            } else {
                $joinTablesToAdd = [];

                $this->gatherColumns($class, $table, $columnNames);
                $this->gatherRelationsSql(
                    $class,
                    $table,
                    $tableName,
                    $columnNames,
                    $schema,
                    $addedFks,
                    $blacklistedFks,
                    $metadataSchemaConfig,
                    $joinTablesToAdd,
                );
            }

            $pkColumns = $this->getPrimaryKeyColumnNames($class);

            if (! $hasPrimaryKey) {
                self::addPrimaryKeyConstraint($table, $pkColumns);
            }

            if (isset($class->table['indexes'])) {
                foreach ($class->table['indexes'] as $indexName => $indexData) {
                    $candidate = $this->createTableIndexEditor($class, $indexData)
                        ->setName(NameParser::parseUnqualifiedName('tmp'))
                        ->create();

                    if ($this->isRedundantForTableUniqueIndexes($table, $candidate)) {
                        continue;
                    }

                    $indexEditor = $this->createTableIndexEditor($class, $indexData);

                    if (! is_numeric($indexName)) {
                        $indexEditor->setName(NameParser::parseUnqualifiedName($indexName));
                    }

                    $table->addIndex($indexEditor);
                }
            }

            // the unique constraints themselves must not be skipped as redundant
            $this->uniqueConstraintsTable = null;

            foreach ($class->table['uniqueConstraints'] ?? [] as $indexName => $indexData) {
                $flagNames = self::normalizeIndexFlags((array) ($indexData['flags'] ?? []));

                if (isset($flagNames['fulltext']) || isset($flagNames['spatial'])) {
                    throw new InvalidArgumentException('A unique index cannot be fulltext or spatial.');
                }

                $this->addUniqueIndex(
                    $table,
                    $this->getIndexColumns($class, $indexData),
                    is_numeric($indexName) ? null : $indexName,
                    $indexData['options'] ?? [],
                );
            }

            if (isset($class->table['options'])) {
                $table = $table->setOptions($class->table['options']);
            }

            $table = $table->create();

            $processedClasses[$class->name] = true;

            // Add the fully populated table to the schema
            $schemaEditor = $schema->edit();
            $schemaEditor->addTable($table);

            // Add any join tables collected during relation processing
            // This ensures join tables appear right after their owning entity table
            foreach ($joinTablesToAdd as $joinTable) {
                $schemaEditor->addTable($joinTable);
            }

            if ($class->isIdGeneratorSequence() && $class->name === $class->rootEntityName) {
                $seqDef     = $class->sequenceGeneratorDefinition;
                $quotedName = $this->quoteStrategy->getSequenceName($seqDef, $class, $this->platform);
                if (! $schema->hasSequence($quotedName)) {
                    $schemaEditor->addSequence(
                        Sequence::editor()
                            ->setName(NameParser::parseOptionallyQualifiedName($quotedName))
                            ->setAllocationSize((int) $seqDef['allocationSize'])
                            ->setInitialValue((int) $seqDef['initialValue'])
                            ->create(),
                    );
                }
            }

            $schema = $schemaEditor->create();

            $tableEventArgs = new GenerateSchemaTableEventArgs($class, $schema, $table);
            $eventManager->dispatchEvent(
                ToolEvents::postGenerateSchemaTable,
                $tableEventArgs,
            );

            // Always retrieve the schema (listener may have mutated it)
            $schema = $tableEventArgs->getSchema();

                // If the listener mutated the table, we need to replace it in the schema
            if ($tableEventArgs->classTableWasMutated()) {
                $originalTableName = $table->getObjectName();
                $newTable          = $tableEventArgs->getClassTable();

                $schema = $schema->edit()
                    ->dropTable($originalTableName)
                    ->addTable($newTable)
                    ->create();

                $table = $newTable;
            }
        }

        // After all classes have been processed and all FK metadata collected,
        // apply the non-conflicting FK constraints to their respective tables
        $schemaEditor = $schema->edit();

        foreach ($addedFks as $compositeName => $fkData) {
            // Skip if this composite key was blacklisted due to conflicts
            if (isset($blacklistedFks[$compositeName])) {
                continue;
            }

            // the table might have been dropped by a listener, so we ignore this error
            if ($schema->hasTable($fkData['tableName'])) {
                $schemaEditor->modifyTable(
                    NameParser::parseOptionallyQualifiedName($fkData['tableName']),
                    static function (TableEditor $tableEditor) use ($fkData): void {
                        $tableEditor->addForeignKeyConstraint(new ForeignKeyConstraint(
                            localColumnNames: $fkData['localColumns'],
                            foreignTableName: $fkData['foreignTableName'],
                            foreignColumnNames: $fkData['foreignColumns'],
                            options: $fkData['fkOptions'],
                            name: $fkData['name'] ?? '',
                        ));
                    },
                );
            }
        }

        $schema = $schemaEditor->create();

        $schemaEventArgs = new GenerateSchemaEventArgs($this->em, $schema, $operation);
        $eventManager->dispatchEvent(
            ToolEvents::postGenerateSchema,
            $schemaEventArgs,
        );

        // Always retrieve the schema (listener may have mutated it)
        return $schemaEventArgs->getSchema();
    }

    /**
     * Gets a portable column definition as required by the DBAL for the discriminator
     * column of a class.
     */
    private function addDiscriminatorColumnDefinition(ClassMetadata $class, TableEditor $table): void
    {
        $discrColumn = $class->discriminatorColumn;
        assert($discrColumn !== null);

        if (strtolower($discrColumn->type) === 'string' && ! isset($discrColumn->length)) {
            $discrColumn->type   = 'string';
            $discrColumn->length = $this->em->getConfiguration()->getDefaultStringTypeSchemaLength();
        }

        $options = [
            'length'    => $discrColumn->length ?? null,
            'notnull'   => true,
        ];

        if (isset($discrColumn->columnDefinition)) {
            $options['columnDefinition'] = $discrColumn->columnDefinition;
        }

        $options = $this->gatherColumnOptions($discrColumn) + $options;
        $this->addColumn($table, $discrColumn->name, $discrColumn->type, $options);
    }

    /**
     * Gathers the column definitions as required by the DBAL of all field mappings
     * found in the given class.
     *
     * @param array<string, true> $columnNames
     */
    private function gatherColumns(ClassMetadata $class, TableEditor $table, array &$columnNames): void
    {
        foreach ($class->fieldMappings as $mapping) {
            if ($class->isInheritanceTypeSingleTable() && isset($mapping->inherited)) {
                continue;
            }

            $this->gatherColumn($class, $mapping, $table, $columnNames);
        }
    }

    /**
     * Creates a column definition as required by the DBAL from an ORM field mapping definition.
     *
     * @param ClassMetadata       $class       The class that owns the field mapping.
     * @param array<string, true> $columnNames
     * @phpstan-param FieldMapping $mapping The field mapping.
     */
    private function gatherColumn(
        ClassMetadata $class,
        FieldMapping $mapping,
        TableEditor $table,
        array &$columnNames,
    ): void {
        $columnName = $this->quoteStrategy->getColumnName($mapping->fieldName, $class, $this->platform);
        $columnType = $mapping->type;

        $options            = [];
        $options['length']  = $mapping->length ?? null;
        $options['notnull'] = isset($mapping->nullable) ? ! $mapping->nullable : true;
        if ($class->isInheritanceTypeSingleTable() && $class->parentClasses) {
            $options['notnull'] = false;
        }

        $options['platformOptions']            = [];
        $options['platformOptions']['version'] = $class->isVersioned && $class->versionField === $mapping->fieldName;

        if (strtolower($columnType) === 'string' && $options['length'] === null) {
            $options['length'] = $this->em->getConfiguration()->getDefaultStringTypeSchemaLength();
        }

        if (isset($mapping->precision)) {
            $options['precision'] = $mapping->precision;
        }

        if (isset($mapping->scale)) {
            $options['scale'] = $mapping->scale;
        }

        /** @phpstan-ignore property.deprecated */
        if (isset($mapping->default)) {
            /** @phpstan-ignore property.deprecated */
            $options['default'] = $mapping->default;
        }

        if (isset($mapping->columnDefinition)) {
            $options['columnDefinition'] = $mapping->columnDefinition;
        }

        // the 'default' option can be overwritten here
        $options = $this->gatherColumnOptions($mapping) + $options;

        if (isset($options['default']) && interface_exists(DefaultExpression::class)) {
            if (
                in_array($mapping->type, [
                    Types::DATETIME_MUTABLE,
                    Types::DATETIME_IMMUTABLE,
                    Types::DATETIMETZ_MUTABLE,
                    Types::DATETIMETZ_IMMUTABLE,
                ], true)
                && $options['default'] === $this->platform->getCurrentTimestampSQL()
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/12252',
                    <<<'DEPRECATION'
                    Using "%s" as a default value for datetime fields is deprecated and
                    will not be supported in Doctrine ORM 4.0.
                    Pass a `Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp` instance instead.
                    DEPRECATION,
                    $this->platform->getCurrentTimestampSQL(),
                );
                $options['default'] = new CurrentTimestamp();
            }

            if (
                in_array($mapping->type, [Types::TIME_MUTABLE, Types::TIME_IMMUTABLE], true)
                && $options['default'] === $this->platform->getCurrentTimeSQL()
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/12252',
                    <<<'DEPRECATION'
                    Using "%s" as a default value for time fields is deprecated and
                    will not be supported in Doctrine ORM 4.0.
                    Pass a `Doctrine\DBAL\Schema\DefaultExpression\CurrentTime` instance instead.
                    DEPRECATION,
                    $this->platform->getCurrentTimeSQL(),
                );
                $options['default'] = new CurrentTime();
            }

            if (
                in_array($mapping->type, [Types::DATE_MUTABLE, Types::DATE_IMMUTABLE], true)
                && $options['default'] === $this->platform->getCurrentDateSQL()
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/issues/12252',
                    <<<'DEPRECATION'
                    Using "%s" as a default value for date fields is deprecated and
                    will not be supported in Doctrine ORM 4.0.
                    Pass a `Doctrine\DBAL\Schema\DefaultExpression\CurrentDate` instance instead.
                    DEPRECATION,
                    $this->platform->getCurrentDateSQL(),
                );
                $options['default'] = new CurrentDate();
            }
        }

        if ($class->isIdGeneratorIdentity() && $class->getIdentifierFieldNames() === [$mapping->fieldName]) {
            $options['autoincrement'] = true;
        }

        if ($class->isInheritanceTypeJoined() && $class->name !== $class->rootEntityName) {
            $options['autoincrement'] = false;
        }

        if (isset($columnNames[$columnName])) {
            $this->modifyColumn($table, $columnName, $options);
        } else {
            $this->addColumn($table, $columnName, $columnType, $options);
            $columnNames[$columnName] = true;
        }

        $isUnique = $mapping->unique ?? false;
        if ($isUnique && $this->getPrimaryKeyColumnNames($class) !== [$columnName]) {
            $this->addUniqueIndex($table, [$columnName]);
        }

        $isIndex = $mapping->index ?? false;
        if ($isIndex) {
            $this->addIndex($table, [$columnName]);
        }
    }

    /**
     * @param ClassMetadata<object> $class
     * @param IndexMapping          $indexData
     */
    private function createTableIndexEditor(ClassMetadata $class, array $indexData): IndexEditor
    {
        $indexEditor = Index::editor();

        foreach ($this->getIndexColumns($class, $indexData) as $columnName) {
            $indexEditor->addColumn(new IndexedColumn(
                NameParser::parseUnqualifiedName($columnName),
                null,
            ));
        }

        self::applyIndexFlags($indexEditor, (array) ($indexData['flags'] ?? []));

        if (isset($indexData['options']['where'])) {
            $indexEditor->setPredicate($indexData['options']['where']);
        }

        return $indexEditor;
    }

    /**
     * Whether an index on the table being built is made redundant by one of the table's unique constraints.
     */
    private function isRedundantForTableUniqueIndexes(TableEditor $table, Index $candidate): bool
    {
        if ($table !== $this->uniqueConstraintsTable) {
            return false;
        }

        foreach ($this->tableUniqueIndexes as $uniqueIndex) {
            if (self::isIndexRedundantForUniqueConstraint($candidate, $uniqueIndex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param ClassMetadata<object> $class
     *
     * @return list<string>
     */
    private function getPrimaryKeyColumnNames(ClassMetadata $class): array
    {
        $pkColumns = [];

        foreach ($class->identifier as $identifierField) {
            if (isset($class->fieldMappings[$identifierField])) {
                $pkColumns[] = $this->quoteStrategy->getColumnName($identifierField, $class, $this->platform);
            } elseif (isset($class->associationMappings[$identifierField])) {
                $assoc = $class->associationMappings[$identifierField];
                assert($assoc->isToOneOwningSide());

                foreach ($assoc->joinColumns as $joinColumn) {
                    $pkColumns[] = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
                }
            }
        }

        return $pkColumns;
    }

    /**
     * Whether the given join column of a to-one association is the whole primary key of its table,
     * in which case a unique index on it would be redundant.
     */
    private function isSoleToOnePrimaryKeyColumn(AssociationMapping $mapping, string $quotedColumnName): bool
    {
        if (! $mapping->isToOneOwningSide()) {
            return false;
        }

        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);

        return $this->getPrimaryKeyColumnNames($sourceClass) === [$quotedColumnName];
    }

    /** @param non-empty-list<string> $columnNames */
    private function addIndex(TableEditor $table, array $columnNames): void
    {
        if ($this->isRedundantForTableUniqueIndexes($table, new Index('tmp', $columnNames, false, false))) {
            return;
        }

        $editor = Index::editor();

        foreach ($columnNames as $columnName) {
            $editor->addColumn(new IndexedColumn(NameParser::parseUnqualifiedName($columnName), null));
        }

        $table->addIndex($editor);
    }

    /**
     * @param non-empty-list<string>                                            $columnNames
     * @param array{lengths?: list<int<1, max>|null>, where?: non-empty-string} $indexOptions
     */
    private function addUniqueIndex(TableEditor $table, array $columnNames, string|null $indexName = null, array $indexOptions = []): void
    {
        // A unique constraint declared on the table replaces simple unique indexes on the same columns
        // (e.g. from "unique: true" on a column). Constraints themselves are added through this method too.
        if (
            $indexName === null
            && $this->isRedundantForTableUniqueIndexes($table, new Index('tmp', $columnNames, true, false, [], $indexOptions))
        ) {
            return;
        }

        $editor = Index::editor()->setType(IndexType::UNIQUE);
        if ($indexName !== null) {
            $editor->setName(NameParser::parseUnqualifiedName($indexName));
        }

        foreach ($columnNames as $position => $columnName) {
            $editor->addColumn(new IndexedColumn(
                NameParser::parseUnqualifiedName($columnName),
                $indexOptions['lengths'][$position] ?? null,
            ));
        }

        if (isset($indexOptions['where'])) {
            $editor->setPredicate($indexOptions['where']);
        }

        $table->addIndex($editor);
    }

    /** @param array<string, mixed> $options */
    private function addColumn(TableEditor $table, string $name, string $typeName, array $options): void
    {
        $column = Column::editor()
            ->setName(NameParser::parseUnqualifiedName($name))
            ->setTypeName($typeName);
        self::setColumnOptions($column, $options);
        $column = $column->create();

        foreach ($options['platformOptions'] ?? [] as $key => $value) {
            if (
                $key !== 'version'
                && ! in_array($key, [
                    'charset',
                    'collation',
                    'min',
                    'max',
                    'enumType',
                    SQLServerPlatform::OPTION_DEFAULT_CONSTRAINT_NAME,
                ], true)
            ) {
                Deprecation::trigger(
                    'doctrine/orm',
                    'https://github.com/doctrine/orm/pull/12733',
                    'Passing the custom platform option "%s" through the "options" of a mapping is deprecated'
                    . ' and will not be supported with DBAL 5, which removes column platform options.',
                    $key,
                );

                /** @phpstan-ignore function.alreadyNarrowedType (the method is removed in DBAL 5) */
                if (! method_exists($column, 'setPlatformOption')) {
                    /** @phpstan-ignore staticMethod.deprecatedClass (this exception is meant for such cases) */
                    throw NotSupportedException::createForDbal5(
                        sprintf('Setting the custom column platform option "%s" on column "%s"', $key, $name),
                    );
                }

                /** @phpstan-ignore method.deprecated (ColumnEditor has no setter for custom platform options) */
                $column->setPlatformOption($key, $value);
            }
        }

        $table->addColumn($column);
    }

    /** @param array<string, mixed> $options */
    private static function setColumnOptions(ColumnEditor $column, array $options): void
    {
        $column->setLength($options['length'] ?? null)
            ->setPrecision($options['precision'] ?? null)
            ->setUnsigned($options['unsigned'] ?? false)
            ->setFixed($options['fixed'] ?? false)
            ->setNotNull($options['notnull'] ?? true)
            ->setDefaultValue($options['default'] ?? null)
            ->setAutoincrement($options['autoincrement'] ?? false)
            ->setColumnDefinition($options['columnDefinition'] ?? null);
        if (isset($options['scale'])) {
            $column->setScale($options['scale']);
        }

        if (isset($options['comment'])) {
            $column->setComment($options['comment']);
        }

        if (isset($options['values'])) {
            $column->setValues($options['values']);
        }

        $platformOptions = $options['platformOptions'] ?? [];
        if (isset($platformOptions['charset'])) {
            $column->setCharset($platformOptions['charset']);
        }

        if (isset($platformOptions['collation'])) {
            $column->setCollation($platformOptions['collation']);
        }

        if (isset($platformOptions['min'])) {
            $column->setMinimumValue($platformOptions['min']);
        }

        if (isset($platformOptions['max'])) {
            $column->setMaximumValue($platformOptions['max']);
        }

        if (isset($platformOptions['enumType'])) {
            $column->setEnumType($platformOptions['enumType']);
        }

        if (isset($platformOptions[SQLServerPlatform::OPTION_DEFAULT_CONSTRAINT_NAME])) {
            $column->setDefaultConstraintName($platformOptions[SQLServerPlatform::OPTION_DEFAULT_CONSTRAINT_NAME]);
        }
    }

    /** @param array<string, mixed> $options */
    private function modifyColumn(TableEditor $table, string $columnName, array $options): void
    {
        $table->modifyColumn(NameParser::parseUnqualifiedName($columnName), static function (ColumnEditor $column) use ($options): void {
            self::setColumnOptions($column, $options);
        });
    }

    /**
     * Gathers the SQL for properly setting up the relations of the given class.
     * This includes the SQL for foreign key constraints and join tables.
     *
     * @param array<string, true> $columnNames
     * @phpstan-param array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>,
     *                  localColumns: list<string>,
     *                  fkOptions: array{onDelete?: string, deferrable?: bool, deferred?: bool},
     *                  name: string|null,
     *                  tableName: string,
     *                  table: TableEditor
     *              }>                               $addedFks
     * @phpstan-param array<string, bool>              $blacklistedFks
     * @phpstan-param list<Table>                      $joinTablesToAdd
     *
     * @throws NotSupported
     */
    private function gatherRelationsSql(
        ClassMetadata $class,
        TableEditor $table,
        string $entityTableName,
        array &$columnNames,
        Schema &$schema,
        array &$addedFks,
        array &$blacklistedFks,
        SchemaConfig $schemaConfig,
        array &$joinTablesToAdd,
    ): void {
        foreach ($class->associationMappings as $id => $mapping) {
            if (isset($mapping->inherited) && ! in_array($id, $class->identifier, true)) {
                continue;
            }

            $foreignClass = $this->em->getClassMetadata($mapping->targetEntity);

            if ($mapping->isToOneOwningSide()) {
                $primaryKeyColumns = []; // PK is unnecessary for this relation-type

                $this->gatherRelationJoinColumns(
                    $mapping->joinColumns,
                    $table,
                    $entityTableName,
                    $columnNames,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks,
                    null,  // For ToOne, FK name comes from JoinColumn, not a parameter
                );
            } elseif ($mapping instanceof ManyToManyOwningSideMapping) {
                // create join table
                $joinTable = $mapping->joinTable;

                $tableName = $this->quoteStrategy->getJoinTableName($mapping, $foreignClass, $this->platform);

                $theJoinTable = Table::editor()
                    ->setName(NameParser::parseOptionallyQualifiedName($tableName))
                    ->setConfiguration($schemaConfig->toTableConfiguration())
                    ->setOptions($joinTable->options + $schemaConfig->getDefaultTableOptions());

                $primaryKeyColumns = [];
                $joinColumnNames   = [];

                // Build first FK constraint (relation table => source table)
                $this->gatherRelationJoinColumns(
                    $joinTable->joinColumns,
                    $theJoinTable,
                    $tableName,
                    $joinColumnNames,
                    $class,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks,
                    $joinTable->foreignKeyName ?? null,
                );

                // Build second FK constraint (relation table => target table)
                $this->gatherRelationJoinColumns(
                    $joinTable->inverseJoinColumns,
                    $theJoinTable,
                    $tableName,
                    $joinColumnNames,
                    $foreignClass,
                    $mapping,
                    $primaryKeyColumns,
                    $addedFks,
                    $blacklistedFks,
                    $joinTable->inverseForeignKeyName ?? null,
                );

                self::addPrimaryKeyConstraint($theJoinTable, $primaryKeyColumns);
                $joinTablesToAdd[] = $theJoinTable->create();
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
     * @phpstan-return array{ClassMetadata, string}|null
     */
    private function getDefiningClass(ClassMetadata $class, string $referencedColumnName): array|null
    {
        $referencedFieldName = $class->getFieldName($referencedColumnName);

        if ($class->hasField($referencedFieldName)) {
            return [$class, $referencedFieldName];
        }

        if (in_array($referencedColumnName, $class->getIdentifierColumnNames(), true)) {
            // it seems to be an entity as foreign key
            foreach ($class->getIdentifierFieldNames() as $fieldName) {
                if (
                    $class->hasAssociation($fieldName)
                    && $class->getSingleAssociationJoinColumnName($fieldName) === $referencedColumnName
                ) {
                    return $this->getDefiningClass(
                        $this->em->getClassMetadata($class->associationMappings[$fieldName]->targetEntity),
                        $class->getSingleAssociationReferencedJoinColumnName($fieldName),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Gathers columns and fk constraints that are required for one part of relationship.
     *
     * @param array<string, true> $columnNames
     * @phpstan-param list<JoinColumnMapping>          $joinColumns
     * @phpstan-param list<string>                     $primaryKeyColumns
     * @phpstan-param array<string, array{
     *                  foreignTableName: string,
     *                  foreignColumns: list<string>,
     *                  localColumns: list<string>,
     *                  fkOptions: array{onDelete?: string, deferrable?: bool, deferred?: bool},
     *                  name: string|null,
     *                  tableName: string,
     *                  table: TableEditor
     *              }>                               $addedFks
     * @phpstan-param array<string,bool>               $blacklistedFks
     *
     * @throws MissingColumnException
     */
    private function gatherRelationJoinColumns(
        array $joinColumns,
        TableEditor $theJoinTable,
        string $quotedTableName,
        array &$columnNames,
        ClassMetadata $class,
        AssociationMapping $mapping,
        array &$primaryKeyColumns,
        array &$addedFks,
        array &$blacklistedFks,
        string|null $foreignKeyName = null,
    ): void {
        $localColumns   = [];
        $foreignColumns = [];
        /** @var array{onDelete?: string, deferrable?: bool, deferred?: bool} $fkOptions */
        $fkOptions         = [];
        $foreignTableName  = $this->quoteStrategy->getTableName($class, $this->platform);
        $uniqueConstraints = [];

        foreach ($joinColumns as $joinColumn) {
            [$definingClass, $referencedFieldName] = $this->getDefiningClass(
                $class,
                $joinColumn->referencedColumnName,
            );

            if (! $definingClass) {
                throw MissingColumnException::fromColumnSourceAndTarget(
                    $joinColumn->referencedColumnName,
                    $mapping->sourceEntity,
                    $mapping->targetEntity,
                );
            }

            $quotedColumnName    = $this->quoteStrategy->getJoinColumnName($joinColumn, $class, $this->platform);
            $quotedRefColumnName = $this->quoteStrategy->getReferencedJoinColumnName(
                $joinColumn,
                $class,
                $this->platform,
            );

            $primaryKeyColumns[] = $quotedColumnName;
            $localColumns[]      = $quotedColumnName;
            $foreignColumns[]    = $quotedRefColumnName;

            if (! isset($columnNames[$quotedColumnName])) {
                // Only add the column to the table if it does not exist already.
                // It might exist already if the foreign key is mapped into a regular
                // property as well.

                $fieldMapping = $definingClass->getFieldMapping($referencedFieldName);

                $columnOptions = ['notnull' => false];

                if (isset($joinColumn->columnDefinition)) {
                    $columnOptions['columnDefinition'] = $joinColumn->columnDefinition;
                } elseif (isset($fieldMapping->columnDefinition)) {
                    $columnOptions['columnDefinition'] = $fieldMapping->columnDefinition;
                }

                if (isset($joinColumn->nullable)) {
                    $columnOptions['notnull'] = ! $joinColumn->nullable;
                }

                $columnOptions += $this->gatherColumnOptions($fieldMapping);

                if (isset($fieldMapping->length)) {
                    $columnOptions['length'] = $fieldMapping->length;
                }

                if ($fieldMapping->type === 'decimal') {
                    $columnOptions['scale']     = $fieldMapping->scale;
                    $columnOptions['precision'] = $fieldMapping->precision;
                }

                $columnOptions = $this->gatherColumnOptions($joinColumn) + $columnOptions;

                $this->addColumn($theJoinTable, $quotedColumnName, $fieldMapping->type, $columnOptions);
                $columnNames[$quotedColumnName] = true;
            }

            if (isset($joinColumn->unique) && $joinColumn->unique === true && ! $this->isSoleToOnePrimaryKeyColumn($mapping, $quotedColumnName)) {
                $uniqueConstraints[] = ['columns' => [$quotedColumnName]];
            }

            if (isset($joinColumn->onDelete)) {
                $fkOptions['onDelete'] = $joinColumn->onDelete;
            }

            if (isset($joinColumn->deferrable)) {
                $fkOptions['deferrable'] = $joinColumn->deferrable;
            }
        }

        // Prefer unique constraints over implicit simple indexes created for foreign keys.
        // Also avoids index duplication.
        foreach ($uniqueConstraints as $indexName => $unique) {
            $this->addUniqueIndex($theJoinTable, $unique['columns'], is_numeric($indexName) ? null : $indexName);
        }

        // Extract and validate foreign key name from join columns
        $foreignKeyNames = [];

        foreach ($joinColumns as $joinColumn) {
            if ($joinColumn->foreignKeyName !== null) {
                $foreignKeyNames[] = $joinColumn->foreignKeyName;
            }
        }

        $extractedForeignKeyName = match (count($foreignKeyNames)) {
            0 => null,
            1 => $foreignKeyNames[0],
            default => throw new RuntimeException(sprintf(
                'Only one JoinColumn in a composite foreign key may specify foreignKeyName. ' .
                '%s::$%s has %d join columns with foreignKeyName specified.',
                $mapping->sourceEntity,
                $mapping->fieldName,
                count($foreignKeyNames),
            )),
        };

        // Use parameter FK name (from JoinTable) if provided, otherwise use extracted from JoinColumns
        $finalForeignKeyName = $foreignKeyName ?? $extractedForeignKeyName;

        $tableName     = self::getTableKey($quotedTableName);
        $compositeName = $tableName . '.' . implode('', $localColumns);

        // Check if an FK constraint already exists for this composite key (table + columns)
        if (isset($addedFks[$compositeName])) {
            $existingFk = $addedFks[$compositeName];

            // Determine if the new FK targets the same table/columns as the existing one.
            $isForeignTableIdentical    = $foreignTableName === $existingFk['foreignTableName'];
            $areForeignColumnsIdentical = count(array_diff($foreignColumns, $existingFk['foreignColumns'])) === 0
                && count(array_diff($existingFk['foreignColumns'], $foreignColumns)) === 0;

            if ($isForeignTableIdentical && $areForeignColumnsIdentical) {
                // Both associations reference the same foreign table and columns, so a
                // single foreign key can satisfy both, even if their JoinColumn
                // configuration (e.g. nullable, onDelete, deferrable) differs. There is no
                // single definition that could honor two conflicting configurations at
                // once, so the association registered first keeps "winning" here, exactly
                // like before the two-pass rewrite introduced in GH-12528: the constraint
                // is no longer silently dropped just because the JoinColumn options disagree.
                return;
            }

            // FK exists but targets a different table/columns (conflicting FK) - blacklist
            // this composite key. No FK will be added for this composite key, but we need
            // to ensure an index exists since FKs normally create indexes automatically.
            if (! isset($blacklistedFks[$compositeName])) {
                // Add an index for the local columns since we won't be adding a FK
                // (FKs normally create implicit indexes).
                if ($localColumns !== []) {
                    $this->addIndex($theJoinTable, $localColumns);
                }
            }

            $blacklistedFks[$compositeName] = true;
        } elseif (! isset($blacklistedFks[$compositeName])) {
            // No existing FK and not blacklisted - store FK metadata for application phase
            $addedFks[$compositeName] = [
                'foreignTableName' => $foreignTableName,
                'foreignColumns' => $foreignColumns,
                'localColumns' => $localColumns,
                'fkOptions' => $fkOptions,
                'name' => $finalForeignKeyName,
                'tableName' => $tableName,
                'table' => $theJoinTable,
            ];
        }
    }

    /**
     * @param list<string>         $localColumns
     * @param list<string>         $foreignColumns
     * @param array<string, mixed> $fkOptions
     */
    private function addForeignKeyConstraint(
        TableEditor $table,
        string $foreignTableName,
        array $localColumns,
        array $foreignColumns,
        array $fkOptions,
    ): void {
        $table->addForeignKeyConstraint(new ForeignKeyConstraint(
            localColumnNames: $localColumns,
            foreignTableName: $foreignTableName,
            foreignColumnNames: $foreignColumns,
            options: $fkOptions,
        ));
    }

    /** @return mixed[] */
    private function gatherColumnOptions(JoinColumnMapping|FieldMapping|DiscriminatorColumnMapping $mapping): array
    {
        $mappingOptions = $mapping->options ?? [];

        if (isset($mapping->enumType)) {
            $mappingOptions['enumType'] = $mapping->enumType;
        }

        if (($mappingOptions['default'] ?? null) instanceof BackedEnum) {
            $mappingOptions['default'] = $mappingOptions['default']->value;
        }

        if (empty($mappingOptions)) {
            return [];
        }

        $options                    = array_intersect_key($mappingOptions, array_flip(self::KNOWN_COLUMN_OPTIONS));
        $options['platformOptions'] = array_diff_key($mappingOptions, $options);

        return $options;
    }

    /** @param non-empty-array<non-empty-string> $primaryKeyColumns */
    private function addPrimaryKeyConstraint(TableEditor $table, array $primaryKeyColumns): void
    {
        $primaryKeyColumnNames = [];
        foreach ($primaryKeyColumns as $primaryKeyColumn) {
            $primaryKeyColumnNames[] = NameParser::parseUnqualifiedName($primaryKeyColumn);
        }

        $table->addPrimaryKeyConstraint(new PrimaryKeyConstraint(null, $primaryKeyColumnNames, true));
    }

    /**
     * @param array<array-key, mixed> $flags
     *
     * @return array<string, mixed>
     */
    private static function normalizeIndexFlags(array $flags): array
    {
        $names = [];

        foreach ($flags as $key => $value) {
            $names[strtolower(is_int($key) ? (string) $value : $key)] = is_int($key) ? true : $value;
        }

        return $names;
    }

    /**
     * Applies platform-specific index flags, such as 'fulltext', to the index being built.
     *
     * Flags are given as a list of names, e.g. `['fulltext']`, but a map of
     * `name => bool` is accepted as well.
     *
     * @param array<array-key, mixed> $flags
     */
    private static function applyIndexFlags(IndexEditor $indexEditor, array $flags): void
    {
        $names = self::normalizeIndexFlags($flags);

        if (isset($names['clustered'])) {
            $indexEditor->setIsClustered((bool) $names['clustered']);
        }

        // fulltext and spatial are mutually exclusive index types
        if (count(array_intersect_key($names, ['fulltext' => true, 'spatial' => true])) > 1) {
            throw new InvalidArgumentException('An index cannot be both fulltext and spatial.');
        }

        if (isset($names['fulltext'])) {
            $indexEditor->setType(IndexType::FULLTEXT);
        }

        if (isset($names['spatial'])) {
            $indexEditor->setType(IndexType::SPATIAL);
        }
    }

    /**
     * Unique constraints can replace ordinary indexes on the same columns, but not
     * platform-specific indexes such as fulltext or spatial whose flags they do not fulfill.
     */
    private static function isIndexRedundantForUniqueConstraint(Index $existingIndex, Index $uniqueIndex): bool
    {
        return $existingIndex->isFulfilledBy($uniqueIndex)
            && self::indexFlagsAreCompatible($existingIndex);
    }

    /**
     * Whether a unique constraint can replace $existingIndex given its platform-specific type.
     *
     * Unflagged indexes remain compatible with unique constraints so they can still be
     * deduplicated. Fulltext and spatial indexes keep their own semantics.
     */
    private static function indexFlagsAreCompatible(Index $existingIndex): bool
    {
        return $existingIndex->getType() !== IndexType::FULLTEXT
            && $existingIndex->getType() !== IndexType::SPATIAL;
    }

    private static function getTableKey(string $quotedTableName): string
    {
        return NameParser::parseOptionallyQualifiedName($quotedTableName)->toString();
    }
}
