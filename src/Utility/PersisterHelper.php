<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use BackedEnum;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Proxy\DefaultProxyClassNameResolver;
use Doctrine\ORM\Query\QueryException;
use RuntimeException;

use function array_map;
use function array_merge;
use function assert;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * The PersisterHelper contains logic to infer binding types which is used in
 * several persisters.
 *
 * @link   www.doctrine-project.org
 */
class PersisterHelper
{
    private const DATE_TIME_TYPES = [
        Types::DATE_MUTABLE,
        Types::DATE_IMMUTABLE,
        Types::DATETIME_MUTABLE,
        Types::DATETIME_IMMUTABLE,
        Types::DATETIMETZ_MUTABLE,
        Types::DATETIMETZ_IMMUTABLE,
        Types::TIME_MUTABLE,
        Types::TIME_IMMUTABLE,
    ];

    /**
     * @return list<string>
     *
     * @throws QueryException
     */
    public static function getTypeOfField(string $fieldName, ClassMetadata $class, EntityManagerInterface $em): array
    {
        if (isset($class->fieldMappings[$fieldName])) {
            return [$class->fieldMappings[$fieldName]->type];
        }

        if (! isset($class->associationMappings[$fieldName])) {
            return [];
        }

        $assoc = $class->associationMappings[$fieldName];

        if (! $assoc->isOwningSide()) {
            return self::getTypeOfField($assoc->mappedBy, $em->getClassMetadata($assoc->targetEntity), $em);
        }

        if ($assoc->isManyToManyOwningSide()) {
            $joinData = $assoc->joinTable;
        } else {
            $joinData = $assoc;
        }

        $types       = [];
        $targetClass = $em->getClassMetadata($assoc->targetEntity);

        foreach ($joinData->joinColumns as $joinColumn) {
            $types[] = self::getTypeOfColumn($joinColumn->referencedColumnName, $targetClass, $em);
        }

        return $types;
    }

    /** @throws RuntimeException */
    public static function getTypeOfColumn(string $columnName, ClassMetadata $class, EntityManagerInterface $em): string
    {
        return self::getFieldMappingOfColumn($columnName, $class, $em)->type;
    }

    /**
     * Finds the mapping of the field that defines a column of the table of the given class.
     *
     * The join columns of an association are not mapped as a field. They are resolved to the
     * mapping of the field that holds the column they reference.
     *
     * @internal
     *
     * @param ClassMetadata<object> $class
     *
     * @throws RuntimeException
     */
    public static function getFieldMappingOfColumn(string $columnName, ClassMetadata $class, EntityManagerInterface $em): FieldMapping
    {
        if (isset($class->fieldNames[$columnName])) {
            $fieldName = $class->fieldNames[$columnName];

            if (isset($class->fieldMappings[$fieldName])) {
                return $class->fieldMappings[$fieldName];
            }
        }

        // iterate over to-one association mappings
        foreach ($class->associationMappings as $assoc) {
            if (! $assoc->isToOneOwningSide()) {
                continue;
            }

            foreach ($assoc->joinColumns as $joinColumn) {
                if ($joinColumn->name === $columnName) {
                    $targetColumnName = $joinColumn->referencedColumnName;
                    $targetClass      = $em->getClassMetadata($assoc->targetEntity);

                    return self::getFieldMappingOfColumn($targetColumnName, $targetClass, $em);
                }
            }
        }

        // iterate over to-many association mappings
        foreach ($class->associationMappings as $assoc) {
            if (! $assoc->isManyToManyOwningSide()) {
                continue;
            }

            foreach ($assoc->joinTable->joinColumns as $joinColumn) {
                if ($joinColumn->name === $columnName) {
                    $targetColumnName = $joinColumn->referencedColumnName;
                    $targetClass      = $em->getClassMetadata($assoc->targetEntity);

                    return self::getFieldMappingOfColumn($targetColumnName, $targetClass, $em);
                }
            }
        }

        throw new RuntimeException(sprintf(
            'Could not resolve type of column "%s" of class "%s"',
            $columnName,
            $class->getName(),
        ));
    }

