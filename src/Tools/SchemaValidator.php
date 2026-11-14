<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use BackedEnum;
use Doctrine\DBAL\Types\AsciiStringType;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\GuidType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\JsonType;
use Doctrine\DBAL\Types\SimpleArrayType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\TypeProviderLocator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use ReflectionEnum;
use ReflectionNamedType;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_push;
use function array_search;
use function array_values;
use function class_exists;
use function class_parents;
use function count;
use function implode;
use function in_array;
use function interface_exists;
use function is_a;
use function sprintf;

/**
 * Performs strict validation of the mapping schema
 *
 * @link        www.doctrine-project.com
 */
class SchemaValidator
{
    /**
     * It maps built-in Doctrine types to PHP types
     */
    private const BUILTIN_TYPES_MAP = [
        AsciiStringType::class => ['string'],
        BigIntType::class => ['int', 'string'],
        BooleanType::class => ['bool'],
        DecimalType::class => ['string'],
        FloatType::class => ['float'],
        GuidType::class => ['string'],
        IntegerType::class => ['int'],
        JsonType::class => ['array'],
        /** @phpstan-ignore classConstant.deprecatedClass (to be removed) */
        SimpleArrayType::class => ['array'],
        SmallIntType::class => ['int'],
        StringType::class => ['string'],
        TextType::class => ['string'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly bool $validatePropertyTypes = true,
    ) {
    }

    /**
     * Checks the internal consistency of all mapping files.
     *
     * There are several checks that can't be done at runtime or are too expensive, which can be verified
     * with this command. For example:
     *
     * 1. Check if a relation with "mappedBy" is actually connected to that specified field.
     * 2. Check if "mappedBy" and "inversedBy" are consistent to each other.
     * 3. Check if "referencedColumnName" attributes are really pointing to primary key columns.
     *
     * @phpstan-return array<string, list<string>>
     */
    public function validateMapping(): array
    {
        $errors  = [];
        $cmf     = $this->em->getMetadataFactory();
        $classes = $cmf->getAllMetadata();

        foreach ($classes as $class) {
            $ce = $this->validateClass($class);
            if ($ce) {
                $errors[$class->name] = $ce;
            }
        }

        return $errors;
    }

    /**
     * Validates a single class of the current.
     *
     * @return string[]
     * @phpstan-return list<string>
     */
    public function validateClass(ClassMetadata $class): array
    {
        $ce           = [];
        $cmf          = $this->em->getMetadataFactory();
        $typeProvider = TypeProviderLocator::fromConnection($this->em->getConnection());

        foreach ($class->fieldMappings as $fieldName => $mapping) {
            if (! $typeProvider->has($mapping->type)) {
                $ce[] = "The field '" . $class->name . '#' . $fieldName . "' uses a non-existent type '" . $mapping->type . "'.";
            }
        }

        if ($this->validatePropertyTypes) {
            array_push($ce, ...$this->validatePropertiesTypes($class));
        }

        foreach ($class->associationMappings as $fieldName => $assoc) {
            if (! class_exists($assoc->targetEntity) || $cmf->isTransient($assoc->targetEntity)) {
                $ce[] = "The target entity '" . $assoc->targetEntity . "' specified on " . $class->name . '#' . $fieldName . ' is unknown or not an entity.';

                return $ce;
            }

            $targetMetadata = $cmf->getMetadataFor($assoc->targetEntity);

            if ($targetMetadata->isMappedSuperclass) {
                $ce[] = "The target entity '" . $assoc->targetEntity . "' specified on " . $class->name . '#' . $fieldName . ' is a mapped superclass. This is not possible since there is no table that a foreign key could refer to.';

                return $ce;
            }

            if ($assoc->isManyToOne() && count($class->identifier) === 1 && $class->identifier[0] === $fieldName) {
                $ce[] = "The association '" . $class->name . '#' . $fieldName . "' is a many-to-one association and is the sole identifier of the entity. " .
                        'This effectively makes the association one-to-one; use a one-to-one association instead or add another identifier field.';
            }

            if (isset($assoc->id) && $targetMetadata->containsForeignIdentifier) {
                $ce[] = "Cannot map association '" . $class->name . '#' . $fieldName . ' as identifier, because ' .
                        "the target entity '" . $targetMetadata->name . "' also maps an association as identifier.";
            }

            if (! $assoc->isOwningSide()) {
                if ($targetMetadata->hasField($assoc->mappedBy)) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the owning side ' .
                            'field ' . $assoc->targetEntity . '#' . $assoc->mappedBy . ' which is not defined as association, but as field.';
                }

                if (! $targetMetadata->hasAssociation($assoc->mappedBy)) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the owning side ' .
                            'field ' . $assoc->targetEntity . '#' . $assoc->mappedBy . ' which does not exist.';
                } elseif ($targetMetadata->associationMappings[$assoc->mappedBy]->inversedBy === null) {
                    $ce[] = 'The field ' . $class->name . '#' . $fieldName . ' is on the inverse side of a ' .
                            'bi-directional relationship, but the specified mappedBy association on the target-entity ' .
                            $assoc->targetEntity . '#' . $assoc->mappedBy . ' does not contain the required ' .
                            "'inversedBy: \"" . $fieldName . "\"' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc->mappedBy]->inversedBy !== $fieldName) {
                    $ce[] = 'The mappings ' . $class->name . '#' . $fieldName . ' and ' .
                            $assoc->targetEntity . '#' . $assoc->mappedBy . ' are ' .
                            'inconsistent with each other.';
                }
            }

            if ($assoc->isOwningSide() && $assoc->inversedBy !== null) {
                if ($targetMetadata->hasField($assoc->inversedBy)) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                            'field ' . $assoc->targetEntity . '#' . $assoc->inversedBy . ' which is not defined as association.';
                }

                if (! $targetMetadata->hasAssociation($assoc->inversedBy)) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                            'field ' . $assoc->targetEntity . '#' . $assoc->inversedBy . ' which does not exist.';
                } elseif ($targetMetadata->associationMappings[$assoc->inversedBy]->isOwningSide()) {
                    $ce[] = 'The field ' . $class->name . '#' . $fieldName . ' is on the owning side of a ' .
                            'bi-directional relationship, but the specified inversedBy association on the target-entity ' .
                            $assoc->targetEntity . '#' . $assoc->inversedBy . ' does not contain the required ' .
                            "'mappedBy: \"" . $fieldName . "\"' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc->inversedBy]->mappedBy !== $fieldName) {
                    $ce[] = 'The mappings ' . $class->name . '#' . $fieldName . ' and ' .
                            $assoc->targetEntity . '#' . $assoc->inversedBy . ' are ' .
                            'inconsistent with each other.';
                }

