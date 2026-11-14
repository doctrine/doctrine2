<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use BackedEnum;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\TypeProvider;
use Doctrine\DBAL\Types\TypeRegistry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\TypeProviderLocator;
use Doctrine\ORM\Mapping\ClassMetadata;
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
    private const array DATE_TIME_TYPES = [
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
        if (isset($class->fieldNames[$columnName])) {
            $fieldName = $class->fieldNames[$columnName];

            if (isset($class->fieldMappings[$fieldName])) {
                return $class->fieldMappings[$fieldName]->type;
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

                    return self::getTypeOfColumn($targetColumnName, $targetClass, $em);
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

                    return self::getTypeOfColumn($targetColumnName, $targetClass, $em);
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
            $typeProvider = TypeProviderLocator::fromConnection($em->getConnection());

            return array_map(static fn ($t) => self::getArrayBindingType($t, $typeProvider), $types);
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

    private static function getArrayBindingType(ParameterType|int|string $type, TypeProvider|TypeRegistry $typeProvider): ArrayParameterType
    {
        if (! $type instanceof ParameterType) {
            $type = $typeProvider->get((string) $type)->getBindingType();
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
                foreach (self::convertToParameterValue($itemValue, $em) as $convertedValue) {
                    $newValue[] = $convertedValue;
                }
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

        $valueClass = $value::class;

        if ($em->getMetadataFactory()->isTransient($valueClass)) {
            return [$value];
        }

        $class = $em->getClassMetadata($valueClass);

        if ($class->isIdentifierComposite) {
            $newValue = [];

            foreach ($class->getIdentifierValues($value) as $innerValue) {
                foreach (self::convertToParameterValue($innerValue, $em) as $convertedValue) {
                    $newValue[] = $convertedValue;
                }
            }

            return $newValue;
        }

        return [$em->getUnitOfWork()->getSingleIdentifierValue($value)];
    }
}
