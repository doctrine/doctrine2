<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use Stringable;

use function array_map;
use function count;
use function get_debug_type;
use function gettype;
use function implode;
use function is_scalar;
use function reset;
use function spl_object_id;
use function sprintf;

/**
 * Contains exception messages for all invalid lifecycle state exceptions inside UnitOfWork
 */
class ORMInvalidArgumentException extends InvalidArgumentException
{
    public static function scheduleInsertForManagedEntity(object $entity): self
    {
        return new self('A managed+dirty entity ' . self::objToStr($entity) . ' can not be scheduled for insertion.');
    }

    public static function scheduleInsertForRemovedEntity(object $entity): self
    {
        return new self('Removed entity ' . self::objToStr($entity) . ' can not be scheduled for insertion.');
    }

    public static function scheduleInsertTwice(object $entity): self
    {
        return new self('Entity ' . self::objToStr($entity) . ' can not be scheduled for insertion twice.');
    }

    public static function entityWithoutIdentity(string $className, object $entity): self
    {
        return new self(
            "The given entity of type '" . $className . "' (" . self::objToStr($entity) . ') has no identity/no ' .
            'id values set. It cannot be added to the identity map.',
        );
    }

    public static function readOnlyRequiresManagedEntity(object $entity): self
    {
        return new self('Only managed entities can be marked or checked as read only. But ' . self::objToStr($entity) . ' is not');
    }

    /** @param non-empty-list<array{AssociationMapping, object}> $newEntitiesWithAssociations */
    public static function newEntitiesFoundThroughRelationships(array $newEntitiesWithAssociations): self
    {
        $errorMessages = array_map(
            static function (array $newEntityWithAssociation): string {
                [$associationMapping, $entity] = $newEntityWithAssociation;

                return self::newEntityFoundThroughRelationshipMessage($associationMapping, $entity);
            },
            $newEntitiesWithAssociations,
        );

        if (count($errorMessages) === 1) {
            return new self(reset($errorMessages));
        }

        return new self(
            'Multiple non-persisted new entities were found through the given association graph:'
            . "\n\n * "
            . implode("\n * ", $errorMessages),
        );
    }

    public static function newEntityFoundThroughRelationship(AssociationMapping $associationMapping, object $entry): self
    {
        return new self(self::newEntityFoundThroughRelationshipMessage($associationMapping, $entry));
    }

    public static function detachedEntityFoundThroughRelationship(AssociationMapping $assoc, object $entry): self
    {
        return new self('A detached entity of type ' . $assoc->targetEntity . ' (' . self::objToStr($entry) . ') '
            . " was found through the relationship '" . $assoc->sourceEntity . '#' . $assoc->fieldName . "' "
            . 'during cascading a persist operation.');
    }

    public static function entityNotManaged(object $entity): self
    {
        return new self('Entity ' . self::objToStr($entity) . ' is not managed. An entity is managed if its fetched ' .
            'from the database or registered as new through EntityManager#persist');
    }

    public static function entityHasNoIdentity(object $entity, string $operation): self
    {
        return new self('Entity has no identity, therefore ' . $operation . ' cannot be performed. ' . self::objToStr($entity));
    }

    public static function entityIsRemoved(object $entity, string $operation): self
    {
        return new self('Entity is removed, therefore ' . $operation . ' cannot be performed. ' . self::objToStr($entity));
    }

    public static function detachedEntityCannot(object $entity, string $operation): self
    {
        return new self('Detached entity ' . self::objToStr($entity) . ' cannot be ' . $operation);
    }

    public static function invalidObject(string $context, mixed $given, int $parameterIndex = 1): self
    {
        return new self($context . ' expects parameter ' . $parameterIndex .
            ' to be an entity object, ' . gettype($given) . ' given.');
    }

    public static function invalidCompositeIdentifier(): self
    {
        return new self('Binding an entity with a composite primary key to a query is not supported. ' .
            'You should split the parameter into the explicit fields and bind them separately.');
    }

    public static function invalidIdentifierBindingEntity(string $class): self
    {
        return new self(sprintf(
            <<<'EXCEPTION'
Binding entities to query parameters only allowed for entities that have an identifier.
Class "%s" does not have an identifier.
EXCEPTION
            ,
            $class,
        ));
    }

    public static function invalidAssociation(ClassMetadata $targetClass, AssociationMapping $assoc, mixed $actualValue): self
    {
        $expectedType = $targetClass->getName();

        return new self(sprintf(
            'Expected value of type "%s" for association field "%s#$%s", got "%s" instead.',
            $expectedType,
            $assoc->sourceEntity,
            $assoc->fieldName,
            get_debug_type($actualValue),
        ));
    }

    public static function invalidAutoGenerateMode(mixed $value): self
    {
        return new self(sprintf('Invalid auto generate mode "%s" given.', is_scalar($value) ? (string) $value : get_debug_type($value)));
    }

    public static function missingPrimaryKeyValue(string $className, string $idField): self
    {
        return new self(sprintf('Missing value for primary key %s on %s', $idField, $className));
    }

    /**
     * Helper method to show an object as string.
     */
    private static function objToStr(object $obj): string
    {
        return $obj instanceof Stringable ? (string) $obj : get_debug_type($obj) . '@' . spl_object_id($obj);
    }

    private static function newEntityFoundThroughRelationshipMessage(AssociationMapping $associationMapping, object $entity): string
    {
        return 'A new entity was found through the relationship \''
            . $associationMapping->sourceEntity . '#' . $associationMapping->fieldName . '\' that was not'
            . ' configured to cascade persist operations for entity: ' . self::objToStr($entity) . '.'
            . ' To solve this issue: Either explicitly call EntityManager#persist()'
            . ' on this unknown entity or configure cascade persist'
            . ' this association in the mapping for example #[ORM\ManyToOne(..., cascade: [\'persist\'])].'
            . ($entity instanceof Stringable
                ? ''
                : ' If you cannot find out which entity causes the problem implement \''
                . $associationMapping->targetEntity . '#__toString()\' to get a clue.'
            );
    }
}
