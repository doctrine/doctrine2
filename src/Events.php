<?php

declare(strict_types=1);

namespace Doctrine\ORM;

/**
 * Container for all ORM events.
 *
 * This class cannot be instantiated.
 */
final class Events
{
    /**
     * Private constructor. This class is not meant to be instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The preRemove event occurs for a given entity before the respective
     * EntityManager remove operation for that entity is executed.
     *
     * This is an entity lifecycle event.
     */
    final public const string preRemove = 'preRemove';

    /**
     * The postRemove event occurs for an entity after the entity has
     * been deleted. It will be invoked after the database delete operations.
     *
     * This is an entity lifecycle event.
     */
    final public const string postRemove = 'postRemove';

    /**
     * The prePersist event occurs for a given entity before the respective
     * EntityManager persist operation for that entity is executed.
     *
     * This is an entity lifecycle event.
     */
    final public const string prePersist = 'prePersist';

    /**
     * The postPersist event occurs for an entity after the entity has
     * been made persistent. It will be invoked after the database insert operations.
     * Generated primary key values are available in the postPersist event.
     *
     * This is an entity lifecycle event.
     */
    final public const string postPersist = 'postPersist';

    /**
     * The preUpdate event occurs before the database update operations to
     * entity data.
     *
     * This is an entity lifecycle event.
     */
    final public const string preUpdate = 'preUpdate';

    /**
     * The postUpdate event occurs after the database update operations to
     * entity data.
     *
     * This is an entity lifecycle event.
     */
    final public const string postUpdate = 'postUpdate';

    /**
     * The postLoad event occurs for an entity after the entity has been loaded
     * into the current EntityManager from the database or after the refresh operation
     * has been applied to it.
     *
     * Note that the postLoad event occurs for an entity before any associations have been
     * initialized. Therefore, it is not safe to access associations in a postLoad callback
     * or event handler.
     *
     * This is an entity lifecycle event.
     */
    final public const string postLoad = 'postLoad';

    /**
     * The loadClassMetadata event occurs after the mapping metadata for a class
     * has been loaded from a mapping source (attributes/xml).
     */
    final public const string loadClassMetadata = 'loadClassMetadata';

    /**
     * The onClassMetadataNotFound event occurs whenever loading metadata for a class
     * failed.
     */
    final public const string onClassMetadataNotFound = 'onClassMetadataNotFound';

    /**
     * The preFlush event occurs when the EntityManager#flush() operation is invoked,
     * but before any changes to managed entities have been calculated. This event is
     * always raised right after EntityManager#flush() call.
     */
    final public const string preFlush = 'preFlush';

    /**
     * The onFlush event occurs when the EntityManager#flush() operation is invoked,
     * after any changes to managed entities have been determined but before any
     * actual database operations are executed. The event is only raised if there is
     * actually something to do for the underlying UnitOfWork.
     */
    final public const string onFlush = 'onFlush';

    /**
     * The postFlush event occurs when the EntityManager#flush() operation is invoked and
     * after all actual database operations are executed successfully. The event is only raised if there is
     * actually something to do for the underlying UnitOfWork. The event won't be raised if an error occurs during the
     * flush operation.
     */
    final public const string postFlush = 'postFlush';

    /**
     * The onClear event occurs when the EntityManager#clear() operation is invoked,
     * after all references to entities have been removed from the unit of work.
     */
    final public const string onClear = 'onClear';
}
