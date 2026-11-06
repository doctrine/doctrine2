<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraintEditor;
use Doctrine\DBAL\Schema\Index as DbalIndex;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraintEditor;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Types\Type;

use function array_key_exists;
use function array_keys;
use function array_map;
use function class_exists;
use function enum_exists;
use function implode;
use function method_exists;
use function sort;
use function sprintf;
use function strcasecmp;

/**
 * Cross-version assertions on DBAL schema objects.
 *
 * Asserting the generated schema rather than rendered SQL keeps tests independent
 * of DBAL's SQL formatting, which changes between minor versions.
 */
trait SchemaAssertions
{
    /** @param array<string, array{type: string, nullable?: bool, length?: int, autoincrement?: bool, precision?: int, scale?: int, unsigned?: bool}> $expectedColumns */
    private static function assertTableColumns(DbalTable $table, array $expectedColumns): void
    {
        $expectedNames = array_keys($expectedColumns);
        $actualNames   = array_map(
            static fn (Column $column): string => self::objectName($column),
            $table->getColumns(),
        );

        sort($expectedNames);
        sort($actualNames);

        self::assertSame($expectedNames, $actualNames, 'The table has unexpected columns.');

        foreach ($expectedColumns as $name => $expected) {
            $column = $table->getColumn($name);

            self::assertSame(
                $expected['type'],
                self::columnTypeName($column),
                sprintf('Unexpected type for column "%s".', $name),
            );

            if (array_key_exists('nullable', $expected)) {
                self::assertSame(
                    ! $expected['nullable'],
                    $column->getNotnull(),
                    sprintf('Unexpected nullability for column "%s".', $name),
                );
            }

            if (isset($expected['length'])) {
                self::assertSame(
                    $expected['length'],
                    $column->getLength(),
                    sprintf('Unexpected length for column "%s".', $name),
                );
            }

            if (isset($expected['autoincrement'])) {
                self::assertSame(
                    $expected['autoincrement'],
                    $column->getAutoincrement(),
                    sprintf('Unexpected autoincrement flag for column "%s".', $name),
                );
            }

            if (isset($expected['precision'])) {
                self::assertSame(
                    $expected['precision'],
                    $column->getPrecision(),
                    sprintf('Unexpected precision for column "%s".', $name),
                );
            }

            if (isset($expected['scale'])) {
                self::assertSame(
                    $expected['scale'],
                    $column->getScale(),
                    sprintf('Unexpected scale for column "%s".', $name),
                );
            }

            if (isset($expected['unsigned'])) {
                self::assertSame(
                    $expected['unsigned'],
                    $column->getUnsigned(),
                    sprintf('Unexpected unsigned flag for column "%s".', $name),
                );
            }
        }
    }

    /** @return list<string> */
    private static function primaryKeyColumns(DbalTable $table): array
    {
        if (class_exists(PrimaryKeyConstraintEditor::class)) {
            $primaryKey = $table->getPrimaryKeyConstraint();
            self::assertNotNull($primaryKey);

            return array_map(
                static fn (UnqualifiedName $name): string => $name->toString(),
                $primaryKey->getColumnNames(),
            );
        }

        $primaryKey = $table->getPrimaryKey();
        self::assertNotNull($primaryKey);

        return self::indexColumnNames($primaryKey);
    }

    /** @param list<string> $expectedColumns */
    private static function assertTableIndex(
        DbalTable $table,
        string $name,
        array $expectedColumns,
        bool $unique = false,
    ): void {
        $index = self::findTableIndex($table, $name);
        self::assertNotNull($index, sprintf('Index "%s" does not exist.', $name));

        self::assertSame(
            $expectedColumns,
            self::indexColumnNames($index),
            sprintf('Unexpected columns for index "%s".', $name),
        );
        self::assertSame(
            $unique,
            self::schemaIndexIsUnique($index),
            sprintf('Unexpected uniqueness for index "%s".', $name),
        );
    }

    /** @param list<string> $localColumns @param list<string> $foreignColumns */
    private static function assertTableForeignKey(
        DbalTable $table,
        array $localColumns,
        string $foreignTableName,
        array $foreignColumns,
    ): void {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if (class_exists(ForeignKeyConstraintEditor::class)) {
                $actualLocalColumns     = array_map(
                    static fn (UnqualifiedName $name): string => $name->toString(),
                    $foreignKey->getReferencingColumnNames(),
                );
                $actualForeignColumns   = array_map(
                    static fn (UnqualifiedName $name): string => $name->toString(),
                    $foreignKey->getReferencedColumnNames(),
                );
                $actualForeignTableName = $foreignKey->getReferencedTableName()->toString();
            } else {
                $actualLocalColumns     = $foreignKey->getLocalColumns();
                $actualForeignColumns   = $foreignKey->getForeignColumns();
                $actualForeignTableName = $foreignKey->getForeignTableName();
            }

            if (
                $actualLocalColumns === $localColumns
                && $actualForeignTableName === $foreignTableName
                && $actualForeignColumns === $foreignColumns
            ) {
                return;
            }
        }

        self::fail(sprintf(
            'Foreign key from (%s) to %s (%s) was not found.',
            implode(', ', $localColumns),
            $foreignTableName,
            implode(', ', $foreignColumns),
        ));
    }

    private static function assertTableOptions(DbalTable $table): void
    {
        $options = $table->getOptions();

        self::assertSame('utf8mb4', $options['charset'] ?? null, 'Unexpected table charset.');
        self::assertSame('utf8mb4_unicode_ci', $options['collation'] ?? null, 'Unexpected table collation.');
        self::assertSame('InnoDB', $options['engine'] ?? null, 'Unexpected table engine.');
    }

    private static function columnTypeName(Column $column): string
    {
        if (method_exists($column, 'getTypeName')) {
            return $column->getTypeName();
        }

        return Type::getTypeRegistry()->lookupName($column->getType());
    }

    /** @return list<string> */
    private static function indexColumnNames(DbalIndex $index): array
    {
        if (method_exists(DbalIndex::class, 'getIndexedColumns')) {
            return array_map(
                static fn (IndexedColumn $indexedColumn): string => $indexedColumn->getColumnName()->toString(),
                $index->getIndexedColumns(),
            );
        }

        return $index->getColumns();
    }

    private static function findTableIndex(DbalTable $table, string $name): DbalIndex|null
    {
        foreach ($table->getIndexes() as $index) {
            if (strcasecmp(self::objectName($index), $name) === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function objectName(Column|DbalIndex $object): string
    {
        if (method_exists($object, 'getObjectName')) {
            return $object->getObjectName()->getIdentifier()->getValue();
        }

        return $object->getName();
    }

    private static function schemaIndexIsUnique(DbalIndex $index): bool
    {
        if (enum_exists(IndexType::class)) {
            return $index->getType() === IndexType::UNIQUE;
        }

        return $index->isUnique();
    }
}
