<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use BackedEnum;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use LibXMLError;
use Override;
use ReflectionException;
use ValueError;

use function array_keys;
use function array_map;
use function array_values;
use function get_debug_type;
use function get_parent_class;
use function implode;
use function sprintf;

use const PHP_EOL;

/**
 * A MappingException indicates that something is wrong with the mapping setup.
 */
class MappingException extends PersistenceMappingException implements ORMException
{
    /** @param class-string $entityName */
    public static function identifierRequired(string $entityName): self
    {
        $parent = get_parent_class($entityName);
        if ($parent !== false) {
            return new self(sprintf(
                'No identifier/primary key specified for Entity "%s" sub class of "%s". Every Entity must have an identifier/primary key.',
                $entityName,
                $parent,
            ));
        }

        return new self(sprintf(
            'No identifier/primary key specified for Entity "%s". Every Entity must have an identifier/primary key.',
            $entityName,
        ));
    }

    public static function invalidAssociationType(string $entityName, string $fieldName, int $type): self
    {
        return new self(sprintf(
            'The association "%s#%s" must be of type "ClassMetadata::ONE_TO_MANY", "ClassMetadata::MANY_TO_MANY" or "ClassMetadata::MANY_TO_ONE", "%d" given.',
            $entityName,
            $fieldName,
            $type,
        ));
    }

    public static function invalidInheritanceType(string $entityName, int $type): self
    {
        return new self(sprintf("The inheritance type '%s' specified for '%s' does not exist.", $type, $entityName));
    }

    public static function generatorNotAllowedWithCompositeId(): self
    {
        return new self("Id generators can't be used with a composite id.");
    }

    public static function missingFieldName(string $entity): self
    {
        return new self(sprintf(
            "The field or association mapping misses the 'fieldName' attribute in entity '%s'.",
            $entity,
        ));
    }

    public static function missingTargetEntity(string $fieldName): self
    {
        return new self(sprintf("The association mapping '%s' misses the 'targetEntity' attribute.", $fieldName));
    }

    public static function missingSourceEntity(string $fieldName): self
    {
        return new self(sprintf("The association mapping '%s' misses the 'sourceEntity' attribute.", $fieldName));
    }

    public static function missingEmbeddedClass(string $fieldName): self
    {
        return new self(sprintf("The embed mapping '%s' misses the 'class' attribute.", $fieldName));
    }

    #[Override]
    public static function mappingFileNotFound(string $entityName, string $fileName): self
    {
        return new self(sprintf("No mapping file found named '%s' for class '%s'.", $fileName, $entityName));
    }

    /**
     * Exception for invalid property name override.
     *
     * @param string $className The entity's name.
     */
    public static function invalidOverrideFieldName(string $className, string $fieldName): self
    {
        return new self(sprintf("Invalid field override named '%s' for class '%s'.", $fieldName, $className));
    }

    /**
     * Exception for invalid property type override.
     *
     * @param string $className The entity's name.
     */
    public static function invalidOverrideFieldType(string $className, string $fieldName): self
    {
        return new self(sprintf(
            "The column type of attribute '%s' on class '%s' could not be changed.",
            $fieldName,
            $className,
        ));
    }

    public static function mappingNotFound(string $className, string $fieldName): self
    {
        return new self(sprintf("No mapping found for field '%s' on class '%s'.", $fieldName, $className));
    }

    public static function queryNotFound(string $className, string $queryName): self
    {
        return new self(sprintf("No query found named '%s' on class '%s'.", $queryName, $className));
    }

    public static function resultMappingNotFound(string $className, string $resultName): self
    {
        return new self(sprintf("No result set mapping found named '%s' on class '%s'.", $resultName, $className));
    }

    public static function emptyQueryMapping(string $entity, string $queryName): self
    {
        return new self(sprintf('Query named "%s" in "%s" could not be empty.', $queryName, $entity));
    }

    public static function nameIsMandatoryForQueryMapping(string $className): self
    {
        return new self(sprintf("Query name on entity class '%s' is not defined.", $className));
    }

    public static function missingQueryMapping(string $entity, string $queryName): self
    {
        return new self(sprintf(
            'Query named "%s" in "%s requires a result class or result set mapping.',
            $queryName,
            $entity,
        ));
    }

    public static function missingResultSetMappingEntity(string $entity, string $resultName): self
    {
        return new self(sprintf(
            'Result set mapping named "%s" in "%s requires a entity class name.',
            $resultName,
            $entity,
        ));
    }

