<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use BackedEnum;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\EventDispatcher;
use Doctrine\DBAL;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\Event\ListenersInvoker;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Exception\EntityIdentityCollisionException;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Exception\UnexpectedAssociationValue;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Internal\HydrationCompleteHandler;
use Doctrine\ORM\Internal\StronglyConnectedComponents;
use Doctrine\ORM\Internal\TopologicalSort;
use Doctrine\ORM\Internal\UnitOfWork\InsertBatch;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\ORM\Mapping\PropertyAccessors\ReadonlyAccessor;
use Doctrine\ORM\Mapping\ToManyInverseSideMapping;
use Doctrine\ORM\Persisters\Collection\CollectionPersister;
use Doctrine\ORM\Persisters\Collection\ManyToManyPersister;
use Doctrine\ORM\Persisters\Collection\OneToManyPersister;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Persisters\Entity\JoinedSubclassPersister;
use Doctrine\ORM\Persisters\Entity\SingleTablePersister;
use Doctrine\ORM\Proxy\InternalProxy;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\Persistence\PropertyChangedListener;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Symfony\Component\VarExporter\Hydrator;
use UnexpectedValueException;

use function array_chunk;
use function array_combine;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function assert;
use function count;
use function current;
use function deepclone_hydrate;
use function extension_loaded;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function reset;
use function spl_object_id;
use function sprintf;
use function strcmp;
use function strtolower;
use function usort;

use const PHP_VERSION_ID;

/**
 * The UnitOfWork is responsible for tracking changes to objects during an
 * "object-level" transaction and for writing out changes to the database
 * in the correct order.
 *
 * Internal note: This class contains highly performance-sensitive code.
 */
class UnitOfWork implements PropertyChangedListener
{
    /**
     * An entity is in MANAGED state when its persistence is managed by an EntityManager.
     */
    public const STATE_MANAGED = 1;

    /**
     * An entity is new if it has just been instantiated (i.e. using the "new" operator)
     * and is not (yet) managed by an EntityManager.
     */
    public const STATE_NEW = 2;

    /**
     * A detached entity is an instance with persistent state and identity that is not
     * (or no longer) associated with an EntityManager (and a UnitOfWork).
     */
    public const STATE_DETACHED = 3;

    /**
     * A removed entity instance is an instance with a persistent identity,
     * associated with an EntityManager, whose persistent state will be deleted
     * on commit.
     */
    public const STATE_REMOVED = 4;

    /**
     * Hint used to collect all primary keys of associated entities during hydration
     * and execute it in a dedicated query afterwards
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/stable/reference/dql-doctrine-query-language.html#temporarily-change-fetch-mode-in-dql
     */
    public const HINT_DEFEREAGERLOAD = 'deferEagerLoad';

    /**
     * The identity map that holds references to all managed entities that have
     * an identity. The entities are grouped by their class name.
     * Since all classes in a hierarchy must share the same identifier set,
     * we always take the root class name of the hierarchy.
     *
     * @var array<class-string, array<string, object>>
     */
    private array $identityMap = [];

    /**
     * Map of all identifiers of managed entities.
     * Keys are object ids (spl_object_id).
     *
     * @phpstan-var array<int, array<string, mixed>>
     */
    private array $entityIdentifiers = [];

    /**
     * Map of the original entity data of managed entities.
     * Keys are object ids (spl_object_id). This is used for calculating changesets
     * at commit time.
     *
     * Internal note: Note that PHPs "copy-on-write" behavior helps a lot with memory usage.
     *                A value will only really be copied if the value in the entity is modified
     *                by the user.
     *
     * @phpstan-var array<int, array<string, mixed>>
     */
    private array $originalEntityData = [];

    /**
     * Map of entity changes. Keys are object ids (spl_object_id).
     * Filled at the beginning of a commit of the UnitOfWork and cleaned at the end.
     *
     * @phpstan-var array<int, array<string, array{mixed, mixed}>>
     */
    private array $entityChangeSets = [];

    /**
     * The (cached) states of any known entities.
     * Keys are object ids (spl_object_id).
     *
     * @phpstan-var array<int, self::STATE_*>
     */
    private array $entityStates = [];

    /**
     * Map of entities that are scheduled for dirty checking at commit time.
     * This is only used for entities with a change tracking policy of DEFERRED_EXPLICIT.
     * Keys are object ids (spl_object_id).
     *
     * @var array<class-string, array<int, mixed>>
     */
    private array $scheduledForSynchronization = [];

    /**
     * A list of all pending entity insertions.
     *
     * @phpstan-var array<int, object>
     */
    private array $entityInsertions = [];

    /**
     * A list of all pending entity updates.
     *
     * @phpstan-var array<int, object>
     */
    private array $entityUpdates = [];

    /**
     * Any pending extra updates that have been scheduled by persisters.
     *
     * @phpstan-var array<int, array{object, array<string, array{mixed, mixed}>}>
     */
    private array $extraUpdates = [];

    /**
     * A list of all pending entity deletions.
     *
     * @phpstan-var array<int, object>
     */
    private array $entityDeletions = [];

    /**
     * New entities that were discovered through relationships that were not
     * marked as cascade-persist. During flush, this array is populated and
     * then pruned of any entities that were discovered through a valid
     * cascade-persist path. (Leftovers cause an error.)
     *
     * Keys are OIDs, payload is a two-item array describing the association
     * and the entity.
     *
     * @var array<int, array{AssociationMapping, object}> indexed by respective object spl_object_id()
     */
    private array $nonCascadedNewDetectedEntities = [];

    /**
     * All pending collection deletions.
     *
     * @phpstan-var array<int, PersistentCollection<array-key, object>>
     */
    private array $collectionDeletions = [];

    /**
     * All pending collection updates.
     *
     * @phpstan-var array<int, PersistentCollection<array-key, object>>
     */
    private array $collectionUpdates = [];

    /**
     * List of collections visited during changeset calculation on a commit-phase of a UnitOfWork.
     * At the end of the UnitOfWork all these collections will make new snapshots
     * of their data.
     *
     * @phpstan-var array<int, PersistentCollection<array-key, object>>
     */
    private array $visitedCollections = [];

    /**
     * List of collections visited during the changeset calculation that contain to-be-removed
     * entities and need to have keys removed post commit.
     *
     * Indexed by Collection object ID, which also serves as the key in self::$visitedCollections;
     * values are the key names that need to be removed.
     *
     * @phpstan-var array<int, array<array-key, true>>
     */
    private array $pendingCollectionElementRemovals = [];

    /**
     * The entity persister instances used to persist entity instances.
     *
     * @phpstan-var array<string, EntityPersister>
     */
    private array $persisters = [];

    /**
     * The collection persister instances used to persist collections.
     *
     * @phpstan-var array<array-key, CollectionPersister>
     */
    private array $collectionPersisters = [];

    /**
     * The EventDispatcher used for dispatching events.
     */
    private readonly EventDispatcher $eventDispatcher;

    /**
     * The ListenersInvoker used for dispatching events.
     */
    private readonly ListenersInvoker $listenersInvoker;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     */
    private readonly IdentifierFlattener $identifierFlattener;

    /**
     * Orphaned entities that are scheduled for removal.
     *
     * @phpstan-var array<int, object>
     */
    private array $orphanRemovals = [];

    /**
     * Read-Only objects are never evaluated
     *
     * @var array<int, true>
     */
    private array $readOnlyObjects = [];

    /**
     * Maps OIDs of native lazy objects that are not (yet) fully loaded to the
     * list of scalar field names that are actually known to be loaded: either
     * the fields selected by a partial DQL query, or an empty list for a
     * to-one association proxy that hasn't been initialized at all.
     *
     * Used during lazy init to determine precisely which fields must not be
     * overwritten from the full load, and by computeChangeSet() as the source
     * of truth for which fields to diff — trusted over inferring the same
     * thing from the shape of originalEntityData, which can be legitimately
     * empty for other reasons.
     *
     * @var array<int, list<string>>
     */
    private array $partialObjectLoadedFields = [];

    /**
     * Map of Entity Class-Names and corresponding IDs that should eager loaded when requested.
     *
     * @var array<class-string, array<string, mixed>>
     */
    private array $eagerLoadingEntities = [];

    /** @var array<string, array<string, mixed>> */
    private array $eagerLoadingCollections = [];

    protected bool $hasCache = false;

    /**
     * Helper for handling completion of hydration
     */
    private readonly HydrationCompleteHandler $hydrationCompleteHandler;

    /**
     * Initializes a new UnitOfWork instance, bound to the given EntityManager.
     *
     * @param EntityManagerInterface $em The EntityManager that "owns" this UnitOfWork instance.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->eventDispatcher          = $em->getEventManager();
        $this->listenersInvoker         = new ListenersInvoker($em);
        $this->hasCache                 = $em->getConfiguration()->isSecondLevelCacheEnabled();
        $this->identifierFlattener      = new IdentifierFlattener($this, $em->getMetadataFactory());
        $this->hydrationCompleteHandler = new HydrationCompleteHandler($this->listenersInvoker, $em);
    }

    /**
     * Commits the UnitOfWork, executing all operations that have been postponed
     * up to this point. The state of all managed entities will be synchronized with
     * the database.
     *
     * The operations are executed in the following order:
     *
     * 1) All entity insertions
     * 2) All entity updates
     * 3) All collection deletions
     * 4) All collection updates
     * 5) All entity deletions
     *
     * @throws Exception
     */
    public function commit(): void
    {
        $connection = $this->em->getConnection();

        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }

        $this->dispatchPreFlushEvent();

        // Compute changes done since last commit.
        $this->computeChangeSets();

        if (
            ! ($this->entityInsertions ||
                $this->entityDeletions ||
                $this->entityUpdates ||
                $this->collectionUpdates ||
                $this->collectionDeletions ||
                $this->orphanRemovals)
        ) {
            $this->dispatchOnFlushEvent();
            $this->dispatchPostFlushEvent();

            $this->postCommitCleanup();

            return; // Nothing to do.
        }

        $this->assertThatThereAreNoUnintentionallyNonPersistedAssociations();

        if ($this->orphanRemovals) {
            foreach ($this->orphanRemovals as $orphan) {
                $this->remove($orphan);
            }
        }

        $this->dispatchOnFlushEvent();

        $connection->beginTransaction();

        $successful = false;

        try {
            // Collection deletions (deletions of complete collections)
            foreach ($this->collectionDeletions as $collectionToDelete) {
                // Deferred explicit tracked collections can be removed only when owning relation was persisted
                $owner = $collectionToDelete->getOwner();

                if ($this->em->getClassMetadata($owner::class)->isChangeTrackingDeferredImplicit() || $this->isScheduledForDirtyCheck($owner)) {
                    $this->getCollectionPersister($collectionToDelete->getMapping())->delete($collectionToDelete);
                }
            }

            if ($this->entityInsertions) {
                // Perform entity insertions first, so that all new entities have their rows in the database
                // and can be referred to by foreign keys. The commit order only needs to take new entities
                // into account (new entities referring to other new entities), since all other types (entities
                // with updates or scheduled deletions) are currently not a problem, since they are already
                // in the database.
                $this->executeInserts();
            }

            if ($this->entityUpdates) {
                // Updates are executed in a consistent order (class name, then entity ID (hash)) to eliminate deadlock
                // risk in concurrent update transactions ("Process X waits for ShareLock on transaction Y; blocked
                // by process Z").
                $this->executeUpdates();
            }

            // Extra updates that were requested by persisters.
            // This may include foreign keys that could not be set when an entity was inserted,
            // which may happen in the case of circular foreign key relationships.
            if ($this->extraUpdates) {
                $this->executeExtraUpdates();
            }

            // Collection updates (deleteRows, updateRows, insertRows)
            // No particular order is necessary, since all entities themselves are already
            // in the database
            foreach ($this->collectionUpdates as $collectionToUpdate) {
                $this->getCollectionPersister($collectionToUpdate->getMapping())->update($collectionToUpdate);
            }

            // Entity deletions come last. Their order only needs to take care of other deletions
            // (first delete entities depending upon others, before deleting depended-upon entities).
            if ($this->entityDeletions) {
                $this->executeDeletions();
            }

            $commitFailed = false;
            try {
                if ($connection->commit() === false) {
                    $commitFailed = true;
                }
            } catch (DBAL\Exception $e) {
                $commitFailed = true;
            }

            if ($commitFailed) {
                throw new OptimisticLockException('Commit failed', null, $e ?? null);
            }

            $successful = true;
        } finally {
            if (! $successful) {
                $this->em->close();

                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                $this->afterTransactionRolledBack();
            }
        }

        $this->afterTransactionComplete();

        // Unset removed entities from collections, and take new snapshots from
        // all visited collections.
        foreach ($this->visitedCollections as $coid => $coll) {
            if (isset($this->pendingCollectionElementRemovals[$coid])) {
                foreach ($this->pendingCollectionElementRemovals[$coid] as $key => $valueIgnored) {
                    unset($coll[$key]);
                }
            }

            $coll->takeSnapshot();
        }

        $this->dispatchPostFlushEvent();

