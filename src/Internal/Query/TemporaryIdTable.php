<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Query;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Utility\PersisterHelper;

use function implode;
use function is_string;

/**
 * Builds the DDL of the temporary table in which the identifiers of the affected rows are kept while
 * the tables of a class table inheritance hierarchy are updated or deleted one after the other: bulk DQL
 * UPDATE and DELETE statements, and the deletion of a one-to-many collection with orphan removal.
 *
 * The identifiers are matched against the identifier columns of the tables of the hierarchy
 * (`WHERE (id) IN (SELECT id FROM temporary_table)`), so the columns of the temporary table are
 * declared like the identifier columns of the root table:
 *
 * - Type, length, precision, scale, fixed and unsigned. Otherwise the table could not store every
 *   identifier, or the platform refuses to declare it: DBAL 4 requires the length of (N)VARCHAR
 *   columns on MySQL, MariaDB and SQL Server.
 * - Character set and collation. Otherwise MySQL and MariaDB refuse to compare string identifiers
 *   that do not share their collation ("Illegal mix of collations"), and a case insensitive
 *   temporary table cannot hold identifiers that differ by case only, unlike the case sensitive
 *   tables it is compared with. The character set and the collation are the ones of the column
 *   mapping or, on MySQL and MariaDB, the default ones of the table, like the SchemaTool declares
 *   them for the tables of the entities.
 *
 * A custom `columnDefinition` of an identifier is not taken over.
 *
 * @internal
 */
final class TemporaryIdTable
{
    /**
     * Returns the statement that creates the temporary identifier table of the hierarchy of the given root class.
     *
     * @param ClassMetadata<object> $rootClass
     */
    public static function getCreateSQL(string $tableName, ClassMetadata $rootClass, EntityManagerInterface $em): string
    {
        $platform      = $em->getConnection()->getDatabasePlatform();
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $columns       = [];

        foreach ($idColumnNames as $columnName) {
            $columns[$columnName] = self::getColumnDefinition(
                $columnName,
                PersisterHelper::getFieldMappingOfColumn($columnName, $rootClass, $em),
                $em,
            );
        }

        return $platform->getCreateTemporaryTableSnippetSQL() . ' ' . $tableName . ' ('
            . $platform->getColumnDeclarationListSQL($columns) . ', PRIMARY KEY(' . implode(',', $idColumnNames) . '))'
            . self::getTableOptionsSQL($columns, $rootClass, $em);
    }

    /** @return array<string, mixed> The definition in the format expected by the column declaration of the DBAL platform. */
    private static function getColumnDefinition(string $columnName, FieldMapping $mapping, EntityManagerInterface $em): array
    {
        $options = $mapping->options ?? [];
        $length  = $mapping->length;

        // The SchemaTool applies the same default when it creates the table of the entity.
        if ($length === null && $mapping->type === Types::STRING) {
            $length = $em->getConfiguration()->getDefaultStringTypeSchemaLength();
        }

        $definition = [
            'name' => $columnName,
            // DBAL 4.5 reads the type name from "typeName" and deprecates the type instance under "type",
            // older versions only know the latter and ignore "typeName".
            'type' => Type::getType($mapping->type),
            'typeName' => $mapping->type,
            'notnull' => true,
            'length' => $length,
            'precision' => $mapping->precision,
            'scale' => $mapping->scale,
            'fixed' => $options['fixed'] ?? false,
            'unsigned' => $options['unsigned'] ?? false,
        ];

        // Like the SchemaTool, hand the character set and the collation of the column over to the platform.
        foreach (['charset', 'collation'] as $option) {
            if (isset($options[$option])) {
                $definition[$option] = $options[$option];
            }
        }

        return $definition;
    }

    /**
     * Returns the character set and the collation that the columns get from the table when they declare none.
     *
     * Only MySQL and MariaDB derive them from the table, the other platforms use the ones of the database.
     * They are declared for string identifiers only: the other column types ignore them, and the statements
     * for entities without a string identifier stay as they were.
     *
     * @param array<string, array<string, mixed>> $columns
     * @param ClassMetadata<object>               $rootClass
     */
    private static function getTableOptionsSQL(array $columns, ClassMetadata $rootClass, EntityManagerInterface $em): string
    {
        $connection = $em->getConnection();
        $platform   = $connection->getDatabasePlatform();

        if (! $platform instanceof AbstractMySQLPlatform || ! self::hasStringColumn($columns)) {
            return '';
        }

        $params  = $connection->getParams();
        $options = $rootClass->table['options'] ?? [];

        // The options of the table win over the default ones, the connection charset is the last resort.
        // The "collate" option is deprecated since DBAL 3.
        $charset   = $options['charset'] ?? $params['defaultTableOptions']['charset'] ?? $params['charset'] ?? null;
        $collation = $options['collation'] ?? $options['collate']
            ?? $params['defaultTableOptions']['collation'] ?? $params['defaultTableOptions']['collate'] ?? null;

        $sql = '';

        if (is_string($charset)) {
            $sql .= ' DEFAULT CHARACTER SET ' . $charset;
        }

        if (is_string($collation)) {
            $sql .= ' COLLATE ' . $platform->quoteSingleIdentifier($collation);
        }

        return $sql;
    }

    /** @param array<string, array<string, mixed>> $columns */
    private static function hasStringColumn(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column['type'] instanceof StringType) {
                return true;
            }
        }

        return false;
    }
}