    /**
     * Infers field types to be used by parameter type casting.
     *
     * @return list<ParameterType|int|string>
     * @phpstan-return list<ParameterType::*|ArrayParameterType::*|string>
     *
     * @throws QueryException
     */
    public static function inferParameterTypes(
        string $field,
        mixed $value,
        ClassMetadata $class,
        EntityManagerInterface $em,
    ): array {
        /** @var list<ParameterType::*|string> $types */
        $types = [];

        switch (true) {
            case isset($class->fieldMappings[$field]):
                $types = array_merge($types, [$class->fieldMappings[$field]->type]);
                break;

            case isset($class->associationMappings[$field]):
                $assoc = $em->getMetadataFactory()->getOwningSide($class->associationMappings[$field]);
                $class = $em->getClassMetadata($assoc->targetEntity);

                if ($assoc->isManyToManyOwningSide()) {
                    $columns = $assoc->relationToTargetKeyColumns;
                } else {
                    assert($assoc->isToOneOwningSide());
                    $columns = $assoc->sourceToTargetKeyColumns;
                }

                foreach ($columns as $column) {
                    $types[] = self::getTypeOfColumn($column, $class, $em);
                }

                break;

            default:
                $types[] = ParameterType::STRING;
                break;
        }

        $types = self::normalizeDateTimeTypesForValue($types, $value);

        if (is_array($value)) {
            return array_map(self::getArrayBindingType(...), $types);
        }

        return $types;
    }

    /**
     * @param list<ParameterType::*|string> $types
     *
     * @return list<ParameterType::*|string>
     */
    private static function normalizeDateTimeTypesForValue(array $types, mixed $value): array
    {
        if ($value instanceof DateTimeInterface) {
            return $types;
        }

        if (! is_string($value)) {
            return $types;
        }

        foreach ($types as $index => $type) {
            if (is_string($type) && in_array($type, self::DATE_TIME_TYPES, true)) {
                $types[$index] = ParameterType::STRING;
            }
        }

        return $types;
    }

    /** @phpstan-return ArrayParameterType::* */
    private static function getArrayBindingType(ParameterType|int|string $type): ArrayParameterType|int
    {
        if (! $type instanceof ParameterType) {
            $type = Type::getType((string) $type)->getBindingType();
        }

        return match ($type) {
            ParameterType::STRING => ArrayParameterType::STRING,
            ParameterType::INTEGER => ArrayParameterType::INTEGER,
            ParameterType::ASCII => ArrayParameterType::ASCII,
            ParameterType::BINARY => ArrayParameterType::BINARY,
        };
    }

    /**
     * Converts a value to the type and value required to bind it as a parameter.
     *
     * @return list<mixed>
     */
    public static function convertToParameterValue(mixed $value, EntityManagerInterface $em): array
    {
        if (is_array($value)) {
            $newValue = [];

            foreach ($value as $itemValue) {
                $newValue = array_merge($newValue, self::convertToParameterValue($itemValue, $em));
            }

            return [$newValue];
        }

        return self::convertIndividualValue($value, $em);
    }

    /** @phpstan-return list<mixed> */
    private static function convertIndividualValue(mixed $value, EntityManagerInterface $em): array
    {
        if (! is_object($value)) {
            return [$value];
        }

        if ($value instanceof BackedEnum) {
            return [$value->value];
        }

        $valueClass = DefaultProxyClassNameResolver::getClass($value);

        if ($em->getMetadataFactory()->isTransient($valueClass)) {
            return [$value];
        }

        $class = $em->getClassMetadata($valueClass);

        if ($class->isIdentifierComposite) {
            $newValue = [];

            foreach ($class->getIdentifierValues($value) as $innerValue) {
                $newValue = array_merge($newValue, self::convertToParameterValue($innerValue, $em));
            }

            return $newValue;
        }

        return [$em->getUnitOfWork()->getSingleIdentifierValue($value)];
    }
}