    public static function missingResultSetMappingFieldName(string $entity, string $resultName): self
    {
        return new self(sprintf(
            'Result set mapping named "%s" in "%s requires a field name.',
            $resultName,
            $entity,
        ));
    }

    public static function oneToManyRequiresMappedBy(string $entityName, string $fieldName): MappingException
    {
        return new self(sprintf(
            "OneToMany mapping on entity '%s' field '%s' requires the 'mappedBy' attribute.",
            $entityName,
            $fieldName,
        ));
    }

    public static function joinTableRequired(string $fieldName): self
    {
        return new self(sprintf("The mapping of field '%s' requires the 'joinTable' attribute.", $fieldName));
    }

    /**
     * Called if a required option was not found but is required
     *
     * @param string $field          Which field cannot be processed?
     * @param string $expectedOption Which option is required
     * @param string $hint           Can optionally be used to supply a tip for common mistakes,
     *                               e.g. "Did you think of the plural s?"
     */
    public static function missingRequiredOption(string $field, string $expectedOption, string $hint = ''): self
    {
        $message = "The mapping of field '" . $field . "' is invalid: The option '" . $expectedOption . "' is required.";

        if (! empty($hint)) {
            $message .= ' (Hint: ' . $hint . ')';
        }

        return new self($message);
    }

    /**
     * Generic exception for invalid mappings.
     */
    public static function invalidMapping(string $fieldName): self
    {
        return new self(sprintf("The mapping of field '%s' is invalid.", $fieldName));
    }

    /**
     * Exception for reflection exceptions - adds the entity name,
     * because there might be long classnames that will be shortened
     * within the stacktrace
     *
     * @param string $entity The entity's name
     */
    public static function reflectionFailure(string $entity, ReflectionException $previousException): self
    {
        return new self(sprintf('An error occurred in %s', $entity), 0, $previousException);
    }

    public static function joinColumnMustPointToMappedField(string $className, string $joinColumn): self
    {
        return new self(sprintf(
            'The column %s must be mapped to a field in class %s since it is referenced by a join column of another class.',
            $joinColumn,
            $className,
        ));
    }

    public static function joinColumnNotAllowedOnOneToOneInverseSide(string $className, string $fieldName): self
    {
        return new self(sprintf(
            '%s#%s is a OneToOne inverse side, which does not allow join columns.',
            $className,
            $fieldName,
        ));
    }

    /** @param class-string $className */
    public static function classIsNotAValidEntityOrMappedSuperClass(string $className): self
    {
        $parent = get_parent_class($className);
        if ($parent !== false) {
            return new self(sprintf(
                'Class "%s" sub class of "%s" is not a valid entity or mapped super class.',
                $className,
                $parent,
            ));
        }

        return new self(sprintf(
            'Class "%s" is not a valid entity or mapped super class.',
            $className,
        ));
    }

    /**
     * @param string $entity    The entity's name.
     * @param string $fieldName The name of the field that was already declared.
     */
    public static function duplicateFieldMapping(string $entity, string $fieldName): self
    {
        return new self(sprintf(
            'Property "%s" in "%s" was already declared, but it must be declared only once',
            $fieldName,
            $entity,
        ));
    }

    public static function duplicateAssociationMapping(string $entity, string $fieldName): self
    {
        return new self(sprintf(
            'Property "%s" in "%s" was already declared, but it must be declared only once',
            $fieldName,
            $entity,
        ));
    }

    public static function duplicateQueryMapping(string $entity, string $queryName): self
    {
        return new self(sprintf(
            'Query named "%s" in "%s" was already declared, but it must be declared only once',
            $queryName,
            $entity,
        ));
    }

    public static function duplicateResultSetMapping(string $entity, string $resultName): self
    {
        return new self(sprintf(
            'Result set mapping named "%s" in "%s" was already declared, but it must be declared only once',
            $resultName,
            $entity,
        ));
    }

    public static function singleIdNotAllowedOnCompositePrimaryKey(string $entity): self
    {
        return new self('Single id is not allowed on composite primary key in entity ' . $entity);
    }

    public static function noIdDefined(string $entity): self
    {
        return new self('No ID defined for entity ' . $entity);
    }

    public static function unsupportedOptimisticLockingType(string $entity, string $fieldName, string $unsupportedType): self
    {
        return new self(sprintf(
            'Locking type "%s" (specified in "%s", field "%s") is not supported by Doctrine.',
            $unsupportedType,
            $entity,
            $fieldName,
        ));
    }

