<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Internal\TypeProviderLocator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Utility\PersisterHelper;
use InvalidArgumentException;
use Override;
use Stringable;

use function in_array;
use function sprintf;

/**
 * A ResultSetMappingBuilder uses the EntityManager to automatically populate entity fields.
 */
class ResultSetMappingBuilder extends ResultSetMapping implements Stringable
{
    use SQLResultCasing;

    /**
     * Picking this rename mode will register entity columns as is,
     * as they are in the database. This can cause clashes when multiple
     * entities are fetched that have columns with the same name.
     */
    final public const int COLUMN_RENAMING_NONE = 1;

    /**
     * Picking custom renaming allows the user to define the renaming
     * of specific columns with a rename array that contains column names as
     * keys and result alias as values.
     */
    final public const int COLUMN_RENAMING_CUSTOM = 2;

    /**
     * Incremental renaming uses a result set mapping internal counter to add a
     * number to each column result, leading to uniqueness. This only works if
     * you use {@see generateSelectClause()} to generate the SELECT clause for
     * you.
     */
    final public const int COLUMN_RENAMING_INCREMENT = 3;

    private int $sqlCounter = 0;

    /** @phpstan-param self::COLUMN_RENAMING_* $defaultRenameMode */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly int $defaultRenameMode = self::COLUMN_RENAMING_NONE,
    ) {
    }

    /**
     * Adds a root entity and all of its fields to the result set.
     *
     * @param class-string          $class          The class name of the root entity.
     * @param string                $alias          The unique alias to use for the root entity.
     * @param array<string, string> $renamedColumns Columns that have been renamed (tableColumnName => queryColumnName).
     * @phpstan-param self::COLUMN_RENAMING_*|null $renameMode
     */
    public function addRootEntityFromClassMetadata(
        string $class,
        string $alias,
        array $renamedColumns = [],
        int|null $renameMode = null,
    ): void {
        $renameMode     = $renameMode ?: $this->defaultRenameMode;
        $columnAliasMap = $this->getColumnAliasMap($class, $renameMode, $renamedColumns);

        $this->addEntityResult($class, $alias);
        $this->addAllClassFields($class, $alias, $columnAliasMap);
    }

    /**
     * Adds a joined entity and all of its fields to the result set.
     *
     * @param class-string          $class          The class name of the joined entity.
     * @param string                $alias          The unique alias to use for the joined entity.
     * @param string                $parentAlias    The alias of the entity result that is the parent of this joined result.
     * @param string                $relation       The association field that connects the parent entity result
     *                                              with the joined entity result.
     * @param array<string, string> $renamedColumns Columns that have been renamed (tableColumnName => queryColumnName).
     * @phpstan-param self::COLUMN_RENAMING_*|null $renameMode
     */
    public function addJoinedEntityFromClassMetadata(
        string $class,
        string $alias,
        string $parentAlias,
        string $relation,
        array $renamedColumns = [],
        int|null $renameMode = null,
    ): void {
        $renameMode     = $renameMode ?: $this->defaultRenameMode;
        $columnAliasMap = $this->getColumnAliasMap($class, $renameMode, $renamedColumns);

        $this->addJoinedEntityResult($class, $alias, $parentAlias, $relation);
        $this->addAllClassFields($class, $alias, $columnAliasMap);
    }

    /**
     * Adds all fields of the given class to the result set mapping (columns and meta fields).
     *
     * @param string[] $columnAliasMap
     * @phpstan-param array<string, string> $columnAliasMap
     *
     * @throws InvalidArgumentException
     */
    protected function addAllClassFields(string $class, string $alias, array $columnAliasMap = []): void
    {
        $classMetadata = $this->em->getClassMetadata($class);
        $platform      = $this->em->getConnection()->getDatabasePlatform();

        if (! $this->isInheritanceSupported($classMetadata)) {
            throw new InvalidArgumentException('ResultSetMapping builder does not currently support your inheritance scheme.');
        }

        foreach ($classMetadata->getColumnNames() as $columnName) {
            $propertyName = $classMetadata->getFieldName($columnName);
            $columnAlias  = $this->getSQLResultCasing($platform, $columnAliasMap[$columnName]);

            if (isset($this->fieldMappings[$columnAlias])) {
                throw new InvalidArgumentException(sprintf(
                    "The column '%s' conflicts with another column in the mapper.",
                    $columnName,
                ));
            }

            $this->addFieldResult($alias, $columnAlias, $propertyName);

            $enumType = $classMetadata->getFieldMapping($propertyName)->enumType ?? null;
            if (! empty($enumType)) {
                $this->addEnumResult($columnAlias, $enumType);
            }
        }

        foreach ($classMetadata->associationMappings as $associationMapping) {
            if ($associationMapping->isToOneOwningSide()) {
                $targetClass  = $this->em->getClassMetadata($associationMapping->targetEntity);
                $isIdentifier = isset($associationMapping->id) && $associationMapping->id === true;

                foreach ($associationMapping->joinColumns as $joinColumn) {
                    $columnName  = $joinColumn->name;
                    $columnAlias = $this->getSQLResultCasing($platform, $columnAliasMap[$columnName]);
                    $columnType  = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $this->em);

                    if (isset($this->metaMappings[$columnAlias])) {
                        throw new InvalidArgumentException(sprintf(
                            "The column '%s' conflicts with another column in the mapper.",
                            $columnAlias,
                        ));
                    }

                    $this->addMetaResult($alias, $columnAlias, $columnName, $isIdentifier, $columnType);
                }
            }
        }
    }

    private function isInheritanceSupported(ClassMetadata $classMetadata): bool
    {
        if (
            $classMetadata->isInheritanceTypeSingleTable()
            && in_array($classMetadata->name, $classMetadata->discriminatorMap, true)
        ) {
            return true;
        }

        return ! ($classMetadata->isInheritanceTypeSingleTable() || $classMetadata->isInheritanceTypeJoined());
    }

    /**
     * Gets column alias for a given column.
     *
     * @phpstan-param array<string, string>  $customRenameColumns
     *
     * @phpstan-assert self::COLUMN_RENAMING_* $mode
     */
    private function getColumnAlias(string $columnName, int $mode, array $customRenameColumns): string
    {
        return match ($mode) {
            self::COLUMN_RENAMING_INCREMENT => $columnName . $this->sqlCounter++,
            self::COLUMN_RENAMING_CUSTOM => $customRenameColumns[$columnName] ?? $columnName,
            self::COLUMN_RENAMING_NONE => $columnName,
            default => throw new InvalidArgumentException(sprintf('%d is not a valid value for $mode', $mode)),
        };
    }

    /**
     * Retrieves a class columns and join columns aliases that are used in the SELECT clause.
     *
     * This depends on the renaming mode selected by the user.
     *
     * @param class-string $className
     * @phpstan-param self::COLUMN_RENAMING_* $mode
     * @phpstan-param array<string, string> $customRenameColumns
     *
     * @return string[]
     */
    private function getColumnAliasMap(
        string $className,
        int $mode,
        array $customRenameColumns,
    ): array {
        if ($customRenameColumns) { // for BC with 2.2-2.3 API
            $mode = self::COLUMN_RENAMING_CUSTOM;
        }

        $columnAlias = [];
        $class       = $this->em->getClassMetadata($className);

        foreach ($class->getColumnNames() as $columnName) {
            $columnAlias[$columnName] = $this->getColumnAlias($columnName, $mode, $customRenameColumns);
        }

        foreach ($class->associationMappings as $associationMapping) {
            if ($associationMapping->isToOneOwningSide()) {
                foreach ($associationMapping->joinColumns as $joinColumn) {
                    $columnName               = $joinColumn->name;
                    $columnAlias[$columnName] = $this->getColumnAlias($columnName, $mode, $customRenameColumns);
                }
            }
        }

        return $columnAlias;
    }

    /**
     * Generates the Select clause from this ResultSetMappingBuilder.
     *
     * Works only for all the entity results. The select parts for scalar
     * expressions have to be written manually.
     *
     * @param string[] $tableAliases
     * @phpstan-param array<string, string> $tableAliases
     */
    public function generateSelectClause(array $tableAliases = []): string
    {
        $sql          = '';
        $typeProvider = TypeProviderLocator::fromConnection($this->em->getConnection());

        foreach ($this->columnOwnerMap as $columnName => $dqlAlias) {
            $tableAlias = $tableAliases[$dqlAlias] ?? $dqlAlias;

            if ($sql !== '') {
                $sql .= ', ';
            }

            if (isset($this->fieldMappings[$columnName])) {
                $class             = $this->em->getClassMetadata($this->declaringClasses[$columnName]);
                $fieldName         = $this->fieldMappings[$columnName];
                $classFieldMapping = $class->fieldMappings[$fieldName];
                $columnSql         = $tableAlias . '.' . $classFieldMapping->columnName;

                $type      = $typeProvider->get($classFieldMapping->type);
                $columnSql = $type->convertToPHPValueSQL($columnSql, $this->em->getConnection()->getDatabasePlatform());

                $sql .= $columnSql;
            } elseif (isset($this->metaMappings[$columnName])) {
                $sql .= $tableAlias . '.' . $this->metaMappings[$columnName];
            } elseif (isset($this->discriminatorColumns[$dqlAlias])) {
                $sql .= $tableAlias . '.' . $this->discriminatorColumns[$dqlAlias];
            }

            $sql .= ' AS ' . $columnName;
        }

        return $sql;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->generateSelectClause([]);
    }
}
