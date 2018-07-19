<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\ORM\ORMException;
use function array_keys;
use function array_map;
use function array_values;
use function get_parent_class;
use function implode;
use function sprintf;

/**
 * A MappingException indicates that something is wrong with the mapping setup.
 */
class MappingException extends \LogicException implements ORMException
{
    /**
     * @return MappingException
     */
    public static function pathRequired()
    {
        return new self('Specifying the paths to your entities is required ' .
            'in the AnnotationDriver to retrieve all class names.');
    }

    /**
     * @param string $entityName
     *
     * @return MappingException
     */
    public static function identifierRequired($entityName)
    {
        $parent = get_parent_class($entityName);

        if ($parent !== false) {
            return new self(sprintf(
                'No identifier/primary key specified for Entity "%s" sub class of "%s". Every Entity must have an identifier/primary key.',
                $entityName,
                $parent
            ));
        }

        return new self(sprintf(
            'No identifier/primary key specified for Entity "%s". Every Entity must have an identifier/primary key.',
            $entityName
        ));
    }

    /**
     * @param string $entityName
     * @param string $type
     *
     * @return MappingException
     */
    public static function invalidInheritanceType($entityName, $type)
    {
        return new self(sprintf("The inheritance type '%s' specified for '%s' does not exist.", $type, $entityName));
    }

    /**
     * @return MappingException
     */
    public static function generatorNotAllowedWithCompositeId()
    {
        return new self("Id generators can't be used with a composite id.");
    }

    /**
     * @param string $entity
     *
     * @return MappingException
     */
    public static function missingFieldName($entity)
    {
        return new self(sprintf("The field or association mapping misses the 'fieldName' attribute in entity '%s'.", $entity));
    }

    /**
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function missingTargetEntity($fieldName)
    {
        return new self(sprintf("The association mapping '%s' misses the 'targetEntity' attribute.", $fieldName));
    }

    /**
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function missingSourceEntity($fieldName)
    {
        return new self(sprintf("The association mapping '%s' misses the 'sourceEntity' attribute.", $fieldName));
    }

    /**
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function missingEmbeddedClass($fieldName)
    {
        return new self(sprintf("The embed mapping '%s' misses the 'class' attribute.", $fieldName));
    }

    /**
     * @param string $entityName
     * @param string $fileName
     *
     * @return MappingException
     */
    public static function mappingFileNotFound($entityName, $fileName)
    {
        return new self(sprintf("No mapping file found named '%s' for class '%s'.", $fileName, $entityName));
    }

    /**
     * Exception for invalid property name override.
     *
     * @param string $className The entity's name.
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function invalidOverrideFieldName($className, $fieldName)
    {
        return new self(sprintf("Invalid field override named '%s' for class '%s'.", $fieldName, $className));
    }

    /**
     * Exception for invalid property type override.
     *
     * @param string $className The entity's name.
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function invalidOverridePropertyType($className, $fieldName)
    {
        return new self(sprintf("Invalid property override named '%s' for class '%s'.", $fieldName, $className));
    }

    /**
     * Exception for invalid version property override.
     *
     * @param string $className The entity's name.
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function invalidOverrideVersionField($className, $fieldName)
    {
        return new self(sprintf("Invalid version field override named '%s' for class '%s'.", $fieldName, $className));
    }

    /**
     * Exception for invalid property type override.
     *
     * @param string $className The entity's name.
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function invalidOverrideFieldType($className, $fieldName)
    {
        return new self(sprintf("The column type of attribute '%s' on class '%s' could not be changed.", $fieldName, $className));
    }

    /**
     * @param string $className
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function mappingNotFound($className, $fieldName)
    {
        return new self(sprintf("No mapping found for field '%s' on class '%s'.", $fieldName, $className));
    }

    /**
     * @param string $className
     * @param string $queryName
     *
     * @return MappingException
     */
    public static function queryNotFound($className, $queryName)
    {
        return new self(sprintf("No query found named '%s' on class '%s'.", $queryName, $className));
    }