    #[Override]
    public static function fileMappingDriversRequireConfiguredDirectoryPath(string|null $path = null): self
    {
        if (! empty($path)) {
            $path = '[' . $path . ']';
        }

        return new self(
            'File mapping drivers must have a valid directory path, ' .
            'however the given path ' . $path . ' seems to be incorrect!',
        );
    }

    /**
     * Returns an exception that indicates that discriminator entries used in a discriminator map
     * does not exist in the backed enum provided by enumType option.
     *
     * @param array<int,int|string> $entries     The discriminator entries that could not be found.
     * @param string                $owningClass The class that declares the discriminator map.
     * @param string                $enumType    The enum that entries were checked against.
     */
    public static function invalidEntriesInDiscriminatorMap(array $entries, string $owningClass, string $enumType): self
    {
        return new self(sprintf(
            "The entries %s in the discriminator map of class '%s' do not correspond to enum cases of '%s'.",
            implode(', ', array_map(static fn ($entry): string => sprintf("'%s'", $entry), $entries)),
            $owningClass,
            $enumType,
        ));
    }

    /**
     * Returns an exception that indicates that a class used in a discriminator map does not exist.
     * An example would be an outdated (maybe renamed) classname.
     *
     * @param string $className   The class that could not be found
     * @param string $owningClass The class that declares the discriminator map.
     */
    public static function invalidClassInDiscriminatorMap(string $className, string $owningClass): self
    {
        return new self(sprintf(
            "Entity class '%s' used in the discriminator map of class '%s' " .
            'does not exist.',
            $className,
            $owningClass,
        ));
    }

    /**
     * @param string[]             $entries
     * @param array<string,string> $map
     */
    public static function duplicateDiscriminatorEntry(string $className, array $entries, array $map): self
    {
        return new self(
            'The entries ' . implode(', ', $entries) . " in discriminator map of class '" . $className . "' is duplicated. " .
            'If the discriminator map is automatically generated you have to convert it to an explicit discriminator map now. ' .
            'The entries of the current map are: @DiscriminatorMap({' . implode(', ', array_map(
                static fn ($a, $b) => sprintf("'%s': '%s'", $a, $b),
                array_keys($map),
                array_values($map),
            )) . '})',
        );
    }

    /**
     * @param class-string $rootEntityClass
     * @param class-string $childEntityClass
     */
    public static function missingInheritanceTypeDeclaration(string $rootEntityClass, string $childEntityClass): self
    {
        return new self(sprintf(
            "Entity class '%s' is a subclass of the root entity class '%s', but no inheritance mapping type was declared.",
            $childEntityClass,
            $rootEntityClass,
        ));
    }

    public static function missingDiscriminatorMap(string $className): self
    {
        return new self(sprintf(
            "Entity class '%s' is using inheritance but no discriminator map was defined.",
            $className,
        ));
    }

    public static function missingDiscriminatorColumn(string $className): self
    {
        return new self(sprintf(
            "Entity class '%s' is using inheritance but no discriminator column was defined.",
            $className,
        ));
    }

    public static function invalidDiscriminatorColumnType(string $className, string $type): self
    {
        return new self(sprintf(
            "Discriminator column type on entity class '%s' is not allowed to be '%s'. 'string' or 'integer' type variables are suggested!",
            $className,
            $type,
        ));
    }

    public static function nameIsMandatoryForDiscriminatorColumns(string $className): self
    {
        return new self(sprintf("Discriminator column name on entity class '%s' is not defined.", $className));
    }

    public static function cannotSetNullableOnToOneIdentifierJoinColumns(
        string $className,
        string $fieldName,
    ): self {
        return new self(sprintf(
            'Cannot specify the "nullable" attribute for join columns in to-one associations (%s::$%s) that are part of the identifier',
            $className,
            $fieldName,
        ));
    }

    public static function cannotSetNullableOnManyToManyJoinColumns(
        string $className,
        string $fieldName,
    ): self {
        return new self(sprintf(
            'Cannot specify the "nullable" attribute for join columns in many-to-many associations (%s::$%s)',
            $className,
            $fieldName,
        ));
    }

    public static function cannotVersionIdField(string $className, string $fieldName): self
    {
        return new self(sprintf(
            "Setting Id field '%s' as versionable in entity class '%s' is not supported.",
            $fieldName,
            $className,
        ));
    }

    public static function duplicateColumnName(string $className, string $columnName): self
    {
        return new self("Duplicate definition of column '" . $columnName . "' on entity '" . $className . "' in a field or discriminator column mapping.");
    }

