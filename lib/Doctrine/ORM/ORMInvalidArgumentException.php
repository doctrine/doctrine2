<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use function array_map;
use function count;
use function get_class;
use function gettype;
use function implode;
use function is_object;
use function method_exists;
use function reset;
use function spl_object_id;
use function sprintf;

/**
 * Contains exception messages for all invalid lifecycle state exceptions inside UnitOfWork
 */
class ORMInvalidArgumentException extends InvalidArgumentException
{
    /**
     * @param object $entity
     *
     * @return ORMInvalidArgumentException
     */
    public static function scheduleInsertForManagedEntity($entity)
    {
        return new self('A managed+dirty entity ' . self::objToStr($entity) . ' can not be scheduled for insertion.');
    }

    /**
     * @param object $entity
     *
     * @return ORMInvalidArgumentException
     */
    public static function scheduleInsertForRemovedEntity($entity)
    {
        return new self('Removed entity ' . self::objToStr($entity) . ' can not be scheduled for insertion.');
    }

    /**
     * @param object $entity
     *
     * @return ORMInvalidArgumentException
     */
    public static function scheduleInsertTwice($entity)
    {
        return new self('Entity ' . self::objToStr($entity) . ' can not be scheduled for insertion twice.');
    }

    /**
     * @param string $className
     * @param object $entity
     *
     * @return ORMInvalidArgumentException
     */
    public static function entityWithoutIdentity($className, $entity)
    {
        return new self(
            "The given entity of type '" . $className . "' (" . self::objToStr($entity) . ') has no identity/no ' .
            'id values set. It cannot be added to the identity map.'
        );
    }

    /**
     * @param object $entity
     *
     * @return ORMInvalidArgumentException
     */
    public static function readOnlyRequiresManagedEntity($entity)
    {
        return new self('Only managed entities can be marked or checked as read only. But ' . self::objToStr($entity) . ' is not');
    }

    /**
     * @param array[][]|object[][] $newEntitiesWithAssociations non-empty an array of [$associationMetadata, $entity] pairs
     *
     * @return ORMInvalidArgumentException
     */
    public static function newEntitiesFoundThroughRelationships($newEntitiesWithAssociations)
    {
        $errorMessages = array_map(
            static function (array $newEntityWithAssociation) : string {
                [$associationMetadata, $entity] = $newEntityWithAssociation;

                return self::newEntityFoundThroughRelationshipMessage($associationMetadata, $entity);
            },
            $newEntitiesWithAssociations
        );

        if (count($errorMessages) === 1) {
            return new self(reset($errorMessages));
        }

        return new self(
            'Multiple non-persisted new entities were found through the given association graph:'
            . "\n\n * "
            . implode("\n * ", $errorMessages)
        );
    }

    /**
     * @param object $entry
     *
     * @return ORMInvalidArgumentException
     */
    public static function newEntityFoundThroughRelationship(AssociationMetadata $association, $entry)
    {
        $message = "A new entity was found through the relationship '%s#%s' that was not configured to cascade "
            . 'persist operations for entity: %s. To solve this issue: Either explicitly call EntityManager#persist() '
            . 'on this unknown entity or configure cascade persist this association in the mapping for example '
            . '@ManyToOne(..,cascade={"persist"}).%s';

        $messageAppend = method_exists($entry, '__toString')
            ? ''
            : " If you cannot find out which entity causes the problem implement '%s#__toString()' to get a clue.";

        return new self(sprintf(
            $message,
            $association->getSourceEntity(),
            $association->getName(),
            self::objToStr($entry),
            sprintf($messageAppend, $association->getTargetEntity())
        ));
    }

    /**
     * @param object $entry
     *
     * @return ORMInvalidArgumentException
     */
    public static function detachedEntityFoundThroughRelationship(AssociationMetadata $association, $entry)
    {
        $messsage = "A detached entity of type %s (%s) was found through the relationship '%s#%s' during cascading a persist operation.";

        return new self(sprintf(
            $messsage,
            $association->getTargetEntity(),
            self::objToStr($entry),
            $association->getSourceEntity(),
            $association->getName()
        ));
    }