    /**
     * @param string $className
     * @param string $resultName
     *
     * @return MappingException
     */
    public static function resultMappingNotFound($className, $resultName)
    {
        return new self(sprintf("No result set mapping found named '%s' on class '%s'.", $resultName, $className));
    }

    /**
     * @param string $entity
     * @param string $queryName
     *
     * @return MappingException
     */
    public static function missingQueryMapping($entity, $queryName)
    {
        return new self('Query named "' . $queryName . '" in "' . $entity . ' requires a result class or result set mapping.');
    }

    /**
     * @param string $entity
     * @param string $resultName
     *
     * @return MappingException
     */
    public static function missingResultSetMappingEntity($entity, $resultName)
    {
        return new self('Result set mapping named "' . $resultName . '" in "' . $entity . ' requires a entity class name.');
    }

    /**
     * @param string $entity
     * @param string $resultName
     *
     * @return MappingException
     */
    public static function missingResultSetMappingFieldName($entity, $resultName)
    {
        return new self('Result set mapping named "' . $resultName . '" in "' . $entity . ' requires a field name.');
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function nameIsMandatoryForSqlResultSetMapping($className)
    {
        return new self(sprintf("Result set mapping name on entity class '%s' is not defined.", $className));
    }

    /**
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function oneToManyRequiresMappedBy($fieldName)
    {
        return new self(sprintf("OneToMany mapping on field '%s' requires the 'mappedBy' attribute.", $fieldName));
    }

    /**
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function joinTableRequired($fieldName)
    {
        return new self(sprintf("The mapping of field '%s' requires an the 'joinTable' attribute.", $fieldName));
    }

    /**
     * Called if a required option was not found but is required
     *
     * @param string $field          Which field cannot be processed?
     * @param string $expectedOption Which option is required
     * @param string $hint           Can optionally be used to supply a tip for common mistakes,
     *                               e.g. "Did you think of the plural s?"
     *
     * @return MappingException
     */
    public static function missingRequiredOption($field, $expectedOption, $hint = '')
    {
        $message = sprintf("The mapping of field '%s' is invalid: The option '%s' is required.", $field, $expectedOption);

        if (! empty($hint)) {
            $message .= ' (Hint: ' . $hint . ')';
        }

        return new self($message);
    }

    /**
     * Generic exception for invalid mappings.
     *
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function invalidMapping($fieldName)
    {
        return new self(sprintf("The mapping of field '%s' is invalid.", $fieldName));
    }

    /**
     * Exception for reflection exceptions - adds the entity name,
     * because there might be long classnames that will be shortened
     * within the stacktrace
     *
     * @param string $entity The entity's name
     *
     * @return MappingException
     */
    public static function reflectionFailure($entity, \ReflectionException $previousException)
    {
        return new self('An error occurred in ' . $entity, 0, $previousException);
    }

    /**
     * @param string $className
     * @param string $joinColumn
     *
     * @return MappingException
     */
    public static function joinColumnMustPointToMappedField($className, $joinColumn)
    {
        return new self('The column ' . $joinColumn . ' must be mapped to a field in class '
            . $className . ' since it is referenced by a join column of another class.');
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function classIsNotAValidEntityOrMappedSuperClass($className)
    {
        $parent = get_parent_class($className);

        if ($parent !== false) {
            return new self(sprintf(
                'Class "%s" sub class of "%s" is not a valid entity or mapped super class.',
                $className,
                $parent
            ));
        }

        return new self(sprintf(
            'Class "%s" is not a valid entity or mapped super class.',
            $className
        ));
    }

    /**
     * @param string $className
     * @param string $propertyName
     *
     * @return MappingException
     */
    public static function propertyTypeIsRequired($className, $propertyName)
    {
        return new self("The attribute 'type' is required for the column description of property " . $className . '::$' . $propertyName . '.');
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function tableIdGeneratorNotImplemented($className)
    {
        return new self('TableIdGenerator is not yet implemented for use with class ' . $className);
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function duplicateProperty($className, Property $property)
    {
        return new self(sprintf(
            'Property "%s" in "%s" was already declared in "%s", but it must be declared only once',
            $property->getName(),
            $className,
            $property->getDeclaringClass()->getClassName()
        ));
    }

    /**
     * @param string $entity
     * @param string $queryName
     *
     * @return MappingException
     */
    public static function duplicateQueryMapping($entity, $queryName)
    {
        return new self('Query named "' . $queryName . '" in "' . $entity . '" was already declared, but it must be declared only once');
    }

    /**
     * @param string $entity
     * @param string $resultName
     *
     * @return MappingException
     */
    public static function duplicateResultSetMapping($entity, $resultName)
    {
        return new self('Result set mapping named "' . $resultName . '" in "' . $entity . '" was already declared, but it must be declared only once');
    }

    /**
     * @param string $entity
     *
     * @return MappingException
     */
    public static function singleIdNotAllowedOnCompositePrimaryKey($entity)
    {
        return new self('Single id is not allowed on composite primary key in entity ' . $entity);
    }

    /**
     * @param string $entity
     *
     * @return MappingException
     */
    public static function noIdDefined($entity)
    {
        return new self('No ID defined for entity ' . $entity);
    }

    /**
     * @param string $unsupportedType
     *
     * @return MappingException
     */
    public static function unsupportedOptimisticLockingType($unsupportedType)
    {
        return new self('Locking type "' . $unsupportedType . '" is not supported by Doctrine.');
    }

    /**
     * @param string|null $path
     *
     * @return MappingException
     */
    public static function fileMappingDriversRequireConfiguredDirectoryPath($path = null)
    {
        if (! empty($path)) {
            $path = '[' . $path . ']';
        }

        return new self(
            'File mapping drivers must have a valid directory path, ' .
            'however the given path ' . $path . ' seems to be incorrect!'
        );
    }

    /**
     * Returns an exception that indicates that a class used in a discriminator map does not exist.
     * An example would be an outdated (maybe renamed) classname.
     *
     * @param string $className   The class that could not be found
     * @param string $owningClass The class that declares the discriminator map.
     *
     * @return MappingException
     */
    public static function invalidClassInDiscriminatorMap($className, $owningClass)
    {
        return new self(sprintf(
            "Entity class '%s' used in the discriminator map of class '%s' " .
            'does not exist.',
            $className,
            $owningClass
        ));
    }

    /**
     * @param string   $className
     * @param string[] $entries
     * @param string[] $map
     *
     * @return MappingException
     */
    public static function duplicateDiscriminatorEntry($className, array $entries, array $map)
    {
        return new self(
            'The entries ' . implode(', ', $entries) . " in discriminator map of class '" . $className . "' is duplicated. " .
            'If the discriminator map is automatically generated you have to convert it to an explicit discriminator map now. ' .
            'The entries of the current map are: @DiscriminatorMap({' . implode(', ', array_map(
                function ($a, $b) {
                    return sprintf("'%s': '%s'", $a, $b);
                },
                array_keys($map),
                array_values($map)
            )) . '})'
        );
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function missingDiscriminatorMap($className)
    {
        return new self(sprintf("Entity class '%s' is using inheritance but no discriminator map was defined.", $className));
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function missingDiscriminatorColumn($className)
    {
        return new self(sprintf("Entity class '%s' is using inheritance but no discriminator column was defined.", $className));
    }

    /**
     * @param string $type
     *
     * @return MappingException
     */
    public static function invalidDiscriminatorColumnType($type)
    {
        return new self(sprintf("Discriminator column type is not allowed to be '%s'. 'string' or 'integer' type variables are suggested!", $type));
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function nameIsMandatoryForDiscriminatorColumns($className)
    {
        return new self(sprintf("Discriminator column name on entity class '%s' is not defined.", $className));
    }

    /**
     * @param string $className
     * @param string $fieldName
     *
     * @return MappingException
     */
    public static function cannotVersionIdField($className, $fieldName)
    {
        return new self(sprintf("Setting Id field '%s' as versionable in entity class '%s' is not supported.", $fieldName, $className));
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function sqlConversionNotAllowedForPrimaryKeyProperties($className, Property $property)
    {
        return new self(sprintf(
            'It is not possible to set id field "%s" to type "%s" in entity class "%s". ' .
            'The type "%s" requires conversion SQL which is not allowed for identifiers.',
            $property->getName(),
            $property->getTypeName(),
            $className,
            $property->getTypeName()
        ));
    }

    /**
     * @param string $className
     * @param string $fieldName
     * @param string $type
     *
     * @return MappingException
     */
    public static function sqlConversionNotAllowedForIdentifiers($className, $fieldName, $type)
    {
        return new self(sprintf(
            "It is not possible to set id field '%s' to type '%s' in entity class '%s'. The type '%s' "
                . 'requires conversion SQL which is not allowed for identifiers.',
            $fieldName,
            $type,
            $className,
            $type
        ));
    }

    /**
     * @param string $className
     * @param string $columnName
     *
     * @return MappingException
     */
    public static function duplicateColumnName($className, $columnName)
    {
        return new self("Duplicate definition of column '" . $columnName . "' on entity '" . $className . "' in a field or discriminator column mapping.");
    }

    /**
     * @param string $className
     * @param string $field
     *
     * @return MappingException
     */
    public static function illegalToManyAssociationOnMappedSuperclass($className, $field)
    {
        return new self("It is illegal to put an inverse side one-to-many or many-to-many association on mapped superclass '" . $className . '#' . $field . "'.");
    }

    /**
     * @param string $className
     * @param string $targetEntity
     * @param string $targetField
     *
     * @return MappingException
     */
    public static function cannotMapCompositePrimaryKeyEntitiesAsForeignId($className, $targetEntity, $targetField)
    {
        return new self("It is not possible to map entity '" . $className . "' with a composite primary key " .
            "as part of the primary key of another entity '" . $targetEntity . '#' . $targetField . "'.");
    }

    /**
     * @param string $className
     * @param string $field
     *
     * @return MappingException
     */
    public static function noSingleAssociationJoinColumnFound($className, $field)
    {
        return new self(sprintf("'%s#%s' is not an association with a single join column.", $className, $field));
    }

    /**
     * @param string $className
     * @param string $column
     *
     * @return MappingException
     */
    public static function noFieldNameFoundForColumn($className, $column)
    {
        return new self(sprintf(
            "Cannot find a field on '%s' that is mapped to column '%s'. Either the "
                . 'field does not exist or an association exists but it has multiple join columns.',
            $className,
            $column
        ));
    }

    /**
     * @param string $className
     * @param string $field
     *
     * @return MappingException
     */
    public static function illegalOrphanRemovalOnIdentifierAssociation($className, $field)
    {
        return new self(sprintf(
            'The orphan removal option is not allowed on an association that is '
                . "part of the identifier in '%s#%s'.",
            $className,
            $field
        ));
    }

    /**
     * @param string $className
     * @param string $field
     *
     * @return MappingException
     */
    public static function illegalOrphanRemoval($className, $field)
    {
        return new self('Orphan removal is only allowed on one-to-one and one-to-many ' .
            'associations, but ' . $className . '#' . $field . ' is not.');
    }

    /**
     * @param string $className
     * @param string $field
     *
     * @return MappingException
     */
    public static function illegalInverseIdentifierAssociation($className, $field)
    {
        return new self(sprintf("An inverse association is not allowed to be identifier in '%s#%s'.", $className, $field));
    }

    /**
     * @param string $className
     * @param string $field
     *
     * @return MappingException
     */
    public static function illegalToManyIdentifierAssociation($className, $field)
    {
        return new self(sprintf("Many-to-many or one-to-many associations are not allowed to be identifier in '%s#%s'.", $className, $field));
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function noInheritanceOnMappedSuperClass($className)
    {
        return new self("It is not supported to define inheritance information on a mapped superclass '" . $className . "'.");
    }

    /**
     * @param string $className
     * @param string $rootClassName
     *
     * @return MappingException
     */
    public static function mappedClassNotPartOfDiscriminatorMap($className, $rootClassName)
    {
        return new self(
            "Entity '" . $className . "' has to be part of the discriminator map of '" . $rootClassName . "' " .
            "to be properly mapped in the inheritance hierarchy. Alternatively you can make '" . $className . "' an abstract class " .
            'to avoid this exception from occurring.'
        );
    }

    /**
     * @param string $className
     * @param string $methodName
     *
     * @return MappingException
     */
    public static function lifecycleCallbackMethodNotFound($className, $methodName)
    {
        return new self("Entity '" . $className . "' has no method '" . $methodName . "' to be registered as lifecycle callback.");
    }

    /**
     * @param string $listenerName
     * @param string $className
     *
     * @return \Doctrine\ORM\Mapping\MappingException
     */
    public static function entityListenerClassNotFound($listenerName, $className)
    {
        return new self(sprintf('Entity Listener "%s" declared on "%s" not found.', $listenerName, $className));
    }

    /**
     * @param string $listenerName
     * @param string $methodName
     * @param string $className
     *
     * @return \Doctrine\ORM\Mapping\MappingException
     */
    public static function entityListenerMethodNotFound($listenerName, $methodName, $className)
    {
        return new self(sprintf('Entity Listener "%s" declared on "%s" has no method "%s".', $listenerName, $className, $methodName));
    }

    /**
     * @param string $listenerName
     * @param string $methodName
     * @param string $className
     *
     * @return \Doctrine\ORM\Mapping\MappingException
     */
    public static function duplicateEntityListener($listenerName, $methodName, $className)
    {
        return new self(sprintf('Entity Listener "%s#%s()" in "%s" was already declared, but it must be declared only once.', $listenerName, $methodName, $className));
    }

    /**
     * @param string $className
     * @param string $annotation
     *
     * @return MappingException
     */
    public static function invalidFetchMode($className, $annotation)
    {
        return new self("Entity '" . $className . "' has a mapping with invalid fetch mode '" . $annotation . "'");
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function compositeKeyAssignedIdGeneratorRequired($className)
    {
        return new self("Entity '" . $className . "' has a composite identifier but uses an ID generator other than manually assigning (Identity, Sequence). This is not supported.");
    }

    /**
     * @param string $targetEntity
     * @param string $sourceEntity
     * @param string $associationName
     *
     * @return MappingException
     */
    public static function invalidTargetEntityClass($targetEntity, $sourceEntity, $associationName)
    {
        return new self("The target-entity '" . $targetEntity . "' cannot be found in '" . $sourceEntity . '#' . $associationName . "'.");
    }

    /**
     * @param string[] $cascades
     * @param string   $className
     * @param string   $propertyName
     *
     * @return MappingException
     */
    public static function invalidCascadeOption(array $cascades, $className, $propertyName)
    {
        $cascades = implode(', ', array_map(function ($e) {
            return "'" . $e . "'";
        }, $cascades));

        return new self(sprintf(
            "You have specified invalid cascade options for %s::$%s: %s; available options: 'remove', 'persist', and 'refresh'",
            $className,
            $propertyName,
            $cascades
        ));
    }

    /**
     * @param string $className
     *
     * @return MappingException
     */
    public static function missingSequenceName($className)
    {
        return new self(
            sprintf('Missing "sequenceName" attribute for sequence id generator definition on class "%s".', $className)
        );
    }

    /**
     * @param string $className
     * @param string $propertyName
     *
     * @return MappingException
     */
    public static function infiniteEmbeddableNesting($className, $propertyName)
    {
        return new self(
            sprintf(
                'Infinite nesting detected for embedded property %s::%s. ' .
                'You cannot embed an embeddable from the same type inside an embeddable.',
                $className,
                $propertyName
            )
        );
    }

    /**
     * @param string[] $namespaces
     */
    public static function classNotFoundInNamespaces(string $className, array $namespaces) : self
    {
        return new self(
            sprintf(
                'Class %s not found in namespaces %s.' .
                $className,
                implode(', ', $namespaces)
            )
        );
    }
}