    public static function illegalToManyAssociationOnMappedSuperclass(string $className, string $field): self
    {
        return new self("It is illegal to put an inverse side one-to-many or many-to-many association on mapped superclass '" . $className . '#' . $field . "'.");
    }

    public static function cannotMapCompositePrimaryKeyEntitiesAsForeignId(string $className, string $targetEntity, string $targetField): self
    {
        return new self("It is not possible to map entity '" . $className . "' with a composite primary key " .
            "as part of the primary key of another entity '" . $targetEntity . '#' . $targetField . "'.");
    }

    public static function noSingleAssociationJoinColumnFound(string $className, string $field): self
    {
        return new self(sprintf("'%s#%s' is not an association with a single join column.", $className, $field));
    }

    public static function noFieldNameFoundForColumn(string $className, string $column): self
    {
        return new self(sprintf(
            "Cannot find a field on '%s' that is mapped to column '%s'. Either the " .
            'field does not exist or an association exists but it has multiple join columns.',
            $className,
            $column,
        ));
    }

    public static function illegalOrphanRemovalOnIdentifierAssociation(string $className, string $field): self
    {
        return new self(sprintf(
            "The orphan removal option is not allowed on an association that is part of the identifier in '%s#%s'.",
            $className,
            $field,
        ));
    }

    public static function illegalOrphanRemoval(string $className, string $field): self
    {
        return new self('Orphan removal is only allowed on one-to-one and one-to-many ' .
            'associations, but ' . $className . '#' . $field . ' is not.');
    }

    public static function illegalInverseIdentifierAssociation(string $className, string $field): self
    {
        return new self(sprintf(
            "An inverse association is not allowed to be identifier in '%s#%s'.",
            $className,
            $field,
        ));
    }

    public static function illegalToManyIdentifierAssociation(string $className, string $field): self
    {
        return new self(sprintf(
            "Many-to-many or one-to-many associations are not allowed to be identifier in '%s#%s'.",
            $className,
            $field,
        ));
    }

    public static function noInheritanceOnMappedSuperClass(string $className): self
    {
        return new self("It is not supported to define inheritance information on a mapped superclass '" . $className . "'.");
    }

    public static function mappedClassNotPartOfDiscriminatorMap(string $className, string $rootClassName): self
    {
        return new self(
            "Entity '" . $className . "' has to be part of the discriminator map of '" . $rootClassName . "' " .
            "to be properly mapped in the inheritance hierarchy. Alternatively you can make '" . $className . "' an abstract class " .
            'to avoid this exception from occurring.',
        );
    }

    public static function lifecycleCallbackMethodNotFound(string $className, string $methodName): self
    {
        return new self("Entity '" . $className . "' has no method '" . $methodName . "' to be registered as lifecycle callback.");
    }

    /** @param class-string $className */
    public static function illegalLifecycleCallbackOnEmbeddedClass(string $event, string $className): self
    {
        return new self(sprintf(
            <<<'EXCEPTION'
            Context: Attempt to register lifecycle callback "%s" on embedded class "%s".
            Problem: Registering lifecycle callbacks on embedded classes is not allowed.
            EXCEPTION,
            $event,
            $className,
        ));
    }

    public static function entityListenerClassNotFound(string $listenerName, string $className): self
    {
        return new self(sprintf('Entity Listener "%s" declared on "%s" not found.', $listenerName, $className));
    }

    public static function entityListenerMethodNotFound(string $listenerName, string $methodName, string $className): self
    {
        return new self(sprintf('Entity Listener "%s" declared on "%s" has no method "%s".', $listenerName, $className, $methodName));
    }

    public static function duplicateEntityListener(string $listenerName, string $methodName, string $className): self
    {
        return new self(sprintf('Entity Listener "%s#%s()" in "%s" was already declared, but it must be declared only once.', $listenerName, $methodName, $className));
    }

    /** @param class-string $className */
    public static function invalidFetchMode(string $className, string $fetchMode): self
    {
        return new self("Entity '" . $className . "' has a mapping with invalid fetch mode '" . $fetchMode . "'");
    }

    public static function invalidGeneratedMode(int|string $generatedMode): self
    {
        return new self("Invalid generated mode '" . $generatedMode . "'");
    }

    public static function compositeKeyAssignedIdGeneratorRequired(string $className): self
    {
        return new self("Entity '" . $className . "' has a composite identifier but uses an ID generator other than manually assigning (Identity, Sequence). This is not supported.");
    }

    public static function invalidTargetEntityClass(string $targetEntity, string $sourceEntity, string $associationName): self
    {
        return new self('The target-entity ' . $targetEntity . " cannot be found in '" . $sourceEntity . '#' . $associationName . "'.");
    }