        $this->postCommitCleanup();
    }

    private function postCommitCleanup(): void
    {
        $this->entityInsertions                 =
        $this->entityUpdates                    =
        $this->entityDeletions                  =
        $this->extraUpdates                     =
        $this->collectionUpdates                =
        $this->nonCascadedNewDetectedEntities   =
        $this->collectionDeletions              =
        $this->pendingCollectionElementRemovals =
        $this->visitedCollections               =
        $this->orphanRemovals                   =
        $this->entityChangeSets                 =
        $this->scheduledForSynchronization      = [];
    }

    /**
     * Computes the changesets of all entities scheduled for insertion.
     */
    private function computeScheduleInsertsChangeSets(): void
    {
        foreach ($this->entityInsertions as $entity) {
            $class = $this->em->getClassMetadata($entity::class);

            if (PHP_VERSION_ID >= 80400 && $class->reflClass->isUninitializedLazyObject($entity)) {
                $class->reflClass->initializeLazyObject($entity);
            }

            $this->computeChangeSet($class, $entity);
        }
    }

    /**
     * Executes any extra updates that have been scheduled.
     */
    private function executeExtraUpdates(): void
    {
        foreach ($this->extraUpdates as $oid => $update) {
            [$entity, $changeset] = $update;

            $this->entityChangeSets[$oid] = $changeset;
            $this->getEntityPersister($entity::class)->update($entity);
        }

        $this->extraUpdates = [];
    }

    /**
     * Gets the changeset for an entity.
     *
     * @return mixed[][]
     * @phpstan-return array<string, array{mixed, mixed}|PersistentCollection>
     */
    public function & getEntityChangeSet(object $entity): array
    {
        $oid  = spl_object_id($entity);
        $data = [];

        if (! isset($this->entityChangeSets[$oid])) {
            return $data;
        }

        return $this->entityChangeSets[$oid];
    }

    /**
     * Computes the changes that happened to a single entity.
     *
     * Modifies/populates the following properties:
     *
     * {@link _originalEntityData}
     * If the entity is NEW or MANAGED but not yet fully persisted (only has an id)
     * then it was not fetched from the database and therefore we have no original
     * entity data yet. All of the current entity data is stored as the original entity data.
     *
     * {@link _entityChangeSets}
     * The changes detected on all properties of the entity are stored there.
     * A change is a tuple array where the first entry is the old value and the second
     * entry is the new value of the property. Changesets are used by persisters
     * to INSERT/UPDATE the persistent entity state.
     *
     * {@link _entityUpdates}
     * If the entity is already fully MANAGED (has been fetched from the database before)
     * and any changes to its properties are detected, then a reference to the entity is stored
     * there to mark it for an update.
     *
     * {@link _collectionDeletions}
     * If a PersistentCollection has been de-referenced in a fully MANAGED entity,
     * then this collection is marked for deletion.
     *
     * @param ClassMetadata $class  The class descriptor of the entity.
     * @param object        $entity The entity for which to compute the changes.
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @template T of object
     *
     * @ignore
     */
    public function computeChangeSet(ClassMetadata $class, object $entity): void
    {
        $oid = spl_object_id($entity);

        if (isset($this->readOnlyObjects[$oid])) {
            return;
        }

        if (! $class->isInheritanceTypeNone()) {
            $class = $this->em->getClassMetadata($entity::class);
        }

        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::preFlush) & ~ListenersInvoker::INVOKE_MANAGER;

        if ($invoke !== ListenersInvoker::INVOKE_NONE) {
            $this->listenersInvoker->invoke($class, Events::preFlush, $entity, new PreFlushEventArgs($this->em), $invoke);
        }

        $isNew      = ! isset($this->originalEntityData[$oid]);
        $actualData = [];

        foreach ($class->propertyAccessors as $name => $refProp) {
            $value = $refProp->getValue($entity);

            if ($class->isCollectionValuedAssociation($name) && $value !== null) {
                if ($value instanceof PersistentCollection) {
                    if ($value->getOwner() === $entity) {
                        $actualData[$name] = $value;
                        continue;
                    }

                    $value = new ArrayCollection($value->getValues());
                }

                // If $value is not a Collection then use an ArrayCollection.
                if (! $value instanceof Collection) {
                    $value = new ArrayCollection($value);
                }

                $assoc = $class->associationMappings[$name];
                assert($assoc->isToMany());

                // Inject PersistentCollection
                $value = new PersistentCollection(
                    $this->em,
                    $this->em->getClassMetadata($assoc->targetEntity),
                    $value,
                );
                $value->setOwner($entity, $assoc);
                $value->setDirty(! $value->isEmpty());

                $refProp->setValue($entity, $value);

                $actualData[$name] = $value;

                continue;
            }

            if (( ! $class->isIdentifier($name) || ! $class->isIdGeneratorIdentity()) && ($name !== $class->versionField)) {
                if (! $isNew && isset($class->fieldMappings[$name]->notUpdatable)) {
                    continue;
                }

                $actualData[$name] = $value;
            }
        }

        if ($isNew) {
            // Entity is either NEW or MANAGED but not yet fully persisted (only has an id).
            // These result in an INSERT.
            $this->originalEntityData[$oid] = $actualData;
            $changeSet                      = [];

            foreach ($actualData as $propName => $actualValue) {
                if (! isset($class->associationMappings[$propName])) {
                    if (isset($class->fieldMappings[$propName]->notInsertable)) {
                        continue;
                    }

                    $changeSet[$propName] = [null, $actualValue];

                    continue;
                }

                $assoc = $class->associationMappings[$propName];

                if ($assoc->isToOneOwningSide()) {
                    $changeSet[$propName] = [null, $actualValue];
                }
            }

            $this->entityChangeSets[$oid] = $changeSet;
        } else {
            // Entity is "fully" MANAGED: it was already fully persisted before
            // and we have a copy of the original data
            $originalData  = $this->originalEntityData[$oid];
            $partialFields = $this->partialObjectLoadedFields[$oid] ?? null;
            $changeSet     = [];

            if (PHP_VERSION_ID >= 80400 && $partialFields !== null && ! $this->isUninitializedObject($entity)) {
                $this->reconcileStalePartialLoadTracking($oid, $class, $actualData, $originalData, $changeSet);
                $partialFields = null;
            }

            foreach ($actualData as $propName => $actualValue) {
                if ($partialFields !== null) {
                    if (! in_array($propName, $partialFields, true)) {
                        continue;
                    }
                } elseif (! (isset($originalData[$propName]) || array_key_exists($propName, $originalData))) {
                    // skip field, its a partially omitted one!
                    continue;
                }

                $orgValue = $originalData[$propName];

                if (! empty($class->fieldMappings[$propName]->enumType)) {
                    if (is_array($orgValue)) {
                        foreach ($orgValue as $id => $val) {
                            if ($val instanceof BackedEnum) {
                                $orgValue[$id] = $val->value;
                            }
                        }
                    } else {
                        if ($orgValue instanceof BackedEnum) {
                            $orgValue = $orgValue->value;
                        }
                    }
                }

                // skip if value haven't changed
                if ($orgValue === $actualValue) {
                    continue;
                }

                // if regular field
                if (! isset($class->associationMappings[$propName])) {
                    $changeSet[$propName] = [$orgValue, $actualValue];

                    continue;
                }

                $assoc = $class->associationMappings[$propName];

                // Persistent collection was exchanged with the "originally"
                // created one. This can only mean it was cloned and replaced
                // on another entity.
                if ($actualValue instanceof PersistentCollection) {
                    assert($assoc->isToMany());
                    $owner = $actualValue->getOwner();
                    if ($owner === null) { // cloned
                        $actualValue->setOwner($entity, $assoc);
                    } elseif ($owner !== $entity) { // no clone, we have to fix
                        if (! $actualValue->isInitialized()) {
                            $actualValue->initialize(); // we have to do this otherwise the cols share state
                        }

                        $newValue = clone $actualValue;
                        $newValue->setOwner($entity, $assoc);
                        $class->propertyAccessors[$propName]->setValue($entity, $newValue);
                    }
                }

                if ($orgValue instanceof PersistentCollection) {
                    // A PersistentCollection was de-referenced, so delete it.
                    $coid = spl_object_id($orgValue);

                    if (isset($this->collectionDeletions[$coid])) {
                        continue;
                    }

                    $this->collectionDeletions[$coid] = $orgValue;
                    $changeSet[$propName]             = $orgValue; // Signal changeset, to-many assocs will be ignored.

                    continue;
                }

                if ($assoc->isToOne()) {
                    if ($assoc->isOwningSide()) {
                        $changeSet[$propName] = [$orgValue, $actualValue];
                    }

                    if ($orgValue !== null && $assoc->orphanRemoval) {
                        assert(is_object($orgValue));
                        $this->scheduleOrphanRemoval($orgValue);
                    }
                }
            }

            if ($changeSet) {
                $this->entityChangeSets[$oid]   = $changeSet;
                $this->originalEntityData[$oid] = $actualData;
                $this->entityUpdates[$oid]      = $entity;
            }
        }

        // Look for changes in associations of the entity
        foreach ($class->associationMappings as $field => $assoc) {
            $val = $class->propertyAccessors[$field]->getValue($entity);
            if ($val === null) {
                continue;
            }

            $this->computeAssociationChanges($assoc, $val);

            if (
                ! isset($this->entityChangeSets[$oid]) &&
                $assoc->isManyToManyOwningSide() &&
                $val instanceof PersistentCollection &&
                $val->isDirty()
            ) {
                $this->entityChangeSets[$oid]   = [];
                $this->originalEntityData[$oid] = $actualData;
                $this->entityUpdates[$oid]      = $entity;
            }
        }
    }

    /**
     * A native lazy association proxy is registered with an empty data
     * snapshot (see registerManaged() callers below) until it is fully
     * hydrated through UnitOfWork::createEntity(). But PHP can complete
     * such an object's initialization without ever going through that
     * method — e.g. hydrating the owning side of a to-one association
     * assigns the inverse-side property directly onto the still-lazy
     * proxy, and if that happens to be the proxy's only remaining
     * property, PHP marks it fully initialized right there. When that
     * happens this entity is no longer "uninitialized" per
     * isUninitializedObject(), yet originalEntityData was never
     * populated, so every field would be silently and permanently
     * treated as "a partially omitted one" and skipped forever. We have
     * no reliable prior value for any of these fields, so force them
     * into this change set (mirroring how brand-new entities are
     * treated in computeChangeSet()) to make sure the entity's current
     * value reaches the database, then adopt it as the baseline for
     * future comparisons.
     *
     * @param ClassMetadata<object> $class
     * @param array<string, mixed>  $actualData
     * @param array<string, mixed>  $originalData
     * @param array<string, mixed>  $changeSet
     */
    private function reconcileStalePartialLoadTracking(
        int $oid,
        ClassMetadata $class,
        array $actualData,
        array &$originalData,
        array &$changeSet,
    ): void {
        foreach ($actualData as $propName => $actualValue) {
            if (
                ! isset($class->associationMappings[$propName])
                || $class->associationMappings[$propName]->isToOneOwningSide()
            ) {
                $changeSet[$propName] = [null, $actualValue];
            }

            $originalData[$propName] = $actualValue;
        }

        $this->originalEntityData[$oid] = $originalData;
    }

    /**
     * Computes all the changes that have been done to entities and collections
     * since the last commit and stores these changes in the _entityChangeSet map
     * temporarily for access by the persisters, until the UoW commit is finished.
     */
    public function computeChangeSets(): void
    {
        // Compute changes for INSERTed entities first. This must always happen.
        $this->computeScheduleInsertsChangeSets();

        // Compute changes for other MANAGED entities. Change tracking policies take effect here.
        foreach ($this->identityMap as $className => $entities) {
            $class = $this->em->getClassMetadata($className);

            // Skip class if instances are read-only
            if ($class->isReadOnly) {
                continue;
            }

            $entitiesToProcess = match (true) {
                $class->isChangeTrackingDeferredImplicit() => $entities,
                isset($this->scheduledForSynchronization[$className]) => $this->scheduledForSynchronization[$className],
                default => [],
            };

            foreach ($entitiesToProcess as $entity) {
                // Ignore uninitialized proxy objects
                if ($this->isUninitializedObject($entity)) {
                    continue;
                }

                // Only MANAGED entities that are NOT SCHEDULED FOR INSERTION OR DELETION are processed here.
                $oid = spl_object_id($entity);

                if (! isset($this->entityInsertions[$oid]) && ! isset($this->entityDeletions[$oid]) && isset($this->entityStates[$oid])) {
                    $this->computeChangeSet($class, $entity);
                }
            }
        }
    }

    /**
     * Computes the changes of an association.
     *
     * @param mixed $value The value of the association.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    private function computeAssociationChanges(AssociationMapping $assoc, mixed $value): void
    {
        if ($this->isUninitializedObject($value)) {
            return;
        }

        // If this collection is dirty, schedule it for updates
        if ($value instanceof PersistentCollection && $value->isDirty()) {
            $coid = spl_object_id($value);

            $this->collectionUpdates[$coid]  = $value;
            $this->visitedCollections[$coid] = $value;
        }

        // Look through the entities, and in any of their associations,
        // for transient (new) entities, recursively. ("Persistence by reachability")
        // Unwrap. Uninitialized collections will simply be empty.
        $unwrappedValue = $assoc->isToOne() ? [$value] : $value->unwrap();
        $targetClass    = $this->em->getClassMetadata($assoc->targetEntity);

        foreach ($unwrappedValue as $key => $entry) {
            if (! ($entry instanceof $targetClass->name)) {
                throw ORMInvalidArgumentException::invalidAssociation($targetClass, $assoc, $entry);
            }

            $state = $this->getEntityState($entry, self::STATE_NEW);

            if (! ($entry instanceof $assoc->targetEntity)) {
                throw UnexpectedAssociationValue::create(
                    $assoc->sourceEntity,
                    $assoc->fieldName,
                    get_debug_type($entry),
                    $assoc->targetEntity,
                );
            }

            switch ($state) {
                case self::STATE_NEW:
                    if (! $assoc->isCascadePersist()) {
                        /*
                         * For now just record the details, because this may
                         * not be an issue if we later discover another pathway
                         * through the object-graph where cascade-persistence
                         * is enabled for this object.
                         */
                        $this->nonCascadedNewDetectedEntities[spl_object_id($entry)] = [$assoc, $entry];

                        break;
                    }

                    $this->persistNew($targetClass, $entry);
                    $this->computeChangeSet($targetClass, $entry);

                    break;

                case self::STATE_REMOVED:
                    // Consume the $value as array (it's either an array or an ArrayAccess)
                    // and remove the element from Collection.
                    if (! $assoc->isToMany()) {
                        break;
                    }

                    $coid                            = spl_object_id($value);
                    $this->visitedCollections[$coid] = $value;

                    if (! isset($this->pendingCollectionElementRemovals[$coid])) {
                        $this->pendingCollectionElementRemovals[$coid] = [];
                    }

                    $this->pendingCollectionElementRemovals[$coid][$key] = true;
                    break;

                case self::STATE_DETACHED:
                    // Can actually not happen right now as we assume STATE_NEW,
                    // so the exception will be raised from the DBAL layer (constraint violation).
                    throw ORMInvalidArgumentException::detachedEntityFoundThroughRelationship($assoc, $entry);

                default:
                    // MANAGED associated entities are already taken into account
                    // during changeset calculation anyway, since they are in the identity map.
            }
        }
    }

    /**
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @template T of object
     */
    private function persistNew(ClassMetadata $class, object $entity): void
    {
        $oid    = spl_object_id($entity);
        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::prePersist);

        if ($invoke !== ListenersInvoker::INVOKE_NONE) {
            $this->listenersInvoker->invoke($class, Events::prePersist, $entity, new PrePersistEventArgs($entity, $this->em), $invoke);
        }

        $idGen = $class->idGenerator;

        if (! $idGen->isPostInsertGenerator()) {
            $idValue = $idGen->generateId($this->em, $entity);

            if (! $idGen instanceof AssignedGenerator) {
                $idValue = [$class->getSingleIdentifierFieldName() => $this->convertSingleFieldIdentifierToPHPValue($class, $idValue)];

                $class->setIdentifierValues($entity, $idValue);
            }

            // Some identifiers may be foreign keys to new entities.
            // In this case, we don't have the value yet and should treat it as if we have a post-insert generator
            if (! $this->hasMissingIdsWhichAreForeignKeys($class, $idValue)) {
                $this->entityIdentifiers[$oid] = $idValue;
            }
        }

        $this->entityStates[$oid] = self::STATE_MANAGED;

        if (! isset($this->entityInsertions[$oid])) {
            $this->scheduleForInsert($entity);
        }
    }

    /** @param mixed[] $idValue */
    private function hasMissingIdsWhichAreForeignKeys(ClassMetadata $class, array $idValue): bool
    {
        foreach ($idValue as $idField => $idFieldValue) {
            if ($idFieldValue === null && isset($class->associationMappings[$idField])) {
                return true;
            }
        }

        return false;
    }

    /**
     * INTERNAL:
     * Computes the changeset of an individual entity, independently of the
     * computeChangeSets() routine that is used at the beginning of a UnitOfWork#commit().
     *
     * The passed entity must be a managed entity. If the entity already has a change set
     * because this method is invoked during a commit cycle then the change sets are added.
     * whereby changes detected in this method prevail.
     *
     * @param ClassMetadata $class  The class descriptor of the entity.
     * @param object        $entity The entity for which to (re)calculate the change set.
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @throws ORMInvalidArgumentException If the passed entity is not MANAGED.
     *
     * @template T of object
     * @ignore
     */
    public function recomputeSingleEntityChangeSet(ClassMetadata $class, object $entity): void
    {
        $oid = spl_object_id($entity);

        if (! isset($this->entityStates[$oid]) || $this->entityStates[$oid] !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        if (! $class->isInheritanceTypeNone()) {
            $class = $this->em->getClassMetadata($entity::class);
        }

        $actualData = [];

        foreach ($class->propertyAccessors as $name => $refProp) {
            if (
                ( ! $class->isIdentifier($name) || ! $class->isIdGeneratorIdentity())
                && ($name !== $class->versionField)
                && ! $class->isCollectionValuedAssociation($name)
                && ! isset($class->fieldMappings[$name]->notUpdatable)
            ) {
                $actualData[$name] = $refProp->getValue($entity);
            }
        }

        if (! isset($this->originalEntityData[$oid])) {
            throw new RuntimeException('Cannot call recomputeSingleEntityChangeSet before computeChangeSet on an entity.');
        }

        $originalData = $this->originalEntityData[$oid];
        $changeSet    = [];

        foreach ($actualData as $propName => $actualValue) {
            $orgValue = $originalData[$propName] ?? null;

            if (isset($class->fieldMappings[$propName]->enumType)) {
                if (is_array($orgValue)) {
                    foreach ($orgValue as $id => $val) {
                        if ($val instanceof BackedEnum) {
                            $orgValue[$id] = $val->value;
                        }
                    }
                } else {
                    if ($orgValue instanceof BackedEnum) {
                        $orgValue = $orgValue->value;
                    }
                }
            }

            if ($orgValue !== $actualValue) {
                $changeSet[$propName] = [$orgValue, $actualValue];
            }
        }

        if ($changeSet) {
            if (isset($this->entityChangeSets[$oid])) {
                $this->entityChangeSets[$oid] = [...$this->entityChangeSets[$oid], ...$changeSet];
            } elseif (! isset($this->entityInsertions[$oid])) {
                $this->entityChangeSets[$oid] = $changeSet;
                $this->entityUpdates[$oid]    = $entity;
            }

            $this->originalEntityData[$oid] = $actualData;
        }
    }

    /**
     * Executes entity insertions
     */
    private function executeInserts(): void
    {
        $batchedByType    = InsertBatch::batchByEntityType($this->em, $this->computeInsertExecutionOrder());
        $eventsToDispatch = [];

        foreach ($batchedByType as $batch) {
            $class     = $batch->class;
            $invoke    = $this->listenersInvoker->getSubscribedSystems($class, Events::postPersist);
            $persister = $this->getEntityPersister($class->name);

            foreach ($batch->entities as $entity) {
                $oid = spl_object_id($entity);

                $persister->addInsert($entity);

                unset($this->entityInsertions[$oid]);
            }

            $persister->executeInserts();

            foreach ($batch->entities as $entity) {
                $oid = spl_object_id($entity);

                if (! isset($this->entityIdentifiers[$oid])) {
                    //entity was not added to identity map because some identifiers are foreign keys to new entities.
                    //add it now
                    $this->addToEntityIdentifiersAndEntityMap($class, $oid, $entity);
                }

                if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                    $eventsToDispatch[] = ['class' => $class, 'entity' => $entity, 'invoke' => $invoke];
                }
            }
        }

        // Defer dispatching `postPersist` events to until all entities have been inserted and post-insert
        // IDs have been assigned.
        foreach ($eventsToDispatch as $event) {
            $this->listenersInvoker->invoke(
                $event['class'],
                Events::postPersist,
                $event['entity'],
                new PostPersistEventArgs($event['entity'], $this->em),
                $event['invoke'],
            );
        }
    }

    /**
     * @phpstan-param ClassMetadata<T> $class
     * @phpstan-param T $entity
     *
     * @template T of object
     */
    private function addToEntityIdentifiersAndEntityMap(
        ClassMetadata $class,
        int $oid,
        object $entity,
    ): void {
        $identifier = [];

        foreach ($class->getIdentifierFieldNames() as $idField) {
            $origValue = $class->getFieldValue($entity, $idField);

            $value = null;
            if (isset($class->associationMappings[$idField])) {
                // NOTE: Single Columns as associated identifiers only allowed - this constraint it is enforced.
                $value = $this->getSingleIdentifierValue($origValue);
            }

            $identifier[$idField]                     = $value ?? $origValue;
            $this->originalEntityData[$oid][$idField] = $origValue;
        }

        $this->entityStates[$oid]      = self::STATE_MANAGED;
        $this->entityIdentifiers[$oid] = $identifier;

        $this->addToIdentityMap($entity);
    }

    /**
     * Executes all entity updates
     */
    private function executeUpdates(): void
    {
        $entities = $this->computeUpdateExecutionOrder();

        foreach ($entities as $oid => $entity) {
            $oid = spl_object_id($entity);

            $class            = $this->em->getClassMetadata($entity::class);
            $persister        = $this->getEntityPersister($class->name);
            $preUpdateInvoke  = $this->listenersInvoker->getSubscribedSystems($class, Events::preUpdate);
            $postUpdateInvoke = $this->listenersInvoker->getSubscribedSystems($class, Events::postUpdate);

            if ($preUpdateInvoke !== ListenersInvoker::INVOKE_NONE) {
                $this->listenersInvoker->invoke($class, Events::preUpdate, $entity, new PreUpdateEventArgs($entity, $this->em, $this->getEntityChangeSet($entity)), $preUpdateInvoke);

                $this->recomputeSingleEntityChangeSet($class, $entity);
            }

            if (! empty($this->entityChangeSets[$oid])) {
                $persister->update($entity);
            }

            unset($this->entityUpdates[$oid]);

            if ($postUpdateInvoke !== ListenersInvoker::INVOKE_NONE) {
                $this->listenersInvoker->invoke($class, Events::postUpdate, $entity, new PostUpdateEventArgs($entity, $this->em), $postUpdateInvoke);
            }
        }
    }

    /**
     * Executes all entity deletions
     */
    private function executeDeletions(): void
    {
        $entities         = $this->computeDeleteExecutionOrder();
        $eventsToDispatch = [];

        foreach ($entities as $entity) {
            $this->removeFromIdentityMap($entity);

            $oid       = spl_object_id($entity);
            $class     = $this->em->getClassMetadata($entity::class);
            $persister = $this->getEntityPersister($class->name);
            $invoke    = $this->listenersInvoker->getSubscribedSystems($class, Events::postRemove);

            $persister->delete($entity);

            unset(
                $this->entityDeletions[$oid],
                $this->entityIdentifiers[$oid],
                $this->originalEntityData[$oid],
                $this->entityStates[$oid],
            );

            // Entity with this $oid after deletion treated as NEW, even if the $oid
            // is obtained by a new entity because the old one went out of scope.
            //$this->entityStates[$oid] = self::STATE_NEW;
            if (
                ! $class->isIdentifierNatural() &&
                ! $class->propertyAccessors[$class->identifier[0]] instanceof ReadonlyAccessor
            ) {
                $class->propertyAccessors[$class->identifier[0]]->setValue($entity, null);
            }

            if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                $eventsToDispatch[] = ['class' => $class, 'entity' => $entity, 'invoke' => $invoke];
            }
        }

        // Defer dispatching `postRemove` events to until all entities have been removed.
        foreach ($eventsToDispatch as $event) {
            $this->listenersInvoker->invoke(
                $event['class'],
                Events::postRemove,
                $event['entity'],
                new PostRemoveEventArgs($event['entity'], $this->em),
                $event['invoke'],
            );
        }
    }

    /** @return list<object> */
    private function computeInsertExecutionOrder(): array
    {
        $sort = new TopologicalSort();

        // First make sure we have all the nodes
        foreach ($this->entityInsertions as $entity) {
            $sort->addNode($entity);
        }

        // Now add edges
        foreach ($this->entityInsertions as $entity) {
            $class = $this->em->getClassMetadata($entity::class);

            foreach ($class->associationMappings as $assoc) {
                // We only need to consider the owning sides of to-one associations,
                // since many-to-many associations are persisted at a later step and
                // have no insertion order problems (all entities already in the database
                // at that time).
                if (! $assoc->isToOneOwningSide()) {
                    continue;
                }

                $targetEntity = $class->getFieldValue($entity, $assoc->fieldName);

                // If there is no entity that we need to refer to, or it is already in the
                // database (i. e. does not have to be inserted), no need to consider it.
                if ($targetEntity === null || ! $sort->hasNode($targetEntity)) {
                    continue;
                }

                // An entity that references back to itself _and_ uses an application-provided ID
                // (the "NONE" generator strategy) can be exempted from commit order computation.
                // See https://github.com/doctrine/orm/pull/10735/ for more details on this edge case.
                // A non-NULLable self-reference would be a cycle in the graph.
                if ($targetEntity === $entity && $class->isIdentifierNatural()) {
                    continue;
                }

                // According to https://www.doctrine-project.org/projects/doctrine-orm/en/2.14/reference/annotations-reference.html#annref_joincolumn,
                // the default for "nullable" is true. Unfortunately, it seems this default is not applied at the metadata driver, factory or other
                // level, but in fact we may have an undefined 'nullable' key here, so we must assume that default here as well.
                //
                // Same in \Doctrine\ORM\Tools\EntityGenerator::isAssociationIsNullable or \Doctrine\ORM\Persisters\Entity\BasicEntityPersister::getJoinSQLForJoinColumns,
                // to give two examples.
                $joinColumns = reset($assoc->joinColumns);
                $isNullable  = ! isset($joinColumns->nullable) || $joinColumns->nullable;

                // Add dependency. The dependency direction implies that "$entity depends on $targetEntity". The
                // topological sort result will output the depended-upon nodes first, which means we can insert
                // entities in that order.
                $sort->addEdge($entity, $targetEntity, $isNullable);
            }
        }

        return $sort->sort();
    }

    /** @return list<object> */
    private function computeUpdateExecutionOrder(): array
    {
        $entities = $this->entityUpdates;

        usort($entities, function (object $a, object $b): int {
            // First, order alphabetically by the class name. If the class names are different, then return immediately.
            $result = strcmp($a::class, $b::class);
            if ($result !== 0) {
                return $result;
            }

            // Second, when the objects are of the same class, then order them alphabetically by the "id hash". This
            // covers multiple different cases of entity's id (think multicolumn ids or uuids). Note that ordering
            // numerical strings alphabetically may result in the following order: "1", "100", "2" but that's fine.
            // The point here is to have a consistent ordering, not that the (potential) numbers are in numeric order.
            return strcmp(
                $this->getIdHashByEntity($a),
                $this->getIdHashByEntity($b),
            );
        });

        return $entities;
    }

    /** @return list<object> */
    private function computeDeleteExecutionOrder(): array
    {
        $stronglyConnectedComponents = new StronglyConnectedComponents();
        $sort                        = new TopologicalSort();

        foreach ($this->entityDeletions as $entity) {
            $stronglyConnectedComponents->addNode($entity);
            $sort->addNode($entity);
        }

        // First, consider only "on delete cascade" associations between entities
        // and find strongly connected groups. Once we delete any one of the entities
        // in such a group, _all_ of the other entities will be removed as well. So,
        // we need to treat those groups like a single entity when performing delete
        // order topological sorting.
        foreach ($this->entityDeletions as $entity) {
            $class = $this->em->getClassMetadata($entity::class);

            foreach ($class->associationMappings as $assoc) {
                // We only need to consider the owning sides of to-one associations,
                // since many-to-many associations can always be (and have already been)
                // deleted in a preceding step.
                if (! $assoc->isToOneOwningSide()) {
                    continue;
                }

                $joinColumns = reset($assoc->joinColumns);
                if (! isset($joinColumns->onDelete)) {
                    continue;
                }

                $onDeleteOption = strtolower($joinColumns->onDelete);
                if ($onDeleteOption !== 'cascade') {
                    continue;
                }

                $targetEntity = $class->getFieldValue($entity, $assoc->fieldName);

                // If the association does not refer to another entity or that entity
                // is not to be deleted, there is no ordering problem and we can
                // skip this particular association.
                if ($targetEntity === null || ! $stronglyConnectedComponents->hasNode($targetEntity)) {
                    continue;
                }

                $stronglyConnectedComponents->addEdge($entity, $targetEntity);
            }
        }

        $stronglyConnectedComponents->findStronglyConnectedComponents();

        // Now do the actual topological sorting to find the delete order.
        foreach ($this->entityDeletions as $entity) {
            $class = $this->em->getClassMetadata($entity::class);

            // Get the entities representing the SCC
            $entityComponent = $stronglyConnectedComponents->getNodeRepresentingStronglyConnectedComponent($entity);

            // When $entity is part of a non-trivial strongly connected component group
            // (a group containing not only those entities alone), make sure we process it _after_ the
            // entity representing the group.
            // The dependency direction implies that "$entity depends on $entityComponent
            // being deleted first". The topological sort will output the depended-upon nodes first.
            if ($entityComponent !== $entity) {
                $sort->addEdge($entity, $entityComponent, false);
            }

            foreach ($class->associationMappings as $assoc) {
                // We only need to consider the owning sides of to-one associations,
                // since many-to-many associations can always be (and have already been)
                // deleted in a preceding step.
                if (! $assoc->isToOneOwningSide()) {
                    continue;
                }

                // For associations that implement a database-level set null operation,
                // we do not have to follow a particular order: If the referred-to entity is
                // deleted first, the DBMS will temporarily set the foreign key to NULL (SET NULL).
                // So, we can skip it in the computation.
                $joinColumns = reset($assoc->joinColumns);
                if (isset($joinColumns->onDelete)) {
                    $onDeleteOption = strtolower($joinColumns->onDelete);
                    if ($onDeleteOption === 'set null') {
                        continue;
                    }
                }

                $targetEntity = $class->getFieldValue($entity, $assoc->fieldName);

                // If the association does not refer to another entity or that entity
                // is not to be deleted, there is no ordering problem and we can
                // skip this particular association.
                if ($targetEntity === null || ! $sort->hasNode($targetEntity)) {
                    continue;
                }

                // Get the entities representing the SCC
                $targetEntityComponent = $stronglyConnectedComponents->getNodeRepresentingStronglyConnectedComponent($targetEntity);

                // When we have a dependency between two different groups of strongly connected nodes,
                // add it to the computation.
                // The dependency direction implies that "$targetEntityComponent depends on $entityComponent
                // being deleted first". The topological sort will output the depended-upon nodes first,
                // so we can work through the result in the returned order.
                if ($targetEntityComponent !== $entityComponent) {
                    $sort->addEdge($targetEntityComponent, $entityComponent, false);
                }
            }
        }

        return $sort->sort();
    }

    /**
     * Schedules an entity for insertion into the database.
     * If the entity already has an identifier, it will be added to the identity map.
     *
     * @throws ORMInvalidArgumentException
     * @throws InvalidArgumentException
     */
    public function scheduleForInsert(object $entity): void
    {
        $oid = spl_object_id($entity);

        if (isset($this->entityUpdates[$oid])) {
            throw new InvalidArgumentException('Dirty entity can not be scheduled for insertion.');
        }

        if (isset($this->entityDeletions[$oid])) {
            throw ORMInvalidArgumentException::scheduleInsertForRemovedEntity($entity);
        }

        if (isset($this->originalEntityData[$oid]) && ! isset($this->entityInsertions[$oid])) {
            throw ORMInvalidArgumentException::scheduleInsertForManagedEntity($entity);
        }

        if (isset($this->entityInsertions[$oid])) {
            throw ORMInvalidArgumentException::scheduleInsertTwice($entity);
        }

        $this->entityInsertions[$oid] = $entity;

        if (isset($this->entityIdentifiers[$oid])) {
            $this->addToIdentityMap($entity);
        }
    }

    /**
     * Checks whether an entity is scheduled for insertion.
     */
    public function isScheduledForInsert(object $entity): bool
    {
        return isset($this->entityInsertions[spl_object_id($entity)]);
    }

    /**
     * Schedules an entity for being updated.
     *
     * @throws ORMInvalidArgumentException
     */
    public function scheduleForUpdate(object $entity): void
    {
        $oid = spl_object_id($entity);

        if (! isset($this->entityIdentifiers[$oid])) {
            throw ORMInvalidArgumentException::entityHasNoIdentity($entity, 'scheduling for update');
        }

        if (isset($this->entityDeletions[$oid])) {
            throw ORMInvalidArgumentException::entityIsRemoved($entity, 'schedule for update');
        }

        if (! isset($this->entityUpdates[$oid]) && ! isset($this->entityInsertions[$oid])) {
            $this->entityUpdates[$oid] = $entity;
        }
    }

    /**
     * INTERNAL:
     * Schedules an extra update that will be executed immediately after the
     * regular entity updates within the currently running commit cycle.
     *
     * Extra updates for entities are stored as (entity, changeset) tuples.
     *
     * @phpstan-param array<string, array{mixed, mixed}>  $changeset The changeset of the entity (what to update).
     *
     * @ignore
     */
    public function scheduleExtraUpdate(object $entity, array $changeset): void
    {
        $oid         = spl_object_id($entity);
        $extraUpdate = [$entity, $changeset];

        if (isset($this->extraUpdates[$oid])) {
            [, $changeset2] = $this->extraUpdates[$oid];

            $extraUpdate = [$entity, $changeset + $changeset2];
        }

        $this->extraUpdates[$oid] = $extraUpdate;
    }

    /**
     * Checks whether an entity is registered as dirty in the unit of work.
     * Note: Is not very useful currently as dirty entities are only registered
     * at commit time.
     */
    public function isScheduledForUpdate(object $entity): bool
    {
        return isset($this->entityUpdates[spl_object_id($entity)]);
    }

    /**
     * Checks whether an entity is registered to be checked in the unit of work.
     */
    public function isScheduledForDirtyCheck(object $entity): bool
    {
        $rootEntityName = $this->em->getClassMetadata($entity::class)->rootEntityName;

        return isset($this->scheduledForSynchronization[$rootEntityName][spl_object_id($entity)]);
    }

    /**
     * INTERNAL:
     * Schedules an entity for deletion.
     */
    public function scheduleForDelete(object $entity): void
    {
        $oid = spl_object_id($entity);

        if (isset($this->entityInsertions[$oid])) {
            if ($this->isInIdentityMap($entity)) {
                $this->removeFromIdentityMap($entity);
            }

            unset($this->entityInsertions[$oid], $this->entityStates[$oid]);

            return; // entity has not been persisted yet, so nothing more to do.
        }

        if (! $this->isInIdentityMap($entity)) {
            return;
        }

        unset($this->entityUpdates[$oid]);

        if (! isset($this->entityDeletions[$oid])) {
            $this->entityDeletions[$oid] = $entity;
            $this->entityStates[$oid]    = self::STATE_REMOVED;
        }
    }

    /**
     * Checks whether an entity is registered as removed/deleted with the unit
     * of work.
     */
    public function isScheduledForDelete(object $entity): bool
    {
        return isset($this->entityDeletions[spl_object_id($entity)]);
    }

    /**
     * Checks whether an entity is scheduled for insertion, update or deletion.
     */
    public function isEntityScheduled(object $entity): bool
    {
        $oid = spl_object_id($entity);

        return isset($this->entityInsertions[$oid])
            || isset($this->entityUpdates[$oid])
            || isset($this->entityDeletions[$oid]);
    }

    /**
     * INTERNAL:
     * Registers an entity in the identity map.
     * Note that entities in a hierarchy are registered with the class name of
     * the root entity.
     *
     * @return bool TRUE if the registration was successful, FALSE if the identity of
     * the entity in question is already managed.
     *
     * @throws ORMInvalidArgumentException
     * @throws EntityIdentityCollisionException
     *
     * @ignore
     */
    public function addToIdentityMap(object $entity): bool
    {
        $classMetadata = $this->em->getClassMetadata($entity::class);
        $idHash        = $this->getIdHashByEntity($entity);
        $className     = $classMetadata->rootEntityName;

        if (isset($this->identityMap[$className][$idHash])) {
            if ($this->identityMap[$className][$idHash] !== $entity) {
                throw EntityIdentityCollisionException::create($this->identityMap[$className][$idHash], $entity, $idHash);
            }

            return false;
        }

        $this->identityMap[$className][$idHash] = $entity;

        return true;
    }

    /**
     * Gets the id hash of an entity by its identifier.
     *
     * @param array<string|int, mixed> $identifier The identifier of an entity
     *
     * @return string The entity id hash.
     */
    final public static function getIdHashByIdentifier(array $identifier): string
    {
        foreach ($identifier as $k => $value) {
            if ($value instanceof BackedEnum) {
                $identifier[$k] = $value->value;
            }
        }

        return implode(
            ' ',
            $identifier,
        );
    }

    /**
     * Gets the id hash of an entity.
     *
     * @param object $entity The entity managed by Unit Of Work
     *
     * @return string The entity id hash.
     */
    public function getIdHashByEntity(object $entity): string
    {
        $identifier = $this->entityIdentifiers[spl_object_id($entity)];

        if (empty($identifier) || in_array(null, $identifier, true)) {
            $classMetadata = $this->em->getClassMetadata($entity::class);

            throw ORMInvalidArgumentException::entityWithoutIdentity($classMetadata->name, $entity);
        }

        return self::getIdHashByIdentifier($identifier);
    }

    /**
     * Gets the state of an entity with regard to the current unit of work.
     *
     * @param int|null $assume The state to assume if the state is not yet known (not MANAGED or REMOVED).
     *                         This parameter can be set to improve performance of entity state detection
     *                         by potentially avoiding a database lookup if the distinction between NEW and DETACHED
     *                         is either known or does not matter for the caller of the method.
     * @phpstan-param self::STATE_*|null $assume
     *
     * @phpstan-return self::STATE_*
     */
    public function getEntityState(object $entity, int|null $assume = null): int
    {
        $oid = spl_object_id($entity);

        if (isset($this->entityStates[$oid])) {
            return $this->entityStates[$oid];
        }

        if ($assume !== null) {
            return $assume;
        }

        // State can only be NEW or DETACHED, because MANAGED/REMOVED states are known.
        // Note that you can not remember the NEW or DETACHED state in _entityStates since
        // the UoW does not hold references to such objects and the object hash can be reused.
        // More generally because the state may "change" between NEW/DETACHED without the UoW being aware of it.
        $class = $this->em->getClassMetadata($entity::class);
        $id    = $class->getIdentifierValues($entity);

        if (! $id) {
            return self::STATE_NEW;
        }

        if ($class->containsForeignIdentifier || $class->containsEnumIdentifier) {
            $id = $this->identifierFlattener->flattenIdentifier($class, $id);
        }

        switch (true) {
            case $class->isIdentifierNatural():
                // Check for a version field, if available, to avoid a db lookup.
                if ($class->isVersioned) {
                    assert($class->versionField !== null);

                    return $class->getFieldValue($entity, $class->versionField)
                        ? self::STATE_DETACHED
                        : self::STATE_NEW;
                }

                // Last try before db lookup: check the identity map.
                if ($this->tryGetById($id, $class->rootEntityName)) {
                    return self::STATE_DETACHED;
                }

                // db lookup
                if ($this->getEntityPersister($class->name)->exists($entity)) {
                    return self::STATE_DETACHED;
                }

                return self::STATE_NEW;

            case ! $class->idGenerator->isPostInsertGenerator():
                // if we have a pre insert generator we can't be sure that having an id
                // really means that the entity exists. We have to verify this through
                // the last resort: a db lookup

                // Last try before db lookup: check the identity map.
                if ($this->tryGetById($id, $class->rootEntityName)) {
                    return self::STATE_DETACHED;
                }

                // db lookup
                if ($this->getEntityPersister($class->name)->exists($entity)) {
                    return self::STATE_DETACHED;
                }

                return self::STATE_NEW;

            default:
                return self::STATE_DETACHED;
        }
    }

    /**
     * INTERNAL:
     * Removes an entity from the identity map. This effectively detaches the
     * entity from the persistence management of Doctrine.
     *
     * @throws ORMInvalidArgumentException
     *
     * @ignore
     */
    public function removeFromIdentityMap(object $entity): bool
    {
        $oid           = spl_object_id($entity);
        $classMetadata = $this->em->getClassMetadata($entity::class);
        $idHash        = self::getIdHashByIdentifier($this->entityIdentifiers[$oid]);

        if ($idHash === '') {
            throw ORMInvalidArgumentException::entityHasNoIdentity($entity, 'remove from identity map');
        }

        $className = $classMetadata->rootEntityName;

        if (isset($this->identityMap[$className][$idHash])) {
            unset($this->identityMap[$className][$idHash], $this->readOnlyObjects[$oid]);

            //$this->entityStates[$oid] = self::STATE_DETACHED;

            return true;
        }

        return false;
    }

    /**
     * INTERNAL:
     * Gets an entity in the identity map by its identifier hash.
     *
     * @ignore
     */
    public function getByIdHash(string $idHash, string $rootClassName): object|null
    {
        return $this->identityMap[$rootClassName][$idHash];
    }

    /**
     * INTERNAL:
     * Tries to get an entity by its identifier hash. If no entity is found for
     * the given hash, FALSE is returned.
     *
     * @param mixed $idHash (must be possible to cast it to string)
     *
     * @return false|object The found entity or FALSE.
     *
     * @ignore
     */
    public function tryGetByIdHash(mixed $idHash, string $rootClassName): object|false
    {
        $stringIdHash = (string) $idHash;

        return $this->identityMap[$rootClassName][$stringIdHash] ?? false;
    }

    /**
     * Checks whether an entity is registered in the identity map of this UnitOfWork.
     */
    public function isInIdentityMap(object $entity): bool
    {
        $oid = spl_object_id($entity);

        if (empty($this->entityIdentifiers[$oid])) {
            return false;
        }

        $classMetadata = $this->em->getClassMetadata($entity::class);
        $idHash        = self::getIdHashByIdentifier($this->entityIdentifiers[$oid]);

        return isset($this->identityMap[$classMetadata->rootEntityName][$idHash]);
    }

    /**
     * Persists an entity as part of the current unit of work.
     */
    public function persist(object $entity): void
    {
        $visited = [];

        $this->doPersist($entity, $visited);
    }

    /**
     * Persists an entity as part of the current unit of work.
     *
     * This method is internally called during persist() cascades as it tracks
     * the already visited entities to prevent infinite recursions.
     *
     * @phpstan-param array<int, object> $visited The already visited entities.
     *
     * @throws ORMInvalidArgumentException
     * @throws UnexpectedValueException
     */
    private function doPersist(object $entity, array &$visited): void
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // Mark visited

        $class = $this->em->getClassMetadata($entity::class);

        // We assume NEW, so DETACHED entities result in an exception on flush (constraint violation).
        // If we would detect DETACHED here we would throw an exception anyway with the same
        // consequences (not recoverable/programming error), so just assuming NEW here
        // lets us avoid some database lookups for entities with natural identifiers.
        $entityState = $this->getEntityState($entity, self::STATE_NEW);

        switch ($entityState) {
            case self::STATE_MANAGED:
                // Nothing to do, except if policy is "deferred explicit"
                if ($class->isChangeTrackingDeferredExplicit()) {
                    $this->scheduleForDirtyCheck($entity);
                }

                break;

            case self::STATE_NEW:
                $this->persistNew($class, $entity);
                break;

            case self::STATE_REMOVED:
                // Entity becomes managed again
                unset($this->entityDeletions[$oid]);
                $this->addToIdentityMap($entity);

                $this->entityStates[$oid] = self::STATE_MANAGED;

                if ($class->isChangeTrackingDeferredExplicit()) {
                    $this->scheduleForDirtyCheck($entity);
                }

                break;

            case self::STATE_DETACHED:
                // Can actually not happen right now since we assume STATE_NEW.
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'persisted');

            default:
                throw new UnexpectedValueException(sprintf(
                    'Unexpected entity state: %s. %s',
                    $entityState,
                    self::objToStr($entity),
                ));
        }

        $this->cascadePersist($entity, $visited);
    }

    /**
     * Deletes an entity as part of the current unit of work.
     */
    public function remove(object $entity): void
    {
        $visited = [];

        $this->doRemove($entity, $visited);
    }

    /**
     * Deletes an entity as part of the current unit of work.
     *
     * This method is internally called during delete() cascades as it tracks
     * the already visited entities to prevent infinite recursions.
     *
     * @phpstan-param array<int, object> $visited The map of the already visited entities.
     *
     * @throws ORMInvalidArgumentException If the instance is a detached entity.
     * @throws UnexpectedValueException
     */
    private function doRemove(object $entity, array &$visited): void
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // mark visited

        // Cascade first, because scheduleForDelete() removes the entity from the identity map, which
        // can cause problems when a lazy proxy has to be initialized for the cascade operation.
        $this->cascadeRemove($entity, $visited);

        $class       = $this->em->getClassMetadata($entity::class);
        $entityState = $this->getEntityState($entity);

        switch ($entityState) {
            case self::STATE_NEW:
            case self::STATE_REMOVED:
                // nothing to do
                break;

            case self::STATE_MANAGED:
                $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::preRemove);

                if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                    $this->listenersInvoker->invoke($class, Events::preRemove, $entity, new PreRemoveEventArgs($entity, $this->em), $invoke);
                }

                $this->scheduleForDelete($entity);
                break;

            case self::STATE_DETACHED:
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'removed');

            default:
                throw new UnexpectedValueException(sprintf(
                    'Unexpected entity state: %s. %s',
                    $entityState,
                    self::objToStr($entity),
                ));
        }
    }

    /**
     * Detaches an entity from the persistence management. It's persistence will
     * no longer be managed by Doctrine.
     */
    public function detach(object $entity): void
    {
        $visited = [];

        $this->doDetach($entity, $visited);
    }

    /**
     * Executes a detach operation on the given entity.
     *
     * @param mixed[] $visited
     * @param bool    $noCascade if true, don't cascade detach operation.
     */
    private function doDetach(
        object $entity,
        array &$visited,
        bool $noCascade = false,
    ): void {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // mark visited

        switch ($this->getEntityState($entity, self::STATE_DETACHED)) {
            case self::STATE_MANAGED:
                if ($this->isInIdentityMap($entity)) {
                    $this->removeFromIdentityMap($entity);
                }

                unset(
                    $this->entityInsertions[$oid],
                    $this->entityUpdates[$oid],
                    $this->entityDeletions[$oid],
                    $this->entityIdentifiers[$oid],
                    $this->entityStates[$oid],
                    $this->originalEntityData[$oid],
                );
                break;
            case self::STATE_NEW:
            case self::STATE_DETACHED:
                return;
        }

        if (! $noCascade) {
            $this->cascadeDetach($entity, $visited);
        }
    }

    /**
     * Refreshes the state of the given entity from the database, overwriting
     * any local, unpersisted changes.
     *
     * @phpstan-param LockMode::*|null $lockMode
     *
     * @throws InvalidArgumentException If the entity is not MANAGED.
     * @throws TransactionRequiredException
     */
    public function refresh(object $entity, LockMode|int|null $lockMode = null): void
    {
        $visited = [];

        $this->doRefresh($entity, $visited, $lockMode);
    }

    /**
     * Executes a refresh operation on an entity.
     *
     * @phpstan-param array<int, object>  $visited The already visited entities during cascades.
     * @phpstan-param LockMode::*|null $lockMode
     *
     * @throws ORMInvalidArgumentException If the entity is not MANAGED.
     * @throws TransactionRequiredException
     */
    private function doRefresh(object $entity, array &$visited, LockMode|int|null $lockMode = null): void
    {
        switch (true) {
            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if (! $this->em->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }
        }

        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // mark visited

        $class = $this->em->getClassMetadata($entity::class);

        if ($this->getEntityState($entity) !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        $this->cascadeRefresh($entity, $visited, $lockMode);

        $this->getEntityPersister($class->name)->refresh(
            array_combine($class->getIdentifierFieldNames(), $this->entityIdentifiers[$oid]),
            $entity,
            $lockMode,
        );
    }

    /**
     * Cascades a refresh operation to associated entities.
     *
     * @phpstan-param array<int, object> $visited
     * @phpstan-param LockMode::*|null $lockMode
     */
    private function cascadeRefresh(object $entity, array &$visited, LockMode|int|null $lockMode = null): void
    {
        $class = $this->em->getClassMetadata($entity::class);

        $associationMappings = array_filter(
            $class->associationMappings,
            static fn (AssociationMapping $assoc): bool => $assoc->isCascadeRefresh(),
        );

        foreach ($associationMappings as $assoc) {
            $relatedEntities = $class->propertyAccessors[$assoc->fieldName]->getValue($entity);

            switch (true) {
                case $relatedEntities instanceof PersistentCollection:
                    // Unwrap so that foreach() does not initialize
                    $relatedEntities = $relatedEntities->unwrap();
                    // break; is commented intentionally!

                case $relatedEntities instanceof Collection:
                case is_array($relatedEntities):
                    foreach ($relatedEntities as $relatedEntity) {
                        $this->doRefresh($relatedEntity, $visited, $lockMode);
                    }

                    break;

                case $relatedEntities !== null:
                    $this->doRefresh($relatedEntities, $visited, $lockMode);
                    break;

                default:
                    // Do nothing
            }
        }
    }

    /**
     * Cascades a detach operation to associated entities.
     *
     * @param array<int, object> $visited
     */
    private function cascadeDetach(object $entity, array &$visited): void
    {
        $class = $this->em->getClassMetadata($entity::class);

        $associationMappings = array_filter(
            $class->associationMappings,
            static fn (AssociationMapping $assoc): bool => $assoc->isCascadeDetach(),
        );

        foreach ($associationMappings as $assoc) {
            $relatedEntities = $class->propertyAccessors[$assoc->fieldName]->getValue($entity);

            switch (true) {
                case $relatedEntities instanceof PersistentCollection:
                    // Unwrap so that foreach() does not initialize
                    $relatedEntities = $relatedEntities->unwrap();
                    // break; is commented intentionally!

                case $relatedEntities instanceof Collection:
                case is_array($relatedEntities):
                    foreach ($relatedEntities as $relatedEntity) {
                        $this->doDetach($relatedEntity, $visited);
                    }

                    break;

                case $relatedEntities !== null:
                    $this->doDetach($relatedEntities, $visited);
                    break;

                default:
                    // Do nothing
            }
        }
    }

    /**
     * Cascades the save operation to associated entities.
     *
     * @phpstan-param array<int, object> $visited
     */
    private function cascadePersist(object $entity, array &$visited): void
    {
        if ($this->isUninitializedObject($entity)) {
            // nothing to do - proxy is not initialized, therefore we don't do anything with it
            return;
        }

        $class = $this->em->getClassMetadata($entity::class);

        $associationMappings = array_filter(
            $class->associationMappings,
            static fn (AssociationMapping $assoc): bool => $assoc->isCascadePersist(),
        );

        foreach ($associationMappings as $assoc) {
            $relatedEntities = $class->propertyAccessors[$assoc->fieldName]->getValue($entity);

            switch (true) {
                case $relatedEntities instanceof PersistentCollection:
                    // Unwrap so that foreach() does not initialize
                    $relatedEntities = $relatedEntities->unwrap();
                    // break; is commented intentionally!

                case $relatedEntities instanceof Collection:
                case is_array($relatedEntities):
                    if ($assoc->isToMany() <= 0) {
                        throw ORMInvalidArgumentException::invalidAssociation(
                            $this->em->getClassMetadata($assoc->targetEntity),
                            $assoc,
                            $relatedEntities,
                        );
                    }

                    foreach ($relatedEntities as $relatedEntity) {
                        $this->doPersist($relatedEntity, $visited);
                    }

                    break;

                case $relatedEntities !== null:
                    if (! $relatedEntities instanceof $assoc->targetEntity) {
                        throw ORMInvalidArgumentException::invalidAssociation(
                            $this->em->getClassMetadata($assoc->targetEntity),
                            $assoc,
                            $relatedEntities,
                        );
                    }

                    $this->doPersist($relatedEntities, $visited);
                    break;

                default:
                    // Do nothing
            }
        }
    }

    /**
     * Cascades the delete operation to associated entities.
     *
     * @phpstan-param array<int, object> $visited
     */
    private function cascadeRemove(object $entity, array &$visited): void
    {
        $class = $this->em->getClassMetadata($entity::class);

        $associationMappings = array_filter(
            $class->associationMappings,
            static fn (AssociationMapping $assoc): bool => $assoc->isCascadeRemove(),
        );

        if ($associationMappings) {
            $this->initializeObject($entity);
        }

        $entitiesToCascade = [];

        foreach ($associationMappings as $assoc) {
            $relatedEntities = $class->propertyAccessors[$assoc->fieldName]->getValue($entity);

            switch (true) {
                case $relatedEntities instanceof Collection:
                case is_array($relatedEntities):
                    // If its a PersistentCollection initialization is intended! No unwrap!
                    foreach ($relatedEntities as $relatedEntity) {
                        $entitiesToCascade[] = $relatedEntity;
                    }

                    break;

                case $relatedEntities !== null:
                    $entitiesToCascade[] = $relatedEntities;
                    break;

                default:
                    // Do nothing
            }
        }

        foreach ($entitiesToCascade as $relatedEntity) {
            $this->doRemove($relatedEntity, $visited);
        }
    }

    /**
     * Acquire a lock on the given entity.
     *
     * @phpstan-param LockMode::* $lockMode
     *
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws OptimisticLockException
     */
    public function lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void
    {
        if ($this->getEntityState($entity, self::STATE_DETACHED) !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        $class = $this->em->getClassMetadata($entity::class);

        switch (true) {
            case $lockMode === LockMode::OPTIMISTIC:
                if (! $class->isVersioned) {
                    throw OptimisticLockException::notVersioned($class->name);
                }

                if ($lockVersion === null) {
                    return;
                }

                $this->initializeObject($entity);

                assert($class->versionField !== null);
                $entityVersion = $class->propertyAccessors[$class->versionField]->getValue($entity);

                // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators.DisallowedNotEqualOperator
                if ($entityVersion != $lockVersion) {
                    throw OptimisticLockException::lockFailedVersionMismatch($entity, $lockVersion, $entityVersion);
                }

                break;

            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if (! $this->em->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }

                $oid = spl_object_id($entity);

                $this->getEntityPersister($class->name)->lock(
                    array_combine($class->getIdentifierFieldNames(), $this->entityIdentifiers[$oid]),
                    $lockMode,
                );
                break;

            default:
                // Do nothing
        }
    }

    /**
     * Clears the UnitOfWork.
     */
    public function clear(): void
    {
        $this->identityMap                      =
        $this->entityIdentifiers                =
        $this->originalEntityData               =
        $this->entityChangeSets                 =
        $this->entityStates                     =
        $this->scheduledForSynchronization      =
        $this->entityInsertions                 =
        $this->entityUpdates                    =
        $this->entityDeletions                  =
        $this->nonCascadedNewDetectedEntities   =
        $this->collectionDeletions              =
        $this->collectionUpdates                =
        $this->extraUpdates                     =
        $this->readOnlyObjects                  =
        $this->partialObjectLoadedFields        =
        $this->pendingCollectionElementRemovals =
        $this->visitedCollections               =
        $this->eagerLoadingEntities             =
        $this->eagerLoadingCollections          =
        $this->orphanRemovals                   = [];

        $this->eventDispatcher->dispatchEvent(Events::onClear, new OnClearEventArgs($this->em));
    }

    /**
     * INTERNAL:
     * Schedules an orphaned entity for removal. The remove() operation will be
     * invoked on that entity at the beginning of the next commit of this
     * UnitOfWork.
     *
     * @ignore
     */
    public function scheduleOrphanRemoval(object $entity): void
    {
        $this->orphanRemovals[spl_object_id($entity)] = $entity;
    }

    /**
     * INTERNAL:
     * Cancels a previously scheduled orphan removal.
     *
     * @ignore
     */
    public function cancelOrphanRemoval(object $entity): void
    {
        unset($this->orphanRemovals[spl_object_id($entity)]);
    }

    /**
     * INTERNAL:
     * Schedules a complete collection for removal when this UnitOfWork commits.
     */
    public function scheduleCollectionDeletion(PersistentCollection $coll): void
    {
        $coid = spl_object_id($coll);

        // TODO: if $coll is already scheduled for recreation ... what to do?
        // Just remove $coll from the scheduled recreations?
        unset($this->collectionUpdates[$coid]);

        $this->collectionDeletions[$coid] = $coll;
    }

    public function isCollectionScheduledForDeletion(PersistentCollection $coll): bool
    {
        return isset($this->collectionDeletions[spl_object_id($coll)]);
    }

    /**
     * INTERNAL:
     * Creates an entity. Used for reconstitution of persistent entities.
     *
     * Internal note: Highly performance-sensitive method.
     *
     * @param class-string         $className The name of the entity class.
     * @param mixed[]              $data      The data for the entity.
     * @param array<string, mixed> $hints     Any hints to account for during reconstitution/lookup of the entity.
     *
     * @return object The managed entity instance.
     *
     * @ignore
     * @todo Rename: getOrCreateEntity
     */
    public function createEntity(string $className, array $data, array &$hints = []): object
    {
        $class = $this->em->getClassMetadata($className);

        $id     = $this->identifierFlattener->flattenIdentifier($class, $data);
        $idHash = self::getIdHashByIdentifier($id);

        // Holds already-loaded field data when a partial proxy is being fully initialized.
        // Used to avoid overwriting user-modified fields and to preserve the partial snapshot
        // in originalEntityData for correct changeset computation.
        $existingData = [];

        if (isset($this->identityMap[$class->rootEntityName][$idHash])) {
            $entity = $this->identityMap[$class->rootEntityName][$idHash];
            $oid    = spl_object_id($entity);

            if (
                isset($hints[Query::HINT_REFRESH], $hints[Query::HINT_REFRESH_ENTITY])
            ) {
                $unmanagedProxy = $hints[Query::HINT_REFRESH_ENTITY];
                if (
                    $unmanagedProxy !== $entity
                    && $this->isIdentifierEquals($unmanagedProxy, $entity)
                ) {
                    // We will hydrate the given un-managed proxy anyway:
                    // continue work, but consider it the entity from now on
                    $entity = $unmanagedProxy;
                }
            }

            // When a partial proxy (native lazy ghost with only some fields loaded) is being
            // fully initialized by the lazy ghost initializer, HINT_REFRESH_ENTITY is set to
            // the proxy itself. Capture the already-loaded field snapshot before overwriting
            // so that (a) user modifications to partial fields are preserved and (b) the
            // changeset snapshot reflects the original DB values from the partial query.
            // Note: $em->refresh() does NOT set HINT_REFRESH_ENTITY, so explicit refreshes
            // skip this and always do a full overwrite regardless of partial-object status.
            if (
                isset($this->partialObjectLoadedFields[$oid], $hints[Query::HINT_REFRESH_ENTITY])
                && $hints[Query::HINT_REFRESH_ENTITY] === $entity
            ) {
                // Restrict $existingData to only the scalar fields loaded in the original
                // partial query. Other entries in originalEntityData (e.g. association
                // snapshots added during hydration) must not block the full load from
                // initialising those fields on the ghost.
                $partialFields = $this->partialObjectLoadedFields[$oid];
                $existingData  = array_intersect_key(
                    $this->originalEntityData[$oid] ?? [],
                    array_flip($partialFields),
                );
            }

            if ($this->isUninitializedObject($entity)) {
                if ($this->em->getConfiguration()->isNativeLazyObjectsEnabled()) {
                    $class->reflClass->markLazyObjectAsInitialized($entity);
                } else {
                    $entity->__setInitialized(true);

                    if (extension_loaded('deepclone')) {
                        deepclone_hydrate($entity, (array) $class->reflClass->newInstanceWithoutConstructor());
                    } else {
                        /** @phpstan-ignore staticMethod.deprecatedClass */
                        Hydrator::hydrate($entity, (array) $class->reflClass->newInstanceWithoutConstructor());
                    }
                }
            } elseif (
                    ! isset($hints[Query::HINT_REFRESH])
                    || (isset($hints[Query::HINT_REFRESH_ENTITY]) && $hints[Query::HINT_REFRESH_ENTITY] !== $entity)
            ) {
                return $entity;
            }

            // Merge rather than replace when initializing a partial proxy: existing partial-load
            // snapshot values take priority so the changeset can detect changes made before lazy
            // init fired. Fields not yet loaded (not in $existingData) are added from $data.
            // For regular proxies and explicit refreshes $existingData is empty, so this is
            // equivalent to a plain assignment.
            $this->originalEntityData[$oid] = $existingData + $data;

            // Whichever way we got here (lazy ghost initializer, find(), or an explicit
            // refresh()), $data is always a complete row: the early return above is the
            // only path left for a caller supplying anything less. The entity can no
            // longer be considered partially loaded.
            unset($this->partialObjectLoadedFields[$oid]);
        } else {
            $allowsPartialLazyObject = $this->em->getConfiguration()->isNativeLazyObjectsEnabled()
                && isset($hints['isPartial']) && $hints['isPartial'];
            if ($allowsPartialLazyObject) {
                $entity = $this->em->getProxyFactory()->getProxy($class->name, $id, false);

                // For each embeddable create a lazy ghost whose initializer
                // loads the parent entity, so that only accessing an unloaded
                // embedded field triggers a SELECT rather than loading eagerly.
                // embeddedClasses is ordered: top-level entries appear before
                // nested ones (which carry a declaredField), so the loop can
                // safely reference already-created parent ghosts.
                $embeddableGhosts = [];
                foreach ($class->embeddedClasses as $property => $embeddableMapping) {
                    $embeddableGhost             = $this->em->getProxyFactory()->getEmbeddableProxy(
                        $embeddableMapping->class,
                        $entity,
                        $id,
                    );
                    $embeddableGhosts[$property] = $embeddableGhost;

                    if ($embeddableMapping->declaredField !== null) {
                        // Nested embeddable: wire it onto its parent embeddable ghost.
                        PropertyAccessorFactory::createPropertyAccessor(
                            $class->embeddedClasses[$embeddableMapping->declaredField]->class,
                            $embeddableMapping->originalField,
                        )->setValue($embeddableGhosts[$embeddableMapping->declaredField], $embeddableGhost);
                    } else {
                        // Top-level embeddable: wire it directly onto the entity ghost.
                        $class->propertyAccessors[$property]->setValue($entity, $embeddableGhost);
                    }
                }
            } else {
                $entity = $class->newInstance();
            }

            $oid = spl_object_id($entity);
            $this->registerManaged($entity, $id, $data);

            if (isset($hints[Query::HINT_READ_ONLY]) && $hints[Query::HINT_READ_ONLY] === true) {
                $this->readOnlyObjects[$oid] = true;
            }

            if ($allowsPartialLazyObject) {
                $this->markAsPartiallyLoaded($entity, array_keys(
                    array_intersect_key($data, $class->fieldMappings),
                ));
            }
        }

        foreach ($data as $field => $value) {
            if (isset($class->fieldMappings[$field]) && ! array_key_exists($field, $existingData)) {
                $class->propertyAccessors[$field]->setValue($entity, $value);
            }
        }

        // Loading the entity right here, if its in the eager loading map get rid of it there.
        unset($this->eagerLoadingEntities[$class->rootEntityName][$idHash]);

        if (isset($this->eagerLoadingEntities[$class->rootEntityName]) && ! $this->eagerLoadingEntities[$class->rootEntityName]) {
            unset($this->eagerLoadingEntities[$class->rootEntityName]);
        }

        foreach ($class->associationMappings as $field => $assoc) {
            // Check if the association is not among the fetch-joined associations already.
            if (isset($hints['fetchAlias'], $hints['fetched'][$hints['fetchAlias']][$field])) {
                continue;
            }

            if (! isset($hints['fetchMode'][$class->name][$field])) {
                $hints['fetchMode'][$class->name][$field] = $assoc->fetch;
            }

            $targetClass = $this->em->getClassMetadata($assoc->targetEntity);

            switch (true) {
                case $assoc->isToOne():
                    if (! $assoc->isOwningSide()) {
                        // use the given entity association
                        if (isset($data[$field]) && is_object($data[$field]) && isset($this->entityStates[spl_object_id($data[$field])])) {
                            $this->originalEntityData[$oid][$field] = $data[$field];

                            $class->propertyAccessors[$field]->setValue($entity, $data[$field]);
                            $targetClass->propertyAccessors[$assoc->mappedBy]->setValue($data[$field], $entity);

                            continue 2;
                        }

                        // Inverse side of x-to-one can never be lazy
                        $class->propertyAccessors[$field]->setValue($entity, $this->getEntityPersister($assoc->targetEntity)->loadOneToOneEntity($assoc, $entity));

                        continue 2;
                    }

                    // use the entity association
                    if (isset($data[$field]) && is_object($data[$field]) && isset($this->entityStates[spl_object_id($data[$field])])) {
                        $class->propertyAccessors[$field]->setValue($entity, $data[$field]);
                        $this->originalEntityData[$oid][$field] = $data[$field];

                        break;
                    }

                    $associatedId = [];

                    assert($assoc->isToOneOwningSide());
                    // TODO: Is this even computed right in all cases of composite keys?
                    foreach ($assoc->targetToSourceKeyColumns as $targetColumn => $srcColumn) {
                        $joinColumnValue = $data[$srcColumn] ?? null;

                        if ($joinColumnValue !== null) {
                            if ($joinColumnValue instanceof BackedEnum) {
                                $joinColumnValue = $joinColumnValue->value;
                            }

                            if ($targetClass->containsForeignIdentifier) {
                                $associatedId[$targetClass->getFieldForColumn($targetColumn)] = $joinColumnValue;
                            } else {
                                $associatedId[$targetClass->fieldNames[$targetColumn]] = $joinColumnValue;
                            }
                        } elseif (in_array($targetClass->getFieldForColumn($targetColumn), $targetClass->identifier, true)) {
                            // the missing key is part of target's entity primary key
                            $associatedId = [];
                            break;
                        }
                    }

                    if (! $associatedId) {
                        // Foreign key is NULL
                        $class->propertyAccessors[$field]->setValue($entity, null);
                        $this->originalEntityData[$oid][$field] = null;

                        break;
                    }

                    // Foreign key is set
                    // Check identity map first
                    // FIXME: Can break easily with composite keys if join column values are in
                    //        wrong order. The correct order is the one in ClassMetadata#identifier.
                    $relatedIdHash = self::getIdHashByIdentifier($associatedId);

                    switch (true) {
                        case isset($this->identityMap[$targetClass->rootEntityName][$relatedIdHash]):
                            $newValue = $this->identityMap[$targetClass->rootEntityName][$relatedIdHash];

                            // If this is an uninitialized proxy, we are deferring eager loads,
                            // this association is marked as eager fetch, and its an uninitialized proxy (wtf!)
                            // then we can append this entity for eager loading!
                            if (
                                $hints['fetchMode'][$class->name][$field] === ClassMetadata::FETCH_EAGER &&
                                isset($hints[self::HINT_DEFEREAGERLOAD]) &&
                                ! $targetClass->isIdentifierComposite &&
                                $this->isUninitializedObject($newValue)
                            ) {
                                $this->eagerLoadingEntities[$targetClass->rootEntityName][$relatedIdHash] = current($associatedId);
                            }

                            break;

                        case $targetClass->subClasses:
                            // If it might be a subtype, it can not be lazy. There isn't even
                            // a way to solve this with deferred eager loading, which means putting
                            // an entity with subclasses at a *-to-one location is really bad! (performance-wise)
                            $newValue = $this->getEntityPersister($assoc->targetEntity)->loadOneToOneEntity($assoc, $entity, $associatedId);
                            break;

                        default:
                            $normalizedAssociatedId = $this->normalizeIdentifier($targetClass, $associatedId);

                            switch (true) {
                                // We are negating the condition here. Other cases will assume it is valid!
                                case $hints['fetchMode'][$class->name][$field] !== ClassMetadata::FETCH_EAGER:
                                    $newValue = $this->em->getProxyFactory()->getProxy($assoc->targetEntity, $normalizedAssociatedId);
                                    $this->registerManagedProxy($newValue, $associatedId);
                                    break;

                                // Deferred eager load only works for single identifier classes
                                case isset($hints[self::HINT_DEFEREAGERLOAD]) &&
                                    $hints[self::HINT_DEFEREAGERLOAD] &&
                                    ! $targetClass->isIdentifierComposite:
                                    // TODO: Is there a faster approach?
                                    $this->eagerLoadingEntities[$targetClass->rootEntityName][$relatedIdHash] = current($normalizedAssociatedId);

                                    $newValue = $this->em->getProxyFactory()->getProxy($assoc->targetEntity, $normalizedAssociatedId);
                                    $this->registerManagedProxy($newValue, $associatedId);
                                    break;

                                default:
                                    // TODO: This is very imperformant, ignore it?
                                    $newValue = $this->em->find($assoc->targetEntity, $normalizedAssociatedId);
                                    break;
                            }
                    }

                    $this->originalEntityData[$oid][$field] = $newValue;
                    $class->propertyAccessors[$field]->setValue($entity, $newValue);

                    if ($assoc->inversedBy !== null && $assoc->isOneToOne() && $newValue !== null) {
                        $inverseAssoc = $targetClass->associationMappings[$assoc->inversedBy];
                        $targetClass->propertyAccessors[$inverseAssoc->fieldName]->setValue($newValue, $entity);
                    }

                    break;

                default:
                    assert($assoc->isToMany());
                    // Ignore if its a cached collection
                    if (isset($hints[Query::HINT_CACHE_ENABLED]) && $class->getFieldValue($entity, $field) instanceof PersistentCollection) {
                        break;
                    }

                    // use the given collection
                    if (isset($data[$field]) && $data[$field] instanceof PersistentCollection) {
                        $data[$field]->setOwner($entity, $assoc);

                        $class->propertyAccessors[$field]->setValue($entity, $data[$field]);
                        $this->originalEntityData[$oid][$field] = $data[$field];

                        break;
                    }

                    // Inject collection
                    $pColl = new PersistentCollection($this->em, $targetClass, new ArrayCollection());
                    $pColl->setOwner($entity, $assoc);
                    $pColl->setInitialized(false);

                    $reflField = $class->propertyAccessors[$field];
                    $reflField->setValue($entity, $pColl);

                    if ($hints['fetchMode'][$class->name][$field] === ClassMetadata::FETCH_EAGER) {
                        if (
                            $assoc->isOneToMany()
                            // is iteration
                            && ! (isset($hints[Query::HINT_INTERNAL_ITERATION]) && $hints[Query::HINT_INTERNAL_ITERATION])
                            // is foreign key composite
                            && ! ($targetClass->hasAssociation($assoc->mappedBy) && count($targetClass->getAssociationMapping($assoc->mappedBy)->joinColumns) > 1)
                            && ! $assoc->isIndexed()
                            // Loading from the second-level cache does not go through ObjectHydrator,
                            // so deferred batch eager loads are never flushed there. Load immediately
                            // instead (also allows hitting the collection cache region).
                            && ! isset($hints[Query::HINT_CACHE_ENABLED])
                        ) {
                            $this->scheduleCollectionForBatchLoading($pColl, $class);
                        } else {
                            $this->loadCollection($pColl);
                            $pColl->takeSnapshot();
                        }
                    }

                    $this->originalEntityData[$oid][$field] = $pColl;
                    break;
            }
        }

        // defer invoking of postLoad event to hydration complete step
        $this->hydrationCompleteHandler->deferPostLoadInvoking($class, $entity);

        return $entity;
    }

    public function triggerEagerLoads(): void
    {
        if (! $this->eagerLoadingEntities && ! $this->eagerLoadingCollections) {
            return;
        }

        // avoid infinite recursion
        $eagerLoadingEntities       = $this->eagerLoadingEntities;
        $this->eagerLoadingEntities = [];

        foreach ($eagerLoadingEntities as $entityName => $ids) {
            if (! $ids) {
                continue;
            }

            $class   = $this->em->getClassMetadata($entityName);
            $batches = array_chunk($ids, $this->em->getConfiguration()->getEagerFetchBatchSize());

            foreach ($batches as $batchedIds) {
                $this->getEntityPersister($entityName)->loadAll(
                    array_combine($class->identifier, [$batchedIds]),
                );
            }
        }

        $eagerLoadingCollections       = $this->eagerLoadingCollections; // avoid recursion
        $this->eagerLoadingCollections = [];

        foreach ($eagerLoadingCollections as $group) {
            $this->eagerLoadCollections($group['items'], $group['mapping']);
        }
    }

    /**
     * Load all data into the given collections, according to the specified mapping
     *
     * @param PersistentCollection[] $collections
     */
    private function eagerLoadCollections(array $collections, ToManyInverseSideMapping $mapping): void
    {
        $targetEntity = $mapping->targetEntity;
        $class        = $this->em->getClassMetadata($mapping->sourceEntity);
        $mappedBy     = $mapping->mappedBy;

        $batches = array_chunk($collections, $this->em->getConfiguration()->getEagerFetchBatchSize(), true);

        foreach ($batches as $collectionBatch) {
            $entities = [];

            foreach ($collectionBatch as $collection) {
                $entities[] = $collection->getOwner();
            }

            $found = $this->getEntityPersister($targetEntity)->loadAll([$mappedBy => $entities], $mapping->orderBy);

            $targetClass    = $this->em->getClassMetadata($targetEntity);
            $targetProperty = $targetClass->getPropertyAccessor($mappedBy);
            assert($targetProperty !== null);

            foreach ($found as $targetValue) {
                $sourceEntity = $targetProperty->getValue($targetValue);

                if ($sourceEntity === null && isset($targetClass->associationMappings[$mappedBy]->joinColumns)) {
                    // case where the hydration $targetValue itself has not yet fully completed, for example
                    // in case a bi-directional association is being hydrated and deferring eager loading is
                    // not possible due to subclassing.
                    $data = $this->getOriginalEntityData($targetValue);
                    $id   = [];
                    foreach ($targetClass->associationMappings[$mappedBy]->joinColumns as $joinColumn) {
                        $id[] = $data[$joinColumn->name];
                    }
                } else {
                    $id = $this->identifierFlattener->flattenIdentifier($class, $class->getIdentifierValues($sourceEntity));
                }

                $idHash = implode(' ', $id);

                if ($mapping->indexBy !== null) {
                    $indexByProperty = $targetClass->getPropertyAccessor($mapping->indexBy);
                    assert($indexByProperty !== null);
                    $collectionBatch[$idHash]->hydrateSet($indexByProperty->getValue($targetValue), $targetValue);
                } else {
                    $collectionBatch[$idHash]->add($targetValue);
                }
            }
        }

        foreach ($collections as $association) {
            $association->setInitialized(true);
            $association->takeSnapshot();
        }
    }

    /**
     * Initializes (loads) an uninitialized persistent collection of an entity.
     *
     * @todo Maybe later move to EntityManager#initialize($proxyOrCollection). See DDC-733.
     */
    public function loadCollection(PersistentCollection $collection): void
    {
        $assoc     = $collection->getMapping();
        $persister = $this->getEntityPersister($assoc->targetEntity);

        switch ($assoc->type()) {
            case ClassMetadata::ONE_TO_MANY:
                $persister->loadOneToManyCollection($assoc, $collection->getOwner(), $collection);
                break;

            case ClassMetadata::MANY_TO_MANY:
                $persister->loadManyToManyCollection($assoc, $collection->getOwner(), $collection);
                break;
        }

        $collection->setInitialized(true);
    }

    /**
     * Schedule this collection for batch loading at the end of the UnitOfWork
     */
    private function scheduleCollectionForBatchLoading(PersistentCollection $collection, ClassMetadata $sourceClass): void
    {
        $mapping = $collection->getMapping();
        $name    = $mapping->sourceEntity . '#' . $mapping->fieldName;

        if (! isset($this->eagerLoadingCollections[$name])) {
            $this->eagerLoadingCollections[$name] = [
                'items'   => [],
                'mapping' => $mapping,
            ];
        }

        $owner = $collection->getOwner();
        assert($owner !== null);

        $id     = $this->identifierFlattener->flattenIdentifier(
            $sourceClass,
            $sourceClass->getIdentifierValues($owner),
        );
        $idHash = implode(' ', $id);

        $this->eagerLoadingCollections[$name]['items'][$idHash] = $collection;
    }

    /**
     * Gets the identity map of the UnitOfWork.
     *
     * @return array<class-string, array<string, object>>
     */
    public function getIdentityMap(): array
    {
        return $this->identityMap;
    }

    /**
     * Gets the original data of an entity. The original data is the data that was
     * present at the time the entity was reconstituted from the database.
     *
     * @phpstan-return array<string, mixed>
     */
    public function getOriginalEntityData(object $entity): array
    {
        $oid = spl_object_id($entity);

        return $this->originalEntityData[$oid] ?? [];
    }

    /**
     * @param mixed[] $data
     *
     * @ignore
     */
    public function setOriginalEntityData(object $entity, array $data): void
    {
        $this->originalEntityData[spl_object_id($entity)] = $data;
    }

    /**
     * INTERNAL:
     * Sets a property value of the original data array of an entity.
     *
     * @ignore
     */
    public function setOriginalEntityProperty(int $oid, string $property, mixed $value): void
    {
        $this->originalEntityData[$oid][$property] = $value;
    }

    /**
     * Gets the identifier of an entity.
     * The returned value is always an array of identifier values. If the entity
     * has a composite identifier then the identifier values are in the same
     * order as the identifier field names as returned by ClassMetadata#getIdentifierFieldNames().
     *
     * @return mixed[] The identifier values.
     */
    public function getEntityIdentifier(object $entity): array
    {
        return $this->entityIdentifiers[spl_object_id($entity)]
            ?? throw EntityNotFoundException::noIdentifierFound(get_debug_type($entity));
    }

    /**
     * Processes an entity instance to extract their identifier values.
     *
     * @return mixed A scalar value.
     *
     * @throws ORMInvalidArgumentException
     */
    public function getSingleIdentifierValue(object $entity): mixed
    {
        $class = $this->em->getClassMetadata($entity::class);

        if ($class->isIdentifierComposite) {
            throw ORMInvalidArgumentException::invalidCompositeIdentifier();
        }

        $values = $this->isInIdentityMap($entity)
            ? $this->getEntityIdentifier($entity)
            : $class->getIdentifierValues($entity);

        return $values[$class->identifier[0]] ?? null;
    }

    /**
     * Tries to find an entity with the given identifier in the identity map of
     * this UnitOfWork.
     *
     * @param mixed        $id            The entity identifier to look for.
     * @param class-string $rootClassName The name of the root class of the mapped entity hierarchy.
     *
     * @return object|false Returns the entity with the specified identifier if it exists in
     *                      this UnitOfWork, FALSE otherwise.
     */
    public function tryGetById(mixed $id, string $rootClassName): object|false
    {
        $idHash = self::getIdHashByIdentifier((array) $id);

        return $this->identityMap[$rootClassName][$idHash] ?? false;
    }

    /**
     * Schedules an entity for dirty-checking at commit-time.
     *
     * @todo Rename: scheduleForSynchronization
     */
    public function scheduleForDirtyCheck(object $entity): void
    {
        $rootClassName = $this->em->getClassMetadata($entity::class)->rootEntityName;

        $this->scheduledForSynchronization[$rootClassName][spl_object_id($entity)] = $entity;
    }

    /**
     * Checks whether the UnitOfWork has any pending insertions.
     */
    public function hasPendingInsertions(): bool
    {
        return ! empty($this->entityInsertions);
    }

    /**
     * Calculates the size of the UnitOfWork. The size of the UnitOfWork is the
     * number of entities in the identity map.
     */
    public function size(): int
    {
        return array_sum(array_map('count', $this->identityMap));
    }

    /**
     * Gets the EntityPersister for an Entity.
     *
     * @param class-string $entityName The name of the Entity.
     */
    public function getEntityPersister(string $entityName): EntityPersister
    {
        if (isset($this->persisters[$entityName])) {
            return $this->persisters[$entityName];
        }

        $class = $this->em->getClassMetadata($entityName);

        $persister = match (true) {
            $class->isInheritanceTypeNone() => new BasicEntityPersister($this->em, $class),
            $class->isInheritanceTypeSingleTable() => new SingleTablePersister($this->em, $class),
            $class->isInheritanceTypeJoined() => new JoinedSubclassPersister($this->em, $class),
            default => throw new RuntimeException('No persister found for entity.'),
        };

        if ($this->hasCache && $class->cache !== null) {
            $persister = $this->em->getConfiguration()
                ->getSecondLevelCacheConfiguration()
                ->getCacheFactory()
                ->buildCachedEntityPersister($this->em, $persister, $class);
        }

        $this->persisters[$entityName] = $persister;

        return $this->persisters[$entityName];
    }

    /** Gets a collection persister for a collection-valued association. */
    public function getCollectionPersister(AssociationMapping $association): CollectionPersister
    {
        $role = isset($association->cache)
            ? $association->sourceEntity . '::' . $association->fieldName
            : $association->type();

        if (isset($this->collectionPersisters[$role])) {
            return $this->collectionPersisters[$role];
        }

        $persister = $association->type() === ClassMetadata::ONE_TO_MANY
            ? new OneToManyPersister($this->em)
            : new ManyToManyPersister($this->em);

        if ($this->hasCache && isset($association->cache)) {
            $persister = $this->em->getConfiguration()
                ->getSecondLevelCacheConfiguration()
                ->getCacheFactory()
                ->buildCachedCollectionPersister($this->em, $persister, $association);
        }

        $this->collectionPersisters[$role] = $persister;

        return $this->collectionPersisters[$role];
    }

    /**
     * INTERNAL:
     * Registers an entity as managed.
     *
     * @param mixed[] $id   The identifier values.
     * @param mixed[] $data The original entity data.
     */
    public function registerManaged(object $entity, array $id, array $data): void
    {
        $oid = spl_object_id($entity);

        $this->entityIdentifiers[$oid]  = $id;
        $this->entityStates[$oid]       = self::STATE_MANAGED;
        $this->originalEntityData[$oid] = $data;

        $this->addToIdentityMap($entity);
    }

    /**
     * INTERNAL:
     * Registers an uninitialized reference/association proxy as managed,
     * i.e. an entity obtained via {@see \Doctrine\ORM\Proxy\ProxyFactory::getProxy()}
     * of which no field has been loaded yet.
     *
     * @param mixed[] $id The identifier values.
     */
    public function registerManagedProxy(object $entity, array $id): void
    {
        $this->registerManaged($entity, $id, []);
        $this->markAsPartiallyLoaded($entity, []);
    }

    /**
     * @see self::$partialObjectLoadedFields
     *
     * @param list<string> $loadedFields
     */
    private function markAsPartiallyLoaded(object $entity, array $loadedFields): void
    {
        if (! $this->em->getConfiguration()->isNativeLazyObjectsEnabled()) {
            return;
        }

        $this->partialObjectLoadedFields[spl_object_id($entity)] = $loadedFields;
    }

    /* PropertyChangedListener implementation */

    /**
     * Notifies this UnitOfWork of a property change in an entity.
     *
     * {@inheritDoc}
     */
    public function propertyChanged(object $sender, string $propertyName, mixed $oldValue, mixed $newValue): void
    {
        $oid   = spl_object_id($sender);
        $class = $this->em->getClassMetadata($sender::class);

        $isAssocField = isset($class->associationMappings[$propertyName]);

        if (! $isAssocField && ! isset($class->fieldMappings[$propertyName])) {
            return; // ignore non-persistent fields
        }

        // Update changeset and mark entity for synchronization
        $this->entityChangeSets[$oid][$propertyName] = [$oldValue, $newValue];

        if (! isset($this->scheduledForSynchronization[$class->rootEntityName][$oid])) {
            $this->scheduleForDirtyCheck($sender);
        }
    }

    /**
     * Gets the currently scheduled entity insertions in this UnitOfWork.
     *
     * @phpstan-return array<int, object>
     */
    public function getScheduledEntityInsertions(): array
    {
        return $this->entityInsertions;
    }

    /**
     * Gets the currently scheduled entity updates in this UnitOfWork.
     *
     * @phpstan-return array<int, object>
     */
    public function getScheduledEntityUpdates(): array
    {
        return $this->entityUpdates;
    }

    /**
     * Gets the currently scheduled entity deletions in this UnitOfWork.
     *
     * @phpstan-return array<int, object>
     */
    public function getScheduledEntityDeletions(): array
    {
        return $this->entityDeletions;
    }

    /**
     * Gets the currently scheduled complete collection deletions
     *
     * @phpstan-return array<int, PersistentCollection<array-key, object>>
     */
    public function getScheduledCollectionDeletions(): array
    {
        return $this->collectionDeletions;
    }

    /**
     * Gets the currently scheduled collection inserts, updates and deletes.
     *
     * @phpstan-return array<int, PersistentCollection<array-key, object>>
     */
    public function getScheduledCollectionUpdates(): array
    {
        return $this->collectionUpdates;
    }

    /**
     * Helper method to initialize a lazy loading proxy or persistent collection.
     */
    public function initializeObject(object $obj): void
    {
        if ($obj instanceof InternalProxy) {
            $obj->__load();

            return;
        }

        if ($obj instanceof PersistentCollection) {
            $obj->initialize();

            return;
        }

        if ($this->em->getConfiguration()->isNativeLazyObjectsEnabled()) {
            if (! $this->isMappedClass($obj::class)) {
                return;
            }

            $reflection = $this->em->getClassMetadata($obj::class)->getReflectionClass();
            $reflection->initializeLazyObject($obj);
        }
    }

    /** Tests if a value is an uninitialized entity. */
    public function isUninitializedObject(mixed $obj): bool
    {
        if ($this->em->getConfiguration()->isNativeLazyObjectsEnabled() && ! ($obj instanceof Collection) && is_object($obj)) {
            if (! $this->isMappedClass($obj::class)) {
                return false;
            }

            return $this->em->getClassMetadata($obj::class)->reflClass->isUninitializedLazyObject($obj);
        }

        return $obj instanceof InternalProxy && ! $obj->__isInitialized();
    }

    /**
     * Helper method to show an object as string.
     */
    private static function objToStr(object $obj): string
    {
        return $obj instanceof Stringable ? (string) $obj : get_debug_type($obj) . '@' . spl_object_id($obj);
    }

    /**
     * Marks an entity as read-only so that it will not be considered for updates during UnitOfWork#commit().
     *
     * This operation cannot be undone as some parts of the UnitOfWork now keep gathering information
     * on this object that might be necessary to perform a correct update.
     *
     * @throws ORMInvalidArgumentException
     */
    public function markReadOnly(object $object): void
    {
        if (! $this->isInIdentityMap($object)) {
            throw ORMInvalidArgumentException::readOnlyRequiresManagedEntity($object);
        }

        $this->readOnlyObjects[spl_object_id($object)] = true;
    }

    /**
     * Is this entity read only?
     *
     * @throws ORMInvalidArgumentException
     */
    public function isReadOnly(object $object): bool
    {
        return isset($this->readOnlyObjects[spl_object_id($object)]);
    }

    /**
     * Perform whatever processing is encapsulated here after completion of the transaction.
     */
    private function afterTransactionComplete(): void
    {
        $this->performCallbackOnCachedPersister(static function (CachedPersister $persister): void {
            $persister->afterTransactionComplete();
        });
    }

    /**
     * Perform whatever processing is encapsulated here after completion of the rolled-back.
     */
    private function afterTransactionRolledBack(): void
    {
        $this->performCallbackOnCachedPersister(static function (CachedPersister $persister): void {
            $persister->afterTransactionRolledBack();
        });
    }

    /**
     * Performs an action after the transaction.
     */
    private function performCallbackOnCachedPersister(callable $callback): void
    {
        if (! $this->hasCache) {
            return;
        }

        foreach ([...$this->persisters, ...$this->collectionPersisters] as $persister) {
            if ($persister instanceof CachedPersister) {
                $callback($persister);
            }
        }
    }

    private function dispatchPreFlushEvent(): void
    {
        $this->eventDispatcher->dispatchEvent(Events::preFlush, new PreFlushEventArgs($this->em));
    }

    private function dispatchOnFlushEvent(): void
    {
        $this->eventDispatcher->dispatchEvent(Events::onFlush, new OnFlushEventArgs($this->em));
    }

    private function dispatchPostFlushEvent(): void
    {
        $this->eventDispatcher->dispatchEvent(Events::postFlush, new PostFlushEventArgs($this->em));
    }

    /**
     * Whether this entity manager has, or can load, metadata for the class.
     *
     * isTransient() alone is not enough: by contract it is only false for entities and mapped
     * superclasses, while embeddables also have metadata and can be lazy ghosts. Checking the
     * already loaded metadata first covers those and keeps the common case an array lookup.
     *
     * @phpstan-param class-string $className
     */
    private function isMappedClass(string $className): bool
    {
        $metadataFactory = $this->em->getMetadataFactory();

        return $metadataFactory->hasMetadataFor($className) || ! $metadataFactory->isTransient($className);
    }

    /**
     * Verifies if two given entities actually are the same based on identifier comparison
     */
    private function isIdentifierEquals(object $entity1, object $entity2): bool
    {
        if ($entity1 === $entity2) {
            return true;
        }

        $class = $this->em->getClassMetadata($entity1::class);

        if ($class !== $this->em->getClassMetadata($entity2::class)) {
            return false;
        }

        $oid1 = spl_object_id($entity1);
        $oid2 = spl_object_id($entity2);

        $id1 = $this->entityIdentifiers[$oid1] ?? $this->identifierFlattener->flattenIdentifier($class, $class->getIdentifierValues($entity1));
        $id2 = $this->entityIdentifiers[$oid2] ?? $this->identifierFlattener->flattenIdentifier($class, $class->getIdentifierValues($entity2));

        return $id1 === $id2 || self::getIdHashByIdentifier($id1) === self::getIdHashByIdentifier($id2);
    }

    /** @throws ORMInvalidArgumentException */
    private function assertThatThereAreNoUnintentionallyNonPersistedAssociations(): void
    {
        $entitiesNeedingCascadePersist = array_diff_key($this->nonCascadedNewDetectedEntities, $this->entityInsertions);

        $this->nonCascadedNewDetectedEntities = [];

        if ($entitiesNeedingCascadePersist) {
            throw ORMInvalidArgumentException::newEntitiesFoundThroughRelationships(
                array_values($entitiesNeedingCascadePersist),
            );
        }
    }

    /**
     * This method called by hydrators, and indicates that hydrator totally completed current hydration cycle.
     * Unit of work able to fire deferred events, related to loading events here.
     *
     * @internal should be called internally from object hydrators
     */
    public function hydrationComplete(): void
    {
        $this->hydrationCompleteHandler->hydrationComplete();
    }

    /** @throws MappingException if the entity has more than a single identifier. */
    private function convertSingleFieldIdentifierToPHPValue(ClassMetadata $class, mixed $identifierValue): mixed
    {
        return $this->em->getConnection()->convertToPHPValue(
            $identifierValue,
            $class->getTypeOfField($class->getSingleIdentifierFieldName()),
        );
    }

    /**
     * Given a flat identifier, this method will produce another flat identifier, but with all
     * association fields that are mapped as identifiers replaced by entity references, recursively.
     *
     * @param mixed[] $flatIdentifier
     *
     * @return array<string, mixed>
     */
    private function normalizeIdentifier(ClassMetadata $targetClass, array $flatIdentifier): array
    {
        $normalizedAssociatedId = [];

        foreach ($targetClass->getIdentifierFieldNames() as $name) {
            if (! array_key_exists($name, $flatIdentifier)) {
                continue;
            }

            if (! $targetClass->isSingleValuedAssociation($name)) {
                $normalizedAssociatedId[$name] = $flatIdentifier[$name];
                continue;
            }

            $targetIdMetadata = $this->em->getClassMetadata($targetClass->getAssociationTargetClass($name));

            // Note: the ORM prevents using an entity with a composite identifier as an identifier association
            //       therefore, reset($targetIdMetadata->identifier) is always correct
            $normalizedAssociatedId[$name] = $this->em->getReference(
                $targetIdMetadata->getName(),
                $this->normalizeIdentifier(
                    $targetIdMetadata,
                    [(string) reset($targetIdMetadata->identifier) => $flatIdentifier[$name]],
                ),
            );
        }

        return $normalizedAssociatedId;
    }

    /**
     * Assign a post-insert generated ID to an entity
     *
     * This is used by EntityPersisters after they inserted entities into the database.
     * It will place the assigned ID values in the entity's fields and start tracking
     * the entity in the identity map.
     */
    final public function assignPostInsertId(object $entity, mixed $generatedId): void
    {
        $class   = $this->em->getClassMetadata($entity::class);
        $idField = $class->getSingleIdentifierFieldName();
        $idValue = $this->convertSingleFieldIdentifierToPHPValue($class, $generatedId);
        $oid     = spl_object_id($entity);

        $class->propertyAccessors[$idField]->setValue($entity, $idValue);

        $this->entityIdentifiers[$oid]            = [$idField => $idValue];
        $this->entityStates[$oid]                 = self::STATE_MANAGED;
        $this->originalEntityData[$oid][$idField] = $idValue;

        $this->addToIdentityMap($entity);
    }
}