    /**
     * @param object $entity
     *
     * @return ORMInvalidArgumentException
     */
    public static function entityNotManaged($entity)
    {
        return new self('Entity ' . self::objToStr($entity) . ' is not managed. An entity is managed if its fetched ' .
            'from the database or registered as new through EntityManager#persist');
    }

    /**
     * @param object $entity
     * @param string $operation
     *
     * @return ORMInvalidArgumentException
     */
    public static function entityHasNoIdentity($entity, $operation)
    {
        return new self('Entity has no identity, therefore ' . $operation . ' cannot be performed. ' . self::objToStr($entity));
    }

    /**
     * @param object $entity
     * @param string $operation
     *
     * @return ORMInvalidArgumentException
     */
    public static function entityIsRemoved($entity, $operation)
    {
        return new self('Entity is removed, therefore ' . $operation . ' cannot be performed. ' . self::objToStr($entity));
    }

    /**
     * @param object $entity
     * @param string $operation
     *
     * @return ORMInvalidArgumentException
     */
    public static function detachedEntityCannot($entity, $operation)
    {
        return new self('Detached entity ' . self::objToStr($entity) . ' cannot be ' . $operation);
    }

    /**
     * @param string $context
     * @param mixed  $given
     * @param int    $parameterIndex
     *
     * @return ORMInvalidArgumentException
     */
    public static function invalidObject($context, $given, $parameterIndex = 1)
    {
        return new self($context . ' expects parameter ' . $parameterIndex .
            ' to be an entity object, ' . gettype($given) . ' given.');
    }

    /**
     * @return ORMInvalidArgumentException
     */
    public static function invalidCompositeIdentifier()
    {
        return new self('Binding an entity with a composite primary key to a query is not supported. ' .
            'You should split the parameter into the explicit fields and bind them separately.');
    }

    /**
     * @return ORMInvalidArgumentException
     */
    public static function invalidIdentifierBindingEntity()
    {
        return new self('Binding entities to query parameters only allowed for entities that have an identifier. ' .
            'Have you persisted the entity to the database?');
    }

    /**
     * @param mixed $actualValue
     *
     * @return self
     */
    public static function invalidAssociation(ClassMetadata $targetClass, AssociationMetadata $association, $actualValue)
    {
        $expectedType = $targetClass->getClassName();

        return new self(sprintf(
            'Expected value of type "%s" for association field "%s#$%s", got "%s" instead.',
            $expectedType,
            $association->getSourceEntity(),
            $association->getName(),
            is_object($actualValue) ? get_class($actualValue) : gettype($actualValue)
        ));
    }

    /**
     * Helper method to show an object as string.
     *
     * @param object $obj
     */
    private static function objToStr($obj) : string
    {
        return method_exists($obj, '__toString') ? (string) $obj : get_class($obj) . '@' . spl_object_id($obj);
    }

    /**
     * @param object $entity
     */
    private static function newEntityFoundThroughRelationshipMessage(AssociationMetadata $association, $entity) : string
    {
        return 'A new entity was found through the relationship \''
            . $association->getSourceEntity() . '#' . $association->getName() . '\' that was not'
            . ' configured to cascade persist operations for entity: ' . self::objToStr($entity) . '.'
            . ' To solve this issue: Either explicitly call EntityManager#persist()'
            . ' on this unknown entity or configure cascade persist'
            . ' this association in the mapping for example @ManyToOne(..,cascade={"persist"}).'
            . (method_exists($entity, '__toString')
                ? ''
                : ' If you cannot find out which entity causes the problem implement \''
                . $association->getTargetEntity() . '#__toString()\' to get a clue.'
            );
    }
}