    /** @param string[] $cascades */
    public static function invalidCascadeOption(array $cascades, string $className, string $propertyName): self
    {
        $cascades = implode(', ', array_map(static fn (string $e): string => "'" . $e . "'", $cascades));

        return new self(sprintf(
            "You have specified invalid cascade options for %s::$%s: %s; available options: 'remove', 'persist', 'refresh', and 'detach'",
            $className,
            $propertyName,
            $cascades,
        ));
    }

    public static function missingSequenceName(string $className): self
    {
        return new self(
            sprintf('Missing "sequenceName" attribute for sequence id generator definition on class "%s".', $className),
        );
    }

    public static function infiniteEmbeddableNesting(string $className, string $propertyName): self
    {
        return new self(
            sprintf(
                'Infinite nesting detected for embedded property %s::%s. ' .
                'You cannot embed an embeddable from the same type inside an embeddable.',
                $className,
                $propertyName,
            ),
        );
    }

    public static function illegalOverrideOfInheritedProperty(string $className, string $propertyName, string $inheritFromClass): self
    {
        return new self(
            sprintf(
                'Overrides are only allowed for fields or associations declared in mapped superclasses or traits. This is not the case for %s::%s, which was inherited from %s.',
                $className,
                $propertyName,
                $inheritFromClass,
            ),
        );
    }

    public static function invalidIndexConfiguration(string $className, string $indexName): self
    {
        return new self(
            sprintf(
                'Index %s for entity %s should contain columns or fields values, but not both.',
                $indexName,
                $className,
            ),
        );
    }

    public static function invalidUniqueConstraintConfiguration(string $className, string $indexName): self
    {
        return new self(
            sprintf(
                'Unique constraint %s for entity %s should contain columns or fields values, but not both.',
                $indexName,
                $className,
            ),
        );
    }

    public static function invalidOverrideType(string $expectdType, mixed $givenValue): self
    {
        return new self(sprintf(
            'Expected %s, but %s was given.',
            $expectdType,
            get_debug_type($givenValue),
        ));
    }

    public static function backedEnumTypeRequired(string $className, string $fieldName, string $enumType): self
    {
        return new self(sprintf(
            'Attempting to map a non-backed enum type %s in entity %s::$%s. Please use backed enums only',
            $enumType,
            $className,
            $fieldName,
        ));
    }

    public static function nonEnumTypeMapped(string $className, string $fieldName, string $enumType): self
    {
        return new self(sprintf(
            'Attempting to map non-enum type %s as enum in entity %s::$%s',
            $enumType,
            $className,
            $fieldName,
        ));
    }

    /**
     * @param class-string             $className
     * @param class-string<BackedEnum> $enumType
     */
    public static function invalidEnumValue(
        string $className,
        string $fieldName,
        string $value,
        string $enumType,
        ValueError $previous,
    ): self {
        return new self(sprintf(
            <<<'EXCEPTION'
Context: Trying to hydrate enum property "%s::$%s"
Problem: Case "%s" is not listed in enum "%s"
Solution: Either add the case to the enum type or migrate the database column to use another case of the enum
EXCEPTION
            ,
            $className,
            $fieldName,
            $value,
            $enumType,
        ), 0, $previous);
    }

    /** @param LibXMLError[] $errors */
    public static function fromLibXmlErrors(array $errors): self
    {
        $formatter = static fn (LibXMLError $error): string => sprintf(
            'libxml error: %s in %s at line %d',
            $error->message,
            $error->file,
            $error->line,
        );

        return new self(implode(PHP_EOL, array_map($formatter, $errors)));
    }

    public static function invalidAttributeOnEmbeddable(string $entityName, string $attributeName): self
    {
        return new self(sprintf(
            'Attribute "%s" on embeddable "%s" is not allowed.',
            $attributeName,
            $entityName,
        ));
    }

    public static function mappingVirtualPropertyNotAllowed(string $entityName, string $propertyName): self
    {
        return new self(sprintf(
            'Mapping virtual property "%s" on entity "%s" is not allowed.',
            $propertyName,
            $entityName,
        ));
    }

    public static function invalidOrderByDirection(
        string $direction,
        string $orderByField,
        string $fieldName,
        string $className,
    ): self {
        return new self(sprintf(
            'Invalid order direction "%s" for field "%s" in association "%s" of class "%s". Valid directions are "ASC" and "DESC".',
            $direction,
            $orderByField,
            $fieldName,
            $className,
        ));
    }
}
