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
    public const preRemove = 'preRemove';

    /**
     * The postRemove event occurs for an entity after the entity has
     * been deleted. It will be invoked after the database delete operations.
     *
     * This is an entity lifecycle event.
     */
    public const postRemove = 'postRemove';

    /**
     * The prePersist event occurs for a given entity before the respective
     * EntityManager persist operation for that entity is executed.
     *
     * This is an entity lifecycle event.
     */
    public const prePersist = 'prePersist';

    /**
     * The postPersist event occurs for an entity after the entity has
     * been made persistent. It will be invoked after the database insert operations.
     * Generated primary key values are available in the postPersist event.
     *
     * This is an entity lifecycle event.
     */
    public const postPersist = 'postPersist';

    /**
     * The preUpdate event occurs before the database update operations to
     * entity data.
     *
     * This is an entity lifecycle event.
     */
    public const preUpdate = 'preUpdate';

    /**
     * The postUpdate event occurs after the database update operations to
     * entity data.
     *
     * This is an entity lifecycle event.
     */
    public const postUpdate = 'postUpdate';

    /**
     * The postLoad event occurs for an entity after the entity has been loaded
     * into the current EntityManager from the database or after the refresh operation
     * has been applied to it.
     *
     * Note that the postLoad event occurs for an entity before any associations have been
     * initialized. Therefore it is not safe to access associations in a postLoad callback
     * or event handler.
     *
     * This is an entity lifecycle event.
     */
    public const postLoad = 'postLoad';

    /**
     * The loadClassMetadata event occurs after the mapping metadata for a class
     * has been loaded from a mapping source (annotations/xml/yaml).
     */
    public const loadClassMetadata = 'loadClassMetadata';

    /**
     * The onClassMetadataNotFound event occurs whenever loading metadata for a class
     * failed.
     */
    public const onClassMetadataNotFound = 'onClassMetadataNotFound';

    /**
     * The preFlush event occurs when the EntityManager#flush() operation is invoked,
     * but before any changes to managed entities have been calculated. This event is
     * always raised right after EntityManager#flush() call.
     */
    public const preFlush = 'preFlush';

    /**
     * The onFlush event occurs when the EntityManager#flush() operation is invoked,
     * after any changes to managed entities have been determined but before any
     * actual database operations are executed. The event is only raised if there is
     * actually something to do for the underlying UnitOfWork. If nothing needs to be done,
     * the onFlush event is not raised.
     */
    public const onFlush = 'onFlush';

    /**
     * The postFlush event occurs when the EntityManager#flush() operation is invoked and
     * after all actual database operations are executed successfully. The event is only raised if there is
     * actually something to do for the underlying UnitOfWork. If nothing needs to be done,
     * the postFlush event is not raised. The event won't be raised if an error occurs during the
     * flush operation.
     */
    public const postFlush = 'postFlush';

    /**
     * The onClear event occurs when the EntityManager#clear() operation is invoked,
     * after all references to entities have been removed from the unit of work.
     */
    public const onClear = 'onClear';
}