                // Verify inverse side/owning side match each other
                if (array_key_exists($assoc->inversedBy, $targetMetadata->associationMappings)) {
                    $targetAssoc = $targetMetadata->associationMappings[$assoc->inversedBy];
                    if ($assoc->isOneToOne() && ! $targetAssoc->isOneToOne()) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is one-to-one, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc->inversedBy . ' has to be one-to-one as well.';
                    } elseif ($assoc->isManyToOne() && ! $targetAssoc->isOneToMany()) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is many-to-one, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc->inversedBy . ' has to be one-to-many.';
                    } elseif ($assoc->isManyToMany() && ! $targetAssoc->isManyToMany()) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is many-to-many, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc->inversedBy . ' has to be many-to-many as well.';
                    }

                    if (! is_a($targetAssoc->targetEntity, $assoc->sourceEntity, true)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                                $assoc->targetEntity . '#' . $assoc->inversedBy . ' which targets a different entity (' .
                                $targetAssoc->targetEntity . ').';
                    }
                }
            }

            if ($assoc->isOwningSide()) {
                if ($assoc->isManyToManyOwningSide()) {
                    $identifierColumns = $class->getIdentifierColumnNames();
                    foreach ($assoc->joinTable->joinColumns as $joinColumn) {
                        if (! in_array($joinColumn->referencedColumnName, $identifierColumns, true)) {
                            $ce[] = "The referenced column name '" . $joinColumn->referencedColumnName . "' " .
                                "has to be a primary key column on the target entity class '" . $class->name . "'.";
                            break;
                        }
                    }

                    $identifierColumns = $targetMetadata->getIdentifierColumnNames();
                    foreach ($assoc->joinTable->inverseJoinColumns as $inverseJoinColumn) {
                        if (! in_array($inverseJoinColumn->referencedColumnName, $identifierColumns, true)) {
                            $ce[] = "The referenced column name '" . $inverseJoinColumn->referencedColumnName . "' " .
                                "has to be a primary key column on the target entity class '" . $targetMetadata->name . "'.";
                            break;
                        }
                    }

                    if (count($targetMetadata->getIdentifierColumnNames()) !== count($assoc->joinTable->inverseJoinColumns)) {
                        $ce[] = "The inverse join columns of the many-to-many table '" . $assoc->joinTable->name . "' " .
                                "have to contain to ALL identifier columns of the target entity '" . $targetMetadata->name . "', " .
                                "however '" . implode(', ', array_diff($targetMetadata->getIdentifierColumnNames(), array_values($assoc->relationToTargetKeyColumns))) .
                                "' are missing.";
                    }

                    if (count($class->getIdentifierColumnNames()) !== count($assoc->joinTable->joinColumns)) {
                        $ce[] = "The join columns of the many-to-many table '" . $assoc->joinTable->name . "' " .
                                "have to contain to ALL identifier columns of the source entity '" . $class->name . "', " .
                                "however '" . implode(', ', array_diff($class->getIdentifierColumnNames(), array_values($assoc->relationToSourceKeyColumns))) .
                                "' are missing.";
                    }
                } elseif ($assoc->isToOneOwningSide()) {
                    $identifierColumns = $targetMetadata->getIdentifierColumnNames();
                    foreach ($assoc->joinColumns as $joinColumn) {
                        if (! in_array($joinColumn->referencedColumnName, $identifierColumns, true)) {
                            $ce[] = "The referenced column name '" . $joinColumn->referencedColumnName . "' " .
                                    "has to be a primary key column on the target entity class '" . $targetMetadata->name . "'.";
                        }
                    }

                    if (count($identifierColumns) !== count($assoc->joinColumns)) {
                        $ids = [];

                        foreach ($assoc->joinColumns as $joinColumn) {
                            $ids[] = $joinColumn->name;
                        }

                        $ce[] = "The join columns of the association '" . $assoc->fieldName . "' " .
                                "have to match to ALL identifier columns of the target entity '" . $targetMetadata->name . "', " .
                                "however '" . implode(', ', array_diff($targetMetadata->getIdentifierColumnNames(), $ids)) .
                                "' are missing.";
                    }
                }
            }

            if ($assoc->isOrdered()) {
                foreach ($assoc->orderBy() as $orderField => $orientation) {
                    if (! $targetMetadata->hasField($orderField) && ! $targetMetadata->hasAssociation($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a foreign field ' .
                                $orderField . ' that is not a field on the target entity ' . $targetMetadata->name . '.';
                        continue;
                    }

                    if ($targetMetadata->isCollectionValuedAssociation($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a field ' .
                                $orderField . ' on ' . $targetMetadata->name . ' that is a collection-valued association.';
                        continue;
                    }

                    if ($targetMetadata->isAssociationInverseSide($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a field ' .
                                $orderField . ' on ' . $targetMetadata->name . ' that is the inverse side of an association.';
                        continue;
                    }
                }
            }
        }

        if (
            ! $class->isInheritanceTypeNone()
            && ! $class->isRootEntity()
            && ($class->reflClass !== null && ! $class->reflClass->isAbstract())
            && ! $class->isMappedSuperclass
            && array_search($class->name, $class->discriminatorMap, true) === false
        ) {
            $ce[] = "Entity class '" . $class->name . "' is part of inheritance hierarchy, but is " .
                "not mapped in the root entity '" . $class->rootEntityName . "' discriminator map. " .
                'All subclasses must be listed in the discriminator map.';
        }

        foreach ($class->subClasses as $subClass) {
            if (! in_array($class->name, class_parents($subClass), true)) {
                $ce[] = "According to the discriminator map class '" . $subClass . "' has to be a child " .
                        "of '" . $class->name . "' but these entities are not related through inheritance.";
            }
        }

        return $ce;
    }

    /**
     * Checks if the Database Schema is in sync with the current metadata state.
     */
    public function schemaInSyncWithMetadata(): bool
    {
        return count($this->getUpdateSchemaList()) === 0;
    }

    /**
     * Returns the list of missing Database Schema updates.
     *
     * @return array<string>
     */
    public function getUpdateSchemaList(): array
    {
        $schemaTool = new SchemaTool($this->em);

        $allMetadata = $this->em->getMetadataFactory()->getAllMetadata();

        return $schemaTool->getUpdateSchemaSql($allMetadata);
    }

    /** @return list<string> containing the found issues */
    private function validatePropertiesTypes(ClassMetadata $class): array
    {
        $typeProvider = TypeProviderLocator::fromConnection($this->em->getConnection());

        return array_values(
            array_filter(
                array_map(
                    function (FieldMapping $fieldMapping) use ($class, $typeProvider): string|null {
                        $fieldName = $fieldMapping->fieldName;

                        // Dynamic properties are stored in an array, there is no property type to compare with
                        if ($fieldMapping->dynamicProperty !== null) {
                            return null;
                        }

                        $propertyType = $class->propertyAccessors[$fieldName]->getUnderlyingReflector()->getType();

                        // If the field type is not a built-in type, we cannot check it
                        if (! $typeProvider->has($fieldMapping->type)) {
                            return null;
                        }

                        // If the property type is not a named type, we cannot check it
                        if (! ($propertyType instanceof ReflectionNamedType) || $propertyType->getName() === 'mixed') {
                            return null;
                        }

                        $metadataFieldType = $this->findBuiltInType($typeProvider->get($fieldMapping->type));

                        //If the metadata field type is not a mapped built-in type, we cannot check it
                        if ($metadataFieldType === null) {
                            return null;
                        }

                        $propertyType = $propertyType->getName();

                        // If the property type is the same as the metadata field type, we are ok
                        if (in_array($propertyType, $metadataFieldType, true)) {
                            return null;
                        }

                        if (is_a($propertyType, BackedEnum::class, true)) {
                            $backingType = (string) (new ReflectionEnum($propertyType))->getBackingType();

                            if (! in_array($backingType, $metadataFieldType, true)) {
                                return sprintf(
                                    "The field '%s#%s' has the property type '%s' with a backing type of '%s' that differs from the metadata field type '%s'.",
                                    $class->name,
                                    $fieldName,
                                    $propertyType,
                                    $backingType,
                                    implode('|', $metadataFieldType),
                                );
                            }

                            if (! isset($fieldMapping->enumType) || $propertyType === $fieldMapping->enumType) {
                                return null;
                            }

                            return sprintf(
                                "The field '%s#%s' has the property type '%s' that differs from the metadata enumType '%s'.",
                                $class->name,
                                $fieldName,
                                $propertyType,
                                $fieldMapping->enumType,
                            );
                        }

                        if (
                            isset($fieldMapping->enumType)
                            && $propertyType !== $fieldMapping->enumType
                            && interface_exists($propertyType)
                            && is_a($fieldMapping->enumType, $propertyType, true)
                        ) {
                            $backingType = (string) (new ReflectionEnum($fieldMapping->enumType))->getBackingType();

                            if (in_array($backingType, $metadataFieldType, true)) {
                                return null;
                            }

                            return sprintf(
                                "The field '%s#%s' has the metadata enumType '%s' with a backing type of '%s' that differs from the metadata field type '%s'.",
                                $class->name,
                                $fieldName,
                                $fieldMapping->enumType,
                                $backingType,
                                implode('|', $metadataFieldType),
                            );
                        }

                        if (
                            $fieldMapping->type === 'json'
                            && in_array($propertyType, ['string', 'int', 'float', 'bool', 'true', 'false', 'null'], true)
                        ) {
                            return null;
                        }

                        return sprintf(
                            "The field '%s#%s' has the property type '%s' that differs from the metadata field type '%s' returned by the '%s' DBAL type.",
                            $class->name,
                            $fieldName,
                            $propertyType,
                            implode('|', $metadataFieldType),
                            $fieldMapping->type,
                        );
                    },
                    $class->fieldMappings,
                ),
            ),
        );
    }

    /**
     * The exact DBAL type must be used (no subclasses), since consumers of doctrine/orm may have their own
     * customization around field types.
     *
     * @return list<string>|null
     */
    private function findBuiltInType(Type $type): array|null
    {
        $typeName = $type::class;

        return self::BUILTIN_TYPES_MAP[$typeName] ?? null;
    }
}
