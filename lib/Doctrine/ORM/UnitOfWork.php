<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\EventManager;
use Doctrine\Common\NotifyPropertyChanged;
use Doctrine\Common\PropertyChangedListener;
use Doctrine\DBAL\LockMode;
use Doctrine\Instantiator\Instantiator;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\ListenersInvoker;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Internal\HydrationCompleteHandler;
use Doctrine\ORM\Mapping\AssociationMetadata;
use Doctrine\ORM\Mapping\ChangeTrackingPolicy;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FetchMode;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\GeneratorType;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumnMetadata;
use Doctrine\ORM\Mapping\ManyToManyAssociationMetadata;
use Doctrine\ORM\Mapping\MetadataCollection;
use Doctrine\ORM\Mapping\OneToManyAssociationMetadata;
use Doctrine\ORM\Mapping\OneToOneAssociationMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMetadata;
use Doctrine\ORM\Mapping\ToOneAssociationMetadata;
use Doctrine\ORM\Mapping\VersionFieldMetadata;
use Doctrine\ORM\Persisters\Collection\CollectionPersister;
use Doctrine\ORM\Persisters\Collection\ManyToManyPersister;
use Doctrine\ORM\Persisters\Collection\OneToManyPersister;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Persisters\Entity\JoinedSubclassPersister;
use Doctrine\ORM\Persisters\Entity\SingleTablePersister;
use Doctrine\ORM\Utility\NormalizeIdentifier;
use InvalidArgumentException;
use ProxyManager\Proxy\GhostObjectInterface;
use UnexpectedValueException;
use function array_combine;
use function array_diff_key;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_pop;
use function array_reverse;
use function array_sum;
use function array_values;
use function current;
use function get_class;
use function implode;
use function in_array;
use function is_array;
use function is_object;
use function method_exists;
use function spl_object_id;
use function sprintf;

/**
 * The UnitOfWork is responsible for tracking changes to objects during an
 * "object-level" transaction and for writing out changes to the database
 * in the correct order.
 *
 * {@internal This class contains highly performance-sensitive code. }}
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
     * @see https://doctrine-orm.readthedocs.org/en/latest/reference/dql-doctrine-query-language.html?highlight=eager#temporarily-change-fetch-mode-in-dql
     */
    public const HINT_DEFEREAGERLOAD = 'deferEagerLoad';

    /**
     * The identity map that holds references to all managed entities that have
     * an identity. The entities are grouped by their class name.
     * Since all classes in a hierarchy must share the same identifier set,
     * we always take the root class name of the hierarchy.
     *
     * @var object[]
     */
    private $identityMap = [];

    /**
     * Map of all identifiers of managed entities.
     * This is a 2-dimensional data structure (map of maps). Keys are object ids (spl_object_id).
     * Values are maps of entity identifiers, where its key is the column name and the value is the raw value.
     *
     * @var mixed[][]
     */
    private $entityIdentifiers = [];

    /**
     * Map of the original entity data of managed entities.
     * This is a 2-dimensional data structure (map of maps). Keys are object ids (spl_object_id).
     * Values are maps of entity data, where its key is the field name and the value is the converted
     * (convertToPHPValue) value.
     * This structure is used for calculating changesets at commit time.
     *
     * Internal: Note that PHPs "copy-on-write" behavior helps a lot with memory usage.
     *           A value will only really be copied if the value in the entity is modified by the user.
     *
     * @var mixed[][]
     */
    private $originalEntityData = [];

    /**
     * Map of entity changes. Keys are object ids (spl_object_id).
     * Filled at the beginning of a commit of the UnitOfWork and cleaned at the end.
     *
     * @var mixed[][]
     */
    private $entityChangeSets = [];

    /**
     * The (cached) states of any known entities.
     * Keys are object ids (spl_object_id).
     *
     * @var int[]
     */
    private $entityStates = [];

    /**
     * Map of entities that are scheduled for dirty checking at commit time.
     * This is only used for entities with a change tracking policy of DEFERRED_EXPLICIT.
     * Keys are object ids (spl_object_id).
     *
     * @var object[][]
     */
    private $scheduledForSynchronization = [];

    /**
     * A list of all pending entity insertions.
     *
     * @var object[]
     */
    private $entityInsertions = [];

    /**
     * A list of all pending entity updates.
     *
     * @var object[]
     */
    private $entityUpdates = [];

    /**
     * Any pending extra updates that have been scheduled by persisters.
     *
     * @var object[]
     */
    private $extraUpdates = [];

    /**
     * A list of all pending entity deletions.
     *
     * @var object[]
     */
    private $entityDeletions = [];

    /**
     * New entities that were discovered through relationships that were not
     * marked as cascade-persist. During flush, this array is populated and
     * then pruned of any entities that were discovered through a valid
     * cascade-persist path. (Leftovers cause an error.)
     *
     * Keys are OIDs, payload is a two-item array describing the association
     * and the entity.
     *
     * @var object[][]|array[][] indexed by respective object spl_object_id()
     */
    private $nonCascadedNewDetectedEntities = [];

    /**
     * All pending collection deletions.
     *
     * @var Collection[]|object[][]
     */
    private $collectionDeletions = [];

    /**
     * All pending collection updates.
     *
     * @var Collection[]|object[][]
     */
    private $collectionUpdates = [];

    /**
     * List of collections visited during changeset calculation on a commit-phase of a UnitOfWork.
     * At the end of the UnitOfWork all these collections will make new snapshots
     * of their data.
     *
     * @var Collection[]|object[][]
     */
    private $visitedCollections = [];

    /**
     * The EntityManager that "owns" this UnitOfWork instance.
     *
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * The entity persister instances used to persist entity instances.
     *
     * @var EntityPersister[]
     */
    private $entityPersisters = [];

    /**
     * The collection persister instances used to persist collections.
     *
     * @var CollectionPersister[]
     */
    private $collectionPersisters = [];

    /**
     * The EventManager used for dispatching events.
     *
     * @var EventManager
     */
    private $eventManager;

    /**
     * The ListenersInvoker used for dispatching events.
     *
     * @var ListenersInvoker
     */
    private $listenersInvoker;

    /** @var Instantiator */
    private $instantiator;

    /**
     * Orphaned entities that are scheduled for removal.
     *
     * @var object[]
     */
    private $orphanRemovals = [];

    /**
     * Read-Only objects are never evaluated
     *
     * @var object[]
     */
    private $readOnlyObjects = [];

    /**
     * Map of Entity Class-Names and corresponding IDs that should eager loaded when requested.
     *
     * @var mixed[][][]
     */
    private $eagerLoadingEntities = [];

    /** @var bool */
    protected $hasCache = false;

    /**
     * Helper for handling completion of hydration
     *
     * @var HydrationCompleteHandler
     */
    private $hydrationCompleteHandler;

    /** @var NormalizeIdentifier */
    private $normalizeIdentifier;

    /** @var MetadataCollection */
    private $mappings;

    /**
     * Initializes a new UnitOfWork instance, bound to the given EntityManager.
     */
    public function __construct(EntityManagerInterface $em, MetadataCollection $mappings)
    {
        $this->mappings                 = $mappings;
        $this->em                       = $em;
        $this->eventManager             = $em->getEventManager();
        $this->listenersInvoker         = new ListenersInvoker($em);
        $this->hasCache                 = $em->getConfiguration()->isSecondLevelCacheEnabled();
        $this->instantiator             = new Instantiator();
        $this->hydrationCompleteHandler = new HydrationCompleteHandler($this->listenersInvoker, $em);
        $this->normalizeIdentifier      = new NormalizeIdentifier();
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
     * @throws \Exception
     */
    public function commit()
    {
        // Raise preFlush
        if ($this->eventManager->hasListeners(Events::preFlush)) {
            $this->eventManager->dispatchEvent(Events::preFlush, new PreFlushEventArgs($this->em));
        }

        $this->computeChangeSets();

        if (! ($this->entityInsertions ||
                $this->entityDeletions ||
                $this->entityUpdates ||
                $this->collectionUpdates ||
                $this->collectionDeletions ||
                $this->orphanRemovals)) {
            $this->dispatchOnFlushEvent();
            $this->dispatchPostFlushEvent();

            return; // Nothing to do.
        }

        $this->assertThatThereAreNoUnintentionallyNonPersistedAssociations();

        if ($this->orphanRemovals) {
            foreach ($this->orphanRemovals as $orphan) {
                $this->remove($orphan);
            }
        }

        $this->dispatchOnFlushEvent();

        // Now we need a commit order to maintain referential integrity
        $commitOrder = $this->getCommitOrder();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Collection deletions (deletions of complete collections)
            foreach ($this->collectionDeletions as $collectionToDelete) {
                $this->getCollectionPersister($collectionToDelete->getMapping())->delete($collectionToDelete);
            }

            if ($this->entityInsertions) {
                foreach ($commitOrder as $class) {
                    $this->executeInserts($class);
                }
            }

            if ($this->entityUpdates) {
                foreach ($commitOrder as $class) {
                    $this->executeUpdates($class);
                }
            }

            // Extra updates that were requested by persisters.
            if ($this->extraUpdates) {
                $this->executeExtraUpdates();
            }

            // Collection updates (deleteRows, updateRows, insertRows)
            foreach ($this->collectionUpdates as $collectionToUpdate) {
                $this->getCollectionPersister($collectionToUpdate->getMapping())->update($collectionToUpdate);
            }

            // Entity deletions come last and need to be in reverse commit order
            if ($this->entityDeletions) {
                foreach (array_reverse($commitOrder) as $committedEntityName) {
                    if (! $this->entityDeletions) {
                        break; // just a performance optimisation
                    }

                    $this->executeDeletions($committedEntityName);
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $this->em->close();
            $conn->rollBack();

            $this->afterTransactionRolledBack();

            throw $e;
        }

        $this->afterTransactionComplete();

        // Take new snapshots from visited collections
        foreach ($this->visitedCollections as $coll) {
            $coll->takeSnapshot();
        }

        $this->dispatchPostFlushEvent();

        // Clean up
        $this->entityInsertions            =
        $this->entityUpdates               =
        $this->entityDeletions             =
        $this->extraUpdates                =
        $this->entityChangeSets            =
        $this->collectionUpdates           =
        $this->collectionDeletions         =
        $this->visitedCollections          =
        $this->scheduledForSynchronization =
        $this->orphanRemovals              = [];
    }

    /**
     * Computes the changesets of all entities scheduled for insertion.
     */
    private function computeScheduleInsertsChangeSets()
    {
        foreach ($this->entityInsertions as $entity) {
            $class = $this->em->getClassMetadata(get_class($entity));

            $this->computeChangeSet($class, $entity);
        }
    }

    /**
     * Executes any extra updates that have been scheduled.
     */
    private function executeExtraUpdates()
    {
        foreach ($this->extraUpdates as $oid => $update) {
            list ($entity, $changeset) = $update;

            $this->entityChangeSets[$oid] = $changeset;

//            echo 'Extra update: ';
//            \Doctrine\Common\Util\Debug::dump($changeset, 3);

            $this->getEntityPersister(get_class($entity))->update($entity);
        }

        $this->extraUpdates = [];
    }

    /**
     * Gets the changeset for an entity.
     *
     * @param object $entity
     *
     * @return mixed[]
     */
    public function & getEntityChangeSet($entity)
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
     * {@link originalEntityData}
     * If the entity is NEW or MANAGED but not yet fully persisted (only has an id)
     * then it was not fetched from the database and therefore we have no original
     * entity data yet. All of the current entity data is stored as the original entity data.
     *
     * {@link entityChangeSets}
     * The changes detected on all properties of the entity are stored there.
     * A change is a tuple array where the first entry is the old value and the second
     * entry is the new value of the property. Changesets are used by persisters
     * to INSERT/UPDATE the persistent entity state.
     *
     * {@link entityUpdates}
     * If the entity is already fully MANAGED (has been fetched from the database before)
     * and any changes to its properties are detected, then a reference to the entity is stored
     * there to mark it for an update.
     *
     * {@link collectionDeletions}
     * If a PersistentCollection has been de-referenced in a fully MANAGED entity,
     * then this collection is marked for deletion.
     *
     * @ignore
     *
     * @internal Don't call from the outside.
     *
     * @param ClassMetadata $class  The class descriptor of the entity.
     * @param object        $entity The entity for which to compute the changes.
     *
     */
    public function computeChangeSet(ClassMetadata $class, $entity)
    {
        $oid = spl_object_id($entity);

        if (isset($this->readOnlyObjects[$oid])) {
            return;
        }

        if ($class->inheritanceType !== InheritanceType::NONE) {
            $class = $this->em->getClassMetadata(get_class($entity));
        }

        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::preFlush) & ~ListenersInvoker::INVOKE_MANAGER;

        if ($invoke !== ListenersInvoker::INVOKE_NONE) {
            $this->listenersInvoker->invoke($class, Events::preFlush, $entity, new PreFlushEventArgs($this->em), $invoke);
        }

        $actualData = [];

        foreach ($class->getDeclaredPropertiesIterator() as $name => $property) {
            $value = $property->getValue($entity);

            if ($property instanceof ToManyAssociationMetadata && $value !== null) {
                if ($value instanceof PersistentCollection && $value->getOwner() === $entity) {
                    continue;
                }

                $value = $property->wrap($entity, $value, $this->em);

                $property->setValue($entity, $value);

                $actualData[$name] = $value;

                continue;
            }

            if (( ! $class->isIdentifier($name)
                    || ! $class->getProperty($name) instanceof FieldMetadata
                    || ! $class->getProperty($name)->hasValueGenerator()
                    || $class->getProperty($name)->getValueGenerator()->getType() !== GeneratorType::IDENTITY
                ) && (! $class->isVersioned() || $name !== $class->versionProperty->getName())) {
                $actualData[$name] = $value;
            }
        }

        if (! isset($this->originalEntityData[$oid])) {
            // Entity is either NEW or MANAGED but not yet fully persisted (only has an id).
            // These result in an INSERT.
            $this->originalEntityData[$oid] = $actualData;
            $changeSet                      = [];

            foreach ($actualData as $propName => $actualValue) {
                $property = $class->getProperty($propName);

                if (($property instanceof FieldMetadata) ||
                    ($property instanceof ToOneAssociationMetadata && $property->isOwningSide())) {
                    $changeSet[$propName] = [null, $actualValue];
                }
            }

            $this->entityChangeSets[$oid] = $changeSet;
        } else {
            // Entity is "fully" MANAGED: it was already fully persisted before
            // and we have a copy of the original data
            $originalData           = $this->originalEntityData[$oid];
            $isChangeTrackingNotify = $class->changeTrackingPolicy === ChangeTrackingPolicy::NOTIFY;
            $changeSet              = ($isChangeTrackingNotify && isset($this->entityChangeSets[$oid]))
                ? $this->entityChangeSets[$oid]
                : [];

            foreach ($actualData as $propName => $actualValue) {
                // skip field, its a partially omitted one!
                if (! (isset($originalData[$propName]) || array_key_exists($propName, $originalData))) {
                    continue;
                }

                $orgValue = $originalData[$propName];

                // skip if value haven't changed
                if ($orgValue === $actualValue) {
                    continue;
                }

                $property = $class->getProperty($propName);

                // Persistent collection was exchanged with the "originally"
                // created one. This can only mean it was cloned and replaced
                // on another entity.
                if ($actualValue instanceof PersistentCollection) {
                    $owner = $actualValue->getOwner();

                    if ($owner === null) { // cloned
                        $actualValue->setOwner($entity, $property);
                    } elseif ($owner !== $entity) { // no clone, we have to fix
                        if (! $actualValue->isInitialized()) {
                            $actualValue->initialize(); // we have to do this otherwise the cols share state
                        }

                        $newValue = clone $actualValue;

                        $newValue->setOwner($entity, $property);

                        $property->setValue($entity, $newValue);
                    }
                }

                switch (true) {
                    case ($property instanceof FieldMetadata):
                        if ($isChangeTrackingNotify) {
                            // Continue inside switch behaves as break.
                            // We are required to use continue 2, since we need to continue to next $actualData item
                            continue 2;
                        }

                        $changeSet[$propName] = [$orgValue, $actualValue];
                        break;

                    case ($property instanceof ToOneAssociationMetadata):
                        if ($property->isOwningSide()) {
                            $changeSet[$propName] = [$orgValue, $actualValue];
                        }

                        if ($orgValue !== null && $property->isOrphanRemoval()) {
                            $this->scheduleOrphanRemoval($orgValue);
                        }

                        break;

                    case ($property instanceof ToManyAssociationMetadata):
                        // Check if original value exists
                        if ($orgValue instanceof PersistentCollection) {
                            // A PersistentCollection was de-referenced, so delete it.
                            if (! $this->isCollectionScheduledForDeletion($orgValue)) {
                                $this->scheduleCollectionDeletion($orgValue);

                                $changeSet[$propName] = $orgValue; // Signal changeset, to-many associations will be ignored
                            }
                        }

                        break;

                    default:
                        // Do nothing
                }
            }

            if ($changeSet) {
                $this->entityChangeSets[$oid]   = $changeSet;
                $this->originalEntityData[$oid] = $actualData;
                $this->entityUpdates[$oid]      = $entity;
            }
        }

        // Look for changes in associations of the entity
        foreach ($class->getDeclaredPropertiesIterator() as $property) {
            if (! $property instanceof AssociationMetadata) {
                continue;
            }

            $value = $property->getValue($entity);

            if ($value === null) {
                continue;
            }

            $this->computeAssociationChanges($property, $value);

            if ($property instanceof ManyToManyAssociationMetadata &&
                $value instanceof PersistentCollection &&
                ! isset($this->entityChangeSets[$oid]) &&
                $property->isOwningSide() &&
                $value->isDirty()) {
                $this->entityChangeSets[$oid]   = [];
                $this->originalEntityData[$oid] = $actualData;
                $this->entityUpdates[$oid]      = $entity;
            }
        }
    }

    /**
     * Computes all the changes that have been done to entities and collections
     * since the last commit and stores these changes in the _entityChangeSet map
     * temporarily for access by the persisters, until the UoW commit is finished.
     */
    public function computeChangeSets()
    {
        // Compute changes for INSERTed entities first. This must always happen.
        $this->computeScheduleInsertsChangeSets();

        // Compute changes for other MANAGED entities. Change tracking policies take effect here.
        foreach ($this->identityMap as $className => $entities) {
            $class = $this->em->getClassMetadata($className);

            // Skip class if instances are read-only
            if ($class->isReadOnly()) {
                continue;
            }

            // If change tracking is explicit or happens through notification, then only compute
            // changes on entities of that type that are explicitly marked for synchronization.
            switch (true) {
                case ($class->changeTrackingPolicy === ChangeTrackingPolicy::DEFERRED_IMPLICIT):
                    $entitiesToProcess = $entities;
                    break;

                case (isset($this->scheduledForSynchronization[$className])):
                    $entitiesToProcess = $this->scheduledForSynchronization[$className];
                    break;

                default:
                    $entitiesToProcess = [];
            }

            foreach ($entitiesToProcess as $entity) {
                // Ignore uninitialized proxy objects
                if ($entity instanceof GhostObjectInterface && ! $entity->isProxyInitialized()) {
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
     * @param AssociationMetadata $association The association mapping.
     * @param mixed               $value       The value of the association.
     *
     * @throws ORMInvalidArgumentException
     * @throws ORMException
     */
    private function computeAssociationChanges(AssociationMetadata $association, $value)
    {
        if ($value instanceof GhostObjectInterface && ! $value->isProxyInitialized()) {
            return;
        }

        if ($value instanceof PersistentCollection && $value->isDirty()) {
            $coid = spl_object_id($value);

            $this->collectionUpdates[$coid]  = $value;
            $this->visitedCollections[$coid] = $value;
        }

        // Look through the entities, and in any of their associations,
        // for transient (new) entities, recursively. ("Persistence by reachability")
        // Unwrap. Uninitialized collections will simply be empty.
        $unwrappedValue = ($association instanceof ToOneAssociationMetadata) ? [$value] : $value->unwrap();
        $targetEntity   = $association->getTargetEntity();
        $targetClass    = $this->em->getClassMetadata($targetEntity);

        foreach ($unwrappedValue as $key => $entry) {
            if (! ($entry instanceof $targetEntity)) {
                throw ORMInvalidArgumentException::invalidAssociation($targetClass, $association, $entry);
            }

            $state = $this->getEntityState($entry, self::STATE_NEW);

            if (! ($entry instanceof $targetEntity)) {
                throw ORMException::unexpectedAssociationValue(
                    $association->getSourceEntity(),
                    $association->getName(),
                    get_class($entry),
                    $targetEntity
                );
            }

            switch ($state) {
                case self::STATE_NEW:
                    if (! in_array('persist', $association->getCascade(), true)) {
                        $this->nonCascadedNewDetectedEntities[spl_object_id($entry)] = [$association, $entry];

                        break;
                    }

                    $this->persistNew($targetClass, $entry);
                    $this->computeChangeSet($targetClass, $entry);

                    break;

                case self::STATE_REMOVED:
                    // Consume the $value as array (it's either an array or an ArrayAccess)
                    // and remove the element from Collection.
                    if ($association instanceof ToManyAssociationMetadata) {
                        unset($value[$key]);
                    }
                    break;

                case self::STATE_DETACHED:
                    // Can actually not happen right now as we assume STATE_NEW,
                    // so the exception will be raised from the DBAL layer (constraint violation).
                    throw ORMInvalidArgumentException::detachedEntityFoundThroughRelationship($association, $entry);
                    break;

                default:
                    // MANAGED associated entities are already taken into account
                    // during changeset calculation anyway, since they are in the identity map.
            }
        }
    }

    /**
     * @param ClassMetadata $class
     * @param object        $entity
     */
    private function persistNew($class, $entity)
    {
        $oid    = spl_object_id($entity);
        $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::prePersist);

        if ($invoke !== ListenersInvoker::INVOKE_NONE) {
            $this->listenersInvoker->invoke($class, Events::prePersist, $entity, new LifecycleEventArgs($entity, $this->em), $invoke);
        }

        $generationPlan = $class->getValueGenerationPlan();
        $persister      = $this->getEntityPersister($class->getClassName());
        $generationPlan->executeImmediate($this->em, $entity);

        if (! $generationPlan->containsDeferred()) {
            $id                            = $this->em->getIdentifierFlattener()->flattenIdentifier($class, $persister->getIdentifier($entity));
            $this->entityIdentifiers[$oid] = $id;
        }

        $this->entityStates[$oid] = self::STATE_MANAGED;

        $this->scheduleForInsert($entity);
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
     * @ignore
     *
     * @param ClassMetadata $class  The class descriptor of the entity.
     * @param object        $entity The entity for which to (re)calculate the change set.
     *
     * @throws ORMInvalidArgumentException If the passed entity is not MANAGED.
     * @throws \RuntimeException
     */
    public function recomputeSingleEntityChangeSet(ClassMetadata $class, $entity) : void
    {
        $oid = spl_object_id($entity);

        if (! isset($this->entityStates[$oid]) || $this->entityStates[$oid] !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        // skip if change tracking is "NOTIFY"
        if ($class->changeTrackingPolicy === ChangeTrackingPolicy::NOTIFY) {
            return;
        }

        if ($class->inheritanceType !== InheritanceType::NONE) {
            $class = $this->em->getClassMetadata(get_class($entity));
        }

        $actualData = [];

        foreach ($class->getDeclaredPropertiesIterator() as $name => $property) {
            switch (true) {
                case ($property instanceof VersionFieldMetadata):
                    // Ignore version field
                    break;

                case ($property instanceof FieldMetadata):
                    if (! $property->isPrimaryKey()
                        || ! $property->getValueGenerator()
                        || $property->getValueGenerator()->getType() !== GeneratorType::IDENTITY) {
                        $actualData[$name] = $property->getValue($entity);
                    }

                    break;

                case ($property instanceof ToOneAssociationMetadata):
                    $actualData[$name] = $property->getValue($entity);
                    break;
            }
        }

        if (! isset($this->originalEntityData[$oid])) {
            throw new \RuntimeException('Cannot call recomputeSingleEntityChangeSet before computeChangeSet on an entity.');
        }

        $originalData = $this->originalEntityData[$oid];
        $changeSet    = [];

        foreach ($actualData as $propName => $actualValue) {
            $orgValue = $originalData[$propName] ?? null;

            if ($orgValue !== $actualValue) {
                $changeSet[$propName] = [$orgValue, $actualValue];
            }
        }

        if ($changeSet) {
            if (isset($this->entityChangeSets[$oid])) {
                $this->entityChangeSets[$oid] = array_merge($this->entityChangeSets[$oid], $changeSet);
            } elseif (! isset($this->entityInsertions[$oid])) {
                $this->entityChangeSets[$oid] = $changeSet;
                $this->entityUpdates[$oid]    = $entity;
            }
            $this->originalEntityData[$oid] = $actualData;
        }
    }

    /**
     * Executes all entity insertions for entities of the specified type.
     */
    private function executeInserts(ClassMetadata $class) : void
    {
        $className      = $class->getClassName();
        $persister      = $this->getEntityPersister($className);
        $invoke         = $this->listenersInvoker->getSubscribedSystems($class, Events::postPersist);
        $generationPlan = $class->getValueGenerationPlan();

        foreach ($this->entityInsertions as $oid => $entity) {
            if ($this->em->getClassMetadata(get_class($entity))->getClassName() !== $className) {
                continue;
            }

            $persister->insert($entity);

            if ($generationPlan->containsDeferred()) {
                // Entity has post-insert IDs
                $oid = spl_object_id($entity);
                $id  = $persister->getIdentifier($entity);

                $this->entityIdentifiers[$oid]  = $this->em->getIdentifierFlattener()->flattenIdentifier($class, $id);
                $this->entityStates[$oid]       = self::STATE_MANAGED;
                $this->originalEntityData[$oid] = $id + $this->originalEntityData[$oid];

                $this->addToIdentityMap($entity);
            }

            unset($this->entityInsertions[$oid]);

            if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                $eventArgs = new LifecycleEventArgs($entity, $this->em);

                $this->listenersInvoker->invoke($class, Events::postPersist, $entity, $eventArgs, $invoke);
            }
        }
    }

    /**
     * Executes all entity updates for entities of the specified type.
     *
     * @param ClassMetadata $class
     */
    private function executeUpdates($class)
    {
        $className        = $class->getClassName();
        $persister        = $this->getEntityPersister($className);
        $preUpdateInvoke  = $this->listenersInvoker->getSubscribedSystems($class, Events::preUpdate);
        $postUpdateInvoke = $this->listenersInvoker->getSubscribedSystems($class, Events::postUpdate);

        foreach ($this->entityUpdates as $oid => $entity) {
            if ($this->em->getClassMetadata(get_class($entity))->getClassName() !== $className) {
                continue;
            }

            if ($preUpdateInvoke !== ListenersInvoker::INVOKE_NONE) {
                $this->listenersInvoker->invoke($class, Events::preUpdate, $entity, new PreUpdateEventArgs($entity, $this->em, $this->getEntityChangeSet($entity)), $preUpdateInvoke);

                $this->recomputeSingleEntityChangeSet($class, $entity);
            }

            if (! empty($this->entityChangeSets[$oid])) {
//                echo 'Update: ';
//                \Doctrine\Common\Util\Debug::dump($this->entityChangeSets[$oid], 3);

                $persister->update($entity);
            }

            unset($this->entityUpdates[$oid]);

            if ($postUpdateInvoke !== ListenersInvoker::INVOKE_NONE) {
                $this->listenersInvoker->invoke($class, Events::postUpdate, $entity, new LifecycleEventArgs($entity, $this->em), $postUpdateInvoke);
            }
        }
    }

    /**
     * Executes all entity deletions for entities of the specified type.
     *
     * @param ClassMetadata $class
     */
    private function executeDeletions($class)
    {
        $className = $class->getClassName();
        $persister = $this->getEntityPersister($className);
        $invoke    = $this->listenersInvoker->getSubscribedSystems($class, Events::postRemove);

        foreach ($this->entityDeletions as $oid => $entity) {
            if ($this->em->getClassMetadata(get_class($entity))->getClassName() !== $className) {
                continue;
            }

            $persister->delete($entity);

            unset(
                $this->entityDeletions[$oid],
                $this->entityIdentifiers[$oid],
                $this->originalEntityData[$oid],
                $this->entityStates[$oid]
            );

            // Entity with this $oid after deletion treated as NEW, even if the $oid
            // is obtained by a new entity because the old one went out of scope.
            //$this->entityStates[$oid] = self::STATE_NEW;
            if (! $class->isIdentifierComposite()) {
                $property = $class->getProperty($class->getSingleIdentifierFieldName());

                if ($property instanceof FieldMetadata && $property->hasValueGenerator()) {
                    $property->setValue($entity, null);
                }
            }

            if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                $eventArgs = new LifecycleEventArgs($entity, $this->em);

                $this->listenersInvoker->invoke($class, Events::postRemove, $entity, $eventArgs, $invoke);
            }
        }
    }

    /**
     * Gets the commit order.
     *
     * @return ClassMetadata[]
     */
    private function getCommitOrder()
    {
        $calc = new Internal\CommitOrderCalculator();

        // See if there are any new classes in the changeset, that are not in the
        // commit order graph yet (don't have a node).
        // We have to inspect changeSet to be able to correctly build dependencies.
        // It is not possible to use IdentityMap here because post inserted ids
        // are not yet available.
        $newNodes = [];

        foreach (array_merge($this->entityInsertions, $this->entityUpdates, $this->entityDeletions) as $entity) {
            $class = $this->em->getClassMetadata(get_class($entity));

            if ($calc->hasNode($class->getClassName())) {
                continue;
            }

            $calc->addNode($class->getClassName(), $class);

            $newNodes[] = $class;
        }

        // Calculate dependencies for new nodes
        while ($class = array_pop($newNodes)) {
            foreach ($class->getDeclaredPropertiesIterator() as $property) {
                if (! ($property instanceof ToOneAssociationMetadata && $property->isOwningSide())) {
                    continue;
                }

                $targetClass = $this->em->getClassMetadata($property->getTargetEntity());

                if (! $calc->hasNode($targetClass->getClassName())) {
                    $calc->addNode($targetClass->getClassName(), $targetClass);

                    $newNodes[] = $targetClass;
                }

                $weight = ! array_filter(
                    $property->getJoinColumns(),
                    function (JoinColumnMetadata $joinColumn) {
                        return $joinColumn->isNullable();
                    }
                );

                $calc->addDependency($targetClass->getClassName(), $class->getClassName(), $weight);

                // If the target class has mapped subclasses, these share the same dependency.
                if (! $targetClass->getSubClasses()) {
                    continue;
                }

                foreach ($targetClass->getSubClasses() as $subClassName) {
                    $targetSubClass = $this->em->getClassMetadata($subClassName);

                    if (! $calc->hasNode($subClassName)) {
                        $calc->addNode($targetSubClass->getClassName(), $targetSubClass);

                        $newNodes[] = $targetSubClass;
                    }

                    $calc->addDependency($targetSubClass->getClassName(), $class->getClassName(), 1);
                }
            }
        }

        return $calc->sort();
    }

    /**
     * Schedules an entity for insertion into the database.
     * If the entity already has an identifier, it will be added to the identity map.
     *
     * @param object $entity The entity to schedule for insertion.
     *
     * @throws ORMInvalidArgumentException
     * @throws \InvalidArgumentException
     */
    public function scheduleForInsert($entity)
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

        if ($entity instanceof NotifyPropertyChanged) {
            $entity->addPropertyChangedListener($this);
        }
    }

    /**
     * Checks whether an entity is scheduled for insertion.
     *
     * @param object $entity
     *
     * @return bool
     */
    public function isScheduledForInsert($entity)
    {
        return isset($this->entityInsertions[spl_object_id($entity)]);
    }

    /**
     * Schedules an entity for being updated.
     *
     * @param object $entity The entity to schedule for being updated.
     *
     * @throws ORMInvalidArgumentException
     */
    public function scheduleForUpdate($entity) : void
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
     * @ignore
     *
     * @param object  $entity    The entity for which to schedule an extra update.
     * @param mixed[] $changeset The changeset of the entity (what to update).
     *
     */
    public function scheduleExtraUpdate($entity, array $changeset) : void
    {
        $oid         = spl_object_id($entity);
        $extraUpdate = [$entity, $changeset];

        if (isset($this->extraUpdates[$oid])) {
            [$unused, $changeset2] = $this->extraUpdates[$oid];

            $extraUpdate = [$entity, $changeset + $changeset2];
        }

        $this->extraUpdates[$oid] = $extraUpdate;
    }

    /**
     * Checks whether an entity is registered as dirty in the unit of work.
     * Note: Is not very useful currently as dirty entities are only registered
     * at commit time.
     *
     * @param object $entity
     */
    public function isScheduledForUpdate($entity) : bool
    {
        return isset($this->entityUpdates[spl_object_id($entity)]);
    }

    /**
     * Checks whether an entity is registered to be checked in the unit of work.
     *
     * @param object $entity
     */
    public function isScheduledForDirtyCheck($entity) : bool
    {
        $rootEntityName = $this->em->getClassMetadata(get_class($entity))->getRootClassName();

        return isset($this->scheduledForSynchronization[$rootEntityName][spl_object_id($entity)]);
    }

    /**
     * INTERNAL:
     * Schedules an entity for deletion.
     *
     * @param object $entity
     */
    public function scheduleForDelete($entity)
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

        $this->removeFromIdentityMap($entity);

        unset($this->entityUpdates[$oid]);

        if (! isset($this->entityDeletions[$oid])) {
            $this->entityDeletions[$oid] = $entity;
            $this->entityStates[$oid]    = self::STATE_REMOVED;
        }
    }

    /**
     * Checks whether an entity is registered as removed/deleted with the unit
     * of work.
     *
     * @param object $entity
     *
     * @return bool
     */
    public function isScheduledForDelete($entity)
    {
        return isset($this->entityDeletions[spl_object_id($entity)]);
    }

    /**
     * Checks whether an entity is scheduled for insertion, update or deletion.
     *
     * @param object $entity
     *
     * @return bool
     */
    public function isEntityScheduled($entity)
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
     * @ignore
     *
     * @param object $entity The entity to register.
     *
     * @return bool  TRUE if the registration was successful, FALSE if the identity of
     *               the entity in question is already managed.
     *
     * @throws ORMInvalidArgumentException
     */
    public function addToIdentityMap($entity)
    {
        $classMetadata = $this->em->getClassMetadata(get_class($entity));
        $identifier    = $this->entityIdentifiers[spl_object_id($entity)];

        if (empty($identifier) || in_array(null, $identifier, true)) {
            throw ORMInvalidArgumentException::entityWithoutIdentity($classMetadata->getClassName(), $entity);
        }

        $idHash    = implode(' ', $identifier);
        $className = $classMetadata->getRootClassName();

        if (isset($this->identityMap[$className][$idHash])) {
            return false;
        }

        $this->identityMap[$className][$idHash] = $entity;

        return true;
    }

    /**
     * Gets the state of an entity with regard to the current unit of work.
     *
     * @param object   $entity
     * @param int|null $assume The state to assume if the state is not yet known (not MANAGED or REMOVED).
     *                         This parameter can be set to improve performance of entity state detection
     *                         by potentially avoiding a database lookup if the distinction between NEW and DETACHED
     *                         is either known or does not matter for the caller of the method.
     *
     * @return int The entity state.
     */
    public function getEntityState($entity, $assume = null)
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
        $class     = $this->em->getClassMetadata(get_class($entity));
        $persister = $this->getEntityPersister($class->getClassName());
        $id        = $persister->getIdentifier($entity);

        if (! $id) {
            return self::STATE_NEW;
        }

        $flatId = $this->em->getIdentifierFlattener()->flattenIdentifier($class, $id);

        if ($class->isIdentifierComposite()
            || ! $class->getProperty($class->getSingleIdentifierFieldName()) instanceof FieldMetadata
            || ! $class->getProperty($class->getSingleIdentifierFieldName())->hasValueGenerator()
        ) {
            // Check for a version field, if available, to avoid a db lookup.
            if ($class->isVersioned()) {
                return $class->versionProperty->getValue($entity)
                    ? self::STATE_DETACHED
                    : self::STATE_NEW;
            }

            // Last try before db lookup: check the identity map.
            if ($this->tryGetById($flatId, $class->getRootClassName())) {
                return self::STATE_DETACHED;
            }

            // db lookup
            if ($this->getEntityPersister($class->getClassName())->exists($entity)) {
                return self::STATE_DETACHED;
            }

            return self::STATE_NEW;
        }

        if ($class->isIdentifierComposite()
            || ! $class->getProperty($class->getSingleIdentifierFieldName()) instanceof FieldMetadata
            || ! $class->getValueGenerationPlan()->containsDeferred()) {
            // if we have a pre insert generator we can't be sure that having an id
            // really means that the entity exists. We have to verify this through
            // the last resort: a db lookup

            // Last try before db lookup: check the identity map.
            if ($this->tryGetById($flatId, $class->getRootClassName())) {
                return self::STATE_DETACHED;
            }

            // db lookup
            if ($this->getEntityPersister($class->getClassName())->exists($entity)) {
                return self::STATE_DETACHED;
            }

            return self::STATE_NEW;
        }

        return self::STATE_DETACHED;
    }

    /**
     * INTERNAL:
     * Removes an entity from the identity map. This effectively detaches the
     * entity from the persistence management of Doctrine.
     *
     * @ignore
     *
     * @param object $entity
     *
     * @return bool
     *
     * @throws ORMInvalidArgumentException
     */
    public function removeFromIdentityMap($entity)
    {
        $oid           = spl_object_id($entity);
        $classMetadata = $this->em->getClassMetadata(get_class($entity));
        $idHash        = implode(' ', $this->entityIdentifiers[$oid]);

        if ($idHash === '') {
            throw ORMInvalidArgumentException::entityHasNoIdentity($entity, 'remove from identity map');
        }

        $className = $classMetadata->getRootClassName();

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
     *
     * @param string $idHash
     * @param string $rootClassName
     *
     * @return object
     */
    public function getByIdHash($idHash, $rootClassName)
    {
        return $this->identityMap[$rootClassName][$idHash];
    }

    /**
     * INTERNAL:
     * Tries to get an entity by its identifier hash. If no entity is found for
     * the given hash, FALSE is returned.
     *
     * @ignore
     *
     * @param mixed  $idHash        (must be possible to cast it to string)
     * @param string $rootClassName
     *
     * @return object|bool The found entity or FALSE.
     */
    public function tryGetByIdHash($idHash, $rootClassName)
    {
        $stringIdHash = (string) $idHash;

        return $this->identityMap[$rootClassName][$stringIdHash] ?? false;
    }

    /**
     * Checks whether an entity is registered in the identity map of this UnitOfWork.
     *
     * @param object $entity
     *
     * @return bool
     */
    public function isInIdentityMap($entity)
    {
        $oid = spl_object_id($entity);

        if (empty($this->entityIdentifiers[$oid])) {
            return false;
        }

        $classMetadata = $this->em->getClassMetadata(get_class($entity));
        $idHash        = implode(' ', $this->entityIdentifiers[$oid]);

        return isset($this->identityMap[$classMetadata->getRootClassName()][$idHash]);
    }

    /**
     * INTERNAL:
     * Checks whether an identifier hash exists in the identity map.
     *
     * @ignore
     *
     * @param string $idHash
     * @param string $rootClassName
     *
     * @return bool
     */
    public function containsIdHash($idHash, $rootClassName)
    {
        return isset($this->identityMap[$rootClassName][$idHash]);
    }

    /**
     * Persists an entity as part of the current unit of work.
     *
     * @param object $entity The entity to persist.
     */
    public function persist($entity)
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
     * @param object   $entity  The entity to persist.
     * @param object[] $visited The already visited entities.
     *
     * @throws ORMInvalidArgumentException
     * @throws UnexpectedValueException
     */
    private function doPersist($entity, array &$visited)
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // Mark visited

        $class = $this->em->getClassMetadata(get_class($entity));

        // We assume NEW, so DETACHED entities result in an exception on flush (constraint violation).
        // If we would detect DETACHED here we would throw an exception anyway with the same
        // consequences (not recoverable/programming error), so just assuming NEW here
        // lets us avoid some database lookups for entities with natural identifiers.
        $entityState = $this->getEntityState($entity, self::STATE_NEW);

        switch ($entityState) {
            case self::STATE_MANAGED:
                // Nothing to do, except if policy is "deferred explicit"
                if ($class->changeTrackingPolicy === ChangeTrackingPolicy::DEFERRED_EXPLICIT) {
                    $this->scheduleForSynchronization($entity);
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
                break;

            case self::STATE_DETACHED:
                // Can actually not happen right now since we assume STATE_NEW.
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'persisted');

            default:
                throw new UnexpectedValueException(
                    sprintf('Unexpected entity state: %d.%s', $entityState, self::objToStr($entity))
                );
        }

        $this->cascadePersist($entity, $visited);
    }

    /**
     * Deletes an entity as part of the current unit of work.
     *
     * @param object $entity The entity to remove.
     */
    public function remove($entity)
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
     * @param object   $entity  The entity to delete.
     * @param object[] $visited The map of the already visited entities.
     *
     * @throws ORMInvalidArgumentException If the instance is a detached entity.
     * @throws UnexpectedValueException
     */
    private function doRemove($entity, array &$visited)
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // mark visited

        // Cascade first, because scheduleForDelete() removes the entity from the identity map, which
        // can cause problems when a lazy proxy has to be initialized for the cascade operation.
        $this->cascadeRemove($entity, $visited);

        $class       = $this->em->getClassMetadata(get_class($entity));
        $entityState = $this->getEntityState($entity);

        switch ($entityState) {
            case self::STATE_NEW:
            case self::STATE_REMOVED:
                // nothing to do
                break;

            case self::STATE_MANAGED:
                $invoke = $this->listenersInvoker->getSubscribedSystems($class, Events::preRemove);

                if ($invoke !== ListenersInvoker::INVOKE_NONE) {
                    $this->listenersInvoker->invoke($class, Events::preRemove, $entity, new LifecycleEventArgs($entity, $this->em), $invoke);
                }

                $this->scheduleForDelete($entity);
                break;

            case self::STATE_DETACHED:
                throw ORMInvalidArgumentException::detachedEntityCannot($entity, 'removed');
            default:
                throw new UnexpectedValueException(
                    sprintf('Unexpected entity state: %d.%s', $entityState, self::objToStr($entity))
                );
        }
    }

    /**
     * Refreshes the state of the given entity from the database, overwriting
     * any local, unpersisted changes.
     *
     * @param object $entity The entity to refresh.
     *
     * @throws InvalidArgumentException If the entity is not MANAGED.
     */
    public function refresh($entity)
    {
        $visited = [];

        $this->doRefresh($entity, $visited);
    }

    /**
     * Executes a refresh operation on an entity.
     *
     * @param object   $entity  The entity to refresh.
     * @param object[] $visited The already visited entities during cascades.
     *
     * @throws ORMInvalidArgumentException If the entity is not MANAGED.
     */
    private function doRefresh($entity, array &$visited)
    {
        $oid = spl_object_id($entity);

        if (isset($visited[$oid])) {
            return; // Prevent infinite recursion
        }

        $visited[$oid] = $entity; // mark visited

        $class = $this->em->getClassMetadata(get_class($entity));

        if ($this->getEntityState($entity) !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        $this->getEntityPersister($class->getClassName())->refresh(
            array_combine($class->getIdentifierFieldNames(), $this->entityIdentifiers[$oid]),
            $entity
        );

        $this->cascadeRefresh($entity, $visited);
    }

    /**
     * Cascades a refresh operation to associated entities.
     *
     * @param object   $entity
     * @param object[] $visited
     */
    private function cascadeRefresh($entity, array &$visited)
    {
        $class = $this->em->getClassMetadata(get_class($entity));

        foreach ($class->getDeclaredPropertiesIterator() as $association) {
            if (! ($association instanceof AssociationMetadata && in_array('refresh', $association->getCascade(), true))) {
                continue;
            }

            $relatedEntities = $association->getValue($entity);

            switch (true) {
                case ($relatedEntities instanceof PersistentCollection):
                    // Unwrap so that foreach() does not initialize
                    $relatedEntities = $relatedEntities->unwrap();
                    // break; is commented intentionally!

                case ($relatedEntities instanceof Collection):
                case (is_array($relatedEntities)):
                    foreach ($relatedEntities as $relatedEntity) {
                        $this->doRefresh($relatedEntity, $visited);
                    }
                    break;

                case ($relatedEntities !== null):
                    $this->doRefresh($relatedEntities, $visited);
                    break;

                default:
                    // Do nothing
            }
        }
    }

    /**
     * Cascades the save operation to associated entities.
     *
     * @param object   $entity
     * @param object[] $visited
     *
     * @throws ORMInvalidArgumentException
     */
    private function cascadePersist($entity, array &$visited)
    {
        $class = $this->em->getClassMetadata(get_class($entity));

        if ($entity instanceof GhostObjectInterface && ! $entity->isProxyInitialized()) {
            // nothing to do - proxy is not initialized, therefore we don't do anything with it
            return;
        }

        foreach ($class->getDeclaredPropertiesIterator() as $association) {
            if (! ($association instanceof AssociationMetadata && in_array('persist', $association->getCascade(), true))) {
                continue;
            }

            /** @var AssociationMetadata $association */
            $relatedEntities = $association->getValue($entity);
            $targetEntity    = $association->getTargetEntity();

            switch (true) {
                case ($relatedEntities instanceof PersistentCollection):
                    // Unwrap so that foreach() does not initialize
                    $relatedEntities = $relatedEntities->unwrap();
                    // break; is commented intentionally!

                case ($relatedEntities instanceof Collection):
                case (is_array($relatedEntities)):
                    if (! ($association instanceof ToManyAssociationMetadata)) {
                        throw ORMInvalidArgumentException::invalidAssociation(
                            $this->em->getClassMetadata($targetEntity),
                            $association,
                            $relatedEntities
                        );
                    }

                    foreach ($relatedEntities as $relatedEntity) {
                        $this->doPersist($relatedEntity, $visited);
                    }

                    break;

                case ($relatedEntities !== null):
                    if (! $relatedEntities instanceof $targetEntity) {
                        throw ORMInvalidArgumentException::invalidAssociation(
                            $this->em->getClassMetadata($targetEntity),
                            $association,
                            $relatedEntities
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
     * @param object   $entity
     * @param object[] $visited
     */
    private function cascadeRemove($entity, array &$visited)
    {
        $entitiesToCascade = [];
        $class             = $this->em->getClassMetadata(get_class($entity));

        foreach ($class->getDeclaredPropertiesIterator() as $association) {
            if (! ($association instanceof AssociationMetadata && in_array('remove', $association->getCascade(), true))) {
                continue;
            }

            if ($entity instanceof GhostObjectInterface && ! $entity->isProxyInitialized()) {
                $entity->initializeProxy();
            }

            $relatedEntities = $association->getValue($entity);

            switch (true) {
                case ($relatedEntities instanceof Collection):
                case (is_array($relatedEntities)):
                    // If its a PersistentCollection initialization is intended! No unwrap!
                    foreach ($relatedEntities as $relatedEntity) {
                        $entitiesToCascade[] = $relatedEntity;
                    }
                    break;

                case ($relatedEntities !== null):
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
     * @param object $entity
     * @param int    $lockMode
     * @param int    $lockVersion
     *
     * @throws ORMInvalidArgumentException
     * @throws TransactionRequiredException
     * @throws OptimisticLockException
     * @throws \InvalidArgumentException
     */
    public function lock($entity, $lockMode, $lockVersion = null)
    {
        if ($entity === null) {
            throw new \InvalidArgumentException('No entity passed to UnitOfWork#lock().');
        }

        if ($this->getEntityState($entity, self::STATE_DETACHED) !== self::STATE_MANAGED) {
            throw ORMInvalidArgumentException::entityNotManaged($entity);
        }

        $class = $this->em->getClassMetadata(get_class($entity));

        switch (true) {
            case $lockMode === LockMode::OPTIMISTIC:
                if (! $class->isVersioned()) {
                    throw OptimisticLockException::notVersioned($class->getClassName());
                }

                if ($lockVersion === null) {
                    return;
                }

                if ($entity instanceof GhostObjectInterface && ! $entity->isProxyInitialized()) {
                    $entity->initializeProxy();
                }

                $entityVersion = $class->versionProperty->getValue($entity);

                if ($entityVersion !== $lockVersion) {
                    throw OptimisticLockException::lockFailedVersionMismatch($entity, $lockVersion, $entityVersion);
                }

                break;

            case $lockMode === LockMode::NONE:
            case $lockMode === LockMode::PESSIMISTIC_READ:
            case $lockMode === LockMode::PESSIMISTIC_WRITE:
                if (! $this->em->getConnection()->isTransactionActive()) {
                    throw TransactionRequiredException::transactionRequired();
                }

                $oid = spl_object_id($entity);

                $this->getEntityPersister($class->getClassName())->lock(
                    array_combine($class->getIdentifierFieldNames(), $this->entityIdentifiers[$oid]),
                    $lockMode
                );
                break;

            default:
                // Do nothing
        }
    }

    /**
     * Clears the UnitOfWork.
     */
    public function clear()
    {
        $this->entityPersisters               =
        $this->collectionPersisters           =
        $this->eagerLoadingEntities           =
        $this->identityMap                    =
        $this->entityIdentifiers              =
        $this->originalEntityData             =
        $this->entityChangeSets               =
        $this->entityStates                   =
        $this->scheduledForSynchronization    =
        $this->entityInsertions               =
        $this->entityUpdates                  =
        $this->entityDeletions                =
        $this->collectionDeletions            =
        $this->collectionUpdates              =
        $this->extraUpdates                   =
        $this->readOnlyObjects                =
        $this->visitedCollections             =
        $this->nonCascadedNewDetectedEntities =
        $this->orphanRemovals                 = [];
    }

    /**
     * INTERNAL:
     * Schedules an orphaned entity for removal. The remove() operation will be
     * invoked on that entity at the beginning of the next commit of this
     * UnitOfWork.
     *
     * @ignore
     *
     * @param object $entity
     */
    public function scheduleOrphanRemoval($entity)
    {
        $this->orphanRemovals[spl_object_id($entity)] = $entity;
    }

    /**
     * INTERNAL:
     * Cancels a previously scheduled orphan removal.
     *
     * @ignore
     *
     * @param object $entity
     */
    public function cancelOrphanRemoval($entity)
    {
        unset($this->orphanRemovals[spl_object_id($entity)]);
    }

    /**
     * INTERNAL:
     * Schedules a complete collection for removal when this UnitOfWork commits.
     */
    public function scheduleCollectionDeletion(PersistentCollection $coll)
    {
        $coid = spl_object_id($coll);

        // TODO: if $coll is already scheduled for recreation ... what to do?
        // Just remove $coll from the scheduled recreations?
        unset($this->collectionUpdates[$coid]);

        $this->collectionDeletions[$coid] = $coll;
    }

    /**
     * @return bool
     */
    public function isCollectionScheduledForDeletion(PersistentCollection $coll)
    {
        return isset($this->collectionDeletions[spl_object_id($coll)]);
    }

    /**
     * INTERNAL:
     * Creates a new instance of the mapped class, without invoking the constructor.
     * This is only meant to be used internally, and should not be consumed by end users.
     *
     * @ignore
     *
     * @return EntityManagerAware|object
     */
    public function newInstance(ClassMetadata $class)
    {
        $entity = $this->instantiator->instantiate($class->getClassName());

        if ($entity instanceof EntityManagerAware) {
            $entity->injectEntityManager($this->em, $class);
        }

        return $entity;
    }

    /**
     * INTERNAL:
     * Creates an entity. Used for reconstitution of persistent entities.
     *
     * {@internal Highly performance-sensitive method. }}
     *
     * @ignore
     *
     * @param string  $className The name of the entity class.
     * @param mixed[] $data      The data for the entity.
     * @param mixed[] $hints     Any hints to account for during reconstitution/lookup of the entity.
     *
     * @return object The managed entity instance.
     *
     * @todo Rename: getOrCreateEntity
     */
    public function createEntity($className, array $data, &$hints = [])
    {
        $class  = $this->em->getClassMetadata($className);
        $id     = $this->em->getIdentifierFlattener()->flattenIdentifier($class, $data);
        $idHash = implode(' ', $id);

        if (isset($this->identityMap[$class->getRootClassName()][$idHash])) {
            $entity = $this->identityMap[$class->getRootClassName()][$idHash];
            $oid    = spl_object_id($entity);

            if (isset($hints[Query::HINT_REFRESH], $hints[Query::HINT_REFRESH_ENTITY])) {
                $unmanagedProxy = $hints[Query::HINT_REFRESH_ENTITY];
                if ($unmanagedProxy !== $entity
                    && $unmanagedProxy instanceof GhostObjectInterface
                    && $this->isIdentifierEquals($unmanagedProxy, $entity)
                ) {
                    // We will hydrate the given un-managed proxy anyway:
                    // continue work, but consider it the entity from now on
                    $entity = $unmanagedProxy;
                }
            }

            if ($entity instanceof GhostObjectInterface && ! $entity->isProxyInitialized()) {
                $entity->setProxyInitializer(null);

                if ($entity instanceof NotifyPropertyChanged) {
                    $entity->addPropertyChangedListener($this);
                }
            } else {
                if (! isset($hints[Query::HINT_REFRESH])
                    || (isset($hints[Query::HINT_REFRESH_ENTITY]) && $hints[Query::HINT_REFRESH_ENTITY] !== $entity)) {
                    return $entity;
                }
            }

            // inject EntityManager upon refresh.
            if ($entity instanceof EntityManagerAware) {
                $entity->injectEntityManager($this->em, $class);
            }

            $this->originalEntityData[$oid] = $data;
        } else {
            $entity = $this->newInstance($class);
            $oid    = spl_object_id($entity);

            $this->entityIdentifiers[$oid]  = $id;
            $this->entityStates[$oid]       = self::STATE_MANAGED;
            $this->originalEntityData[$oid] = $data;

            $this->identityMap[$class->getRootClassName()][$idHash] = $entity;
        }

        if ($entity instanceof NotifyPropertyChanged) {
            $entity->addPropertyChangedListener($this);
        }

        foreach ($data as $field => $value) {
            $property = $class->getProperty($field);

            if ($property instanceof FieldMetadata) {
                $property->setValue($entity, $value);
            }
        }

        // Loading the entity right here, if its in the eager loading map get rid of it there.
        unset($this->eagerLoadingEntities[$class->getRootClassName()][$idHash]);

        if (isset($this->eagerLoadingEntities[$class->getRootClassName()]) && ! $this->eagerLoadingEntities[$class->getRootClassName()]) {
            unset($this->eagerLoadingEntities[$class->getRootClassName()]);
        }

        // Properly initialize any unfetched associations, if partial objects are not allowed.
        if (isset($hints[Query::HINT_FORCE_PARTIAL_LOAD])) {
            return $entity;
        }

        foreach ($class->getDeclaredPropertiesIterator() as $field => $association) {
            if (! ($association instanceof AssociationMetadata)) {
                continue;
            }

            // Check if the association is not among the fetch-joined associations already.
            if (isset($hints['fetchAlias'], $hints['fetched'][$hints['fetchAlias']][$field])) {
                continue;
            }

            $targetEntity = $association->getTargetEntity();
            $targetClass  = $this->em->getClassMetadata($targetEntity);

            if ($association instanceof ToManyAssociationMetadata) {
                // Ignore if its a cached collection
                if (isset($hints[Query::HINT_CACHE_ENABLED]) &&
                    $association->getValue($entity) instanceof PersistentCollection) {
                    continue;
                }

                $hasDataField = isset($data[$field]);

                // use the given collection
                if ($hasDataField && $data[$field] instanceof PersistentCollection) {
                    $data[$field]->setOwner($entity, $association);

                    $association->setValue($entity, $data[$field]);

                    $this->originalEntityData[$oid][$field] = $data[$field];

                    continue;
                }

                // Inject collection
                $pColl = $association->wrap($entity, $hasDataField ? $data[$field] : [], $this->em);

                $pColl->setInitialized($hasDataField);

                $association->setValue($entity, $pColl);

                if ($association->getFetchMode() === FetchMode::EAGER) {
                    $this->loadCollection($pColl);
                    $pColl->takeSnapshot();
                }

                $this->originalEntityData[$oid][$field] = $pColl;

                continue;
            }

            if (! $association->isOwningSide()) {
                // use the given entity association
                if (isset($data[$field]) && is_object($data[$field]) &&
                    isset($this->entityStates[spl_object_id($data[$field])])) {
                    $inverseAssociation = $targetClass->getProperty($association->getMappedBy());

                    $association->setValue($entity, $data[$field]);
                    $inverseAssociation->setValue($data[$field], $entity);

                    $this->originalEntityData[$oid][$field] = $data[$field];

                    continue;
                }

                // Inverse side of x-to-one can never be lazy
                $persister = $this->getEntityPersister($targetEntity);

                $association->setValue($entity, $persister->loadToOneEntity($association, $entity));

                continue;
            }

            // use the entity association
            if (isset($data[$field]) && is_object($data[$field]) && isset($this->entityStates[spl_object_id($data[$field])])) {
                $association->setValue($entity, $data[$field]);

                $this->originalEntityData[$oid][$field] = $data[$field];

                continue;
            }

            $associatedId = [];

            // TODO: Is this even computed right in all cases of composite keys?
            foreach ($association->getJoinColumns() as $joinColumn) {
                /** @var JoinColumnMetadata $joinColumn */
                $joinColumnName  = $joinColumn->getColumnName();
                $joinColumnValue = $data[$joinColumnName] ?? null;
                $targetField     = $targetClass->fieldNames[$joinColumn->getReferencedColumnName()];

                if ($joinColumnValue === null && in_array($targetField, $targetClass->identifier, true)) {
                    // the missing key is part of target's entity primary key
                    $associatedId = [];

                    continue;
                }

                $associatedId[$targetField] = $joinColumnValue;
            }

            if (! $associatedId) {
                // Foreign key is NULL
                $association->setValue($entity, null);
                $this->originalEntityData[$oid][$field] = null;

                continue;
            }

            // @todo guilhermeblanco Can we remove the need of this somehow?
            if (! isset($hints['fetchMode'][$class->getClassName()][$field])) {
                $hints['fetchMode'][$class->getClassName()][$field] = $association->getFetchMode();
            }

            // Foreign key is set
            // Check identity map first
            // FIXME: Can break easily with composite keys if join column values are in
            //        wrong order. The correct order is the one in ClassMetadata#identifier.
            $relatedIdHash = implode(' ', $associatedId);

            switch (true) {
                case (isset($this->identityMap[$targetClass->getRootClassName()][$relatedIdHash])):
                    $newValue = $this->identityMap[$targetClass->getRootClassName()][$relatedIdHash];

                    // If this is an uninitialized proxy, we are deferring eager loads,
                    // this association is marked as eager fetch, and its an uninitialized proxy (wtf!)
                    // then we can append this entity for eager loading!
                    if (! $targetClass->isIdentifierComposite() &&
                        $newValue instanceof GhostObjectInterface &&
                        isset($hints[self::HINT_DEFEREAGERLOAD]) &&
                        $hints['fetchMode'][$class->getClassName()][$field] === FetchMode::EAGER &&
                        ! $newValue->isProxyInitialized()
                    ) {
                        $this->eagerLoadingEntities[$targetClass->getRootClassName()][$relatedIdHash] = current($associatedId);
                    }

                    break;

                case ($targetClass->getSubClasses()):
                    // If it might be a subtype, it can not be lazy. There isn't even
                    // a way to solve this with deferred eager loading, which means putting
                    // an entity with subclasses at a *-to-one location is really bad! (performance-wise)
                    $persister = $this->getEntityPersister($targetEntity);
                    $newValue  = $persister->loadToOneEntity($association, $entity, $associatedId);
                    break;

                default:
                    // Proxies do not carry any kind of original entity data until they're fully loaded/initialized
                    $managedData = [];

                    $normalizedAssociatedId = $this->normalizeIdentifier->__invoke(
                        $this->em,
                        $targetClass,
                        $associatedId
                    );

                    switch (true) {
                        // We are negating the condition here. Other cases will assume it is valid!
                        case ($hints['fetchMode'][$class->getClassName()][$field] !== FetchMode::EAGER):
                            $newValue = $this->em->getProxyFactory()->getProxy($targetClass, $normalizedAssociatedId);
                            break;

                        // Deferred eager load only works for single identifier classes
                        case (isset($hints[self::HINT_DEFEREAGERLOAD]) && ! $targetClass->isIdentifierComposite()):
                            // TODO: Is there a faster approach?
                            $this->eagerLoadingEntities[$targetClass->getRootClassName()][$relatedIdHash] = current($normalizedAssociatedId);

                            $newValue = $this->em->getProxyFactory()->getProxy($targetClass, $normalizedAssociatedId);
                            break;

                        default:
                            // TODO: This is very imperformant, ignore it?
                            $newValue = $this->em->find($targetEntity, $normalizedAssociatedId);
                            // Needed to re-assign original entity data for freshly loaded entity
                            $managedData = $this->originalEntityData[spl_object_id($newValue)];
                            break;
                    }

                    // @TODO using `$associatedId` here seems to be risky.
                    $this->registerManaged($newValue, $associatedId, $managedData);

                    break;
            }

            $this->originalEntityData[$oid][$field] = $newValue;
            $association->setValue($entity, $newValue);

            if ($association->getInversedBy()
                && $association instanceof OneToOneAssociationMetadata
                // @TODO refactor this
                // we don't want to set any values in un-initialized proxies
                && ! (
                    $newValue instanceof GhostObjectInterface
                    && ! $newValue->isProxyInitialized()
                )
            ) {
                $inverseAssociation = $targetClass->getProperty($association->getInversedBy());

                $inverseAssociation->setValue($newValue, $entity);
            }
        }

        // defer invoking of postLoad event to hydration complete step
        $this->hydrationCompleteHandler->deferPostLoadInvoking($class, $entity);

        return $entity;
    }

    public function triggerEagerLoads()
    {
        if (! $this->eagerLoadingEntities) {
            return;
        }

        // avoid infinite recursion
        $eagerLoadingEntities       = $this->eagerLoadingEntities;
        $this->eagerLoadingEntities = [];

        foreach ($eagerLoadingEntities as $entityName => $ids) {
            if (! $ids) {
                continue;
            }

            $class = $this->em->getClassMetadata($entityName);

            $this->getEntityPersister($entityName)->loadAll(
                array_combine($class->identifier, [array_values($ids)])
            );
        }
    }

    /**
     * Initializes (loads) an uninitialized persistent collection of an entity.
     *
     * @param PersistentCollection $collection The collection to initialize.
     *
     * @todo Maybe later move to EntityManager#initialize($proxyOrCollection). See DDC-733.
     */
    public function loadCollection(PersistentCollection $collection)
    {
        $association = $collection->getMapping();
        $persister   = $this->getEntityPersister($association->getTargetEntity());

        if ($association instanceof OneToManyAssociationMetadata) {
            $persister->loadOneToManyCollection($association, $collection->getOwner(), $collection);
        } else {
            $persister->loadManyToManyCollection($association, $collection->getOwner(), $collection);
        }

        $collection->setInitialized(true);
    }

    /**
     * Gets the identity map of the UnitOfWork.
     *
     * @return object[]
     */
    public function getIdentityMap()
    {
        return $this->identityMap;
    }

    /**
     * Gets the original data of an entity. The original data is the data that was
     * present at the time the entity was reconstituted from the database.
     *
     * @param object $entity
     *
     * @return mixed[]
     */
    public function getOriginalEntityData($entity)
    {
        $oid = spl_object_id($entity);

        return $this->originalEntityData[$oid] ?? [];
    }

    /**
     * @ignore
     *
     * @param object  $entity
     * @param mixed[] $data
     */
    public function setOriginalEntityData($entity, array $data)
    {
        $this->originalEntityData[spl_object_id($entity)] = $data;
    }

    /**
     * INTERNAL:
     * Sets a property value of the original data array of an entity.
     *
     * @ignore
     *
     * @param string $oid
     * @param string $property
     * @param mixed  $value
     */
    public function setOriginalEntityProperty($oid, $property, $value)
    {
        $this->originalEntityData[$oid][$property] = $value;
    }

    /**
     * Gets the identifier of an entity.
     * The returned value is always an array of identifier values. If the entity
     * has a composite identifier then the identifier values are in the same
     * order as the identifier field names as returned by ClassMetadata#getIdentifierFieldNames().
     *
     * @param object $entity
     *
     * @return mixed[] The identifier values.
     */
    public function getEntityIdentifier($entity)
    {
        return $this->entityIdentifiers[spl_object_id($entity)];
    }

    /**
     * Processes an entity instance to extract their identifier values.
     *
     * @param object $entity The entity instance.
     *
     * @return mixed A scalar value.
     *
     * @throws ORMInvalidArgumentException
     */
    public function getSingleIdentifierValue($entity)
    {
        $class     = $this->em->getClassMetadata(get_class($entity));
        $persister = $this->getEntityPersister($class->getClassName());

        if ($class->isIdentifierComposite()) {
            throw ORMInvalidArgumentException::invalidCompositeIdentifier();
        }

        $values = $this->isInIdentityMap($entity)
            ? $this->getEntityIdentifier($entity)
            : $persister->getIdentifier($entity);

        return $values[$class->identifier[0]] ?? null;
    }

    /**
     * Tries to find an entity with the given identifier in the identity map of
     * this UnitOfWork.
     *
     * @param mixed|mixed[] $id            The entity identifier to look for.
     * @param string        $rootClassName The name of the root class of the mapped entity hierarchy.
     *
     * @return object|bool Returns the entity with the specified identifier if it exists in
     *                     this UnitOfWork, FALSE otherwise.
     */
    public function tryGetById($id, $rootClassName)
    {
        $idHash = implode(' ', (array) $id);

        return $this->identityMap[$rootClassName][$idHash] ?? false;
    }

    /**
     * Schedules an entity for dirty-checking at commit-time.
     *
     * @param object $entity The entity to schedule for dirty-checking.
     */
    public function scheduleForSynchronization($entity)
    {
        $rootClassName = $this->em->getClassMetadata(get_class($entity))->getRootClassName();

        $this->scheduledForSynchronization[$rootClassName][spl_object_id($entity)] = $entity;
    }

    /**
     * Checks whether the UnitOfWork has any pending insertions.
     *
     * @return bool TRUE if this UnitOfWork has pending insertions, FALSE otherwise.
     */
    public function hasPendingInsertions()
    {
        return ! empty($this->entityInsertions);
    }

    /**
     * Calculates the size of the UnitOfWork. The size of the UnitOfWork is the
     * number of entities in the identity map.
     *
     * @return int
     */
    public function size()
    {
        return array_sum(array_map('count', $this->identityMap));
    }

    /**
     * Gets the EntityPersister for an Entity.
     *
     * @param string $entityName The name of the Entity.
     *
     * @return EntityPersister
     */
    public function getEntityPersister($entityName)
    {
        if (isset($this->entityPersisters[$entityName])) {
            return $this->entityPersisters[$entityName];
        }

        $class = $this->em->getClassMetadata($entityName);

        switch (true) {
            case ($class->inheritanceType === InheritanceType::NONE):
                $persister = new BasicEntityPersister($this->em, $class, $this->mappings);
                break;

            case ($class->inheritanceType === InheritanceType::SINGLE_TABLE):
                $persister = new SingleTablePersister($this->em, $class);
                break;

            case ($class->inheritanceType === InheritanceType::JOINED):
                $persister = new JoinedSubclassPersister($this->em, $class);
                break;

            default:
                throw new \RuntimeException('No persister found for entity.');
        }

        if ($this->hasCache && $class->getCache()) {
            $persister = $this->em->getConfiguration()
                ->getSecondLevelCacheConfiguration()
                ->getCacheFactory()
                ->buildCachedEntityPersister($this->em, $persister, $class);
        }

        $this->entityPersisters[$entityName] = $persister;

        return $this->entityPersisters[$entityName];
    }

    /**
     * Gets a collection persister for a collection-valued association.
     *
     * @return CollectionPersister
     */
    public function getCollectionPersister(ToManyAssociationMetadata $association)
    {
        $role = $association->getCache()
            ? sprintf('%s::%s', $association->getSourceEntity(), $association->getName())
            : get_class($association);

        if (isset($this->collectionPersisters[$role])) {
            return $this->collectionPersisters[$role];
        }

        $persister = $association instanceof OneToManyAssociationMetadata
            ? new OneToManyPersister($this->em)
            : new ManyToManyPersister($this->em);

        if ($this->hasCache && $association->getCache()) {
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
     * @param object  $entity The entity.
     * @param mixed[] $id     Map containing identifier field names as key and its associated values.
     * @param mixed[] $data   The original entity data.
     */
    public function registerManaged($entity, array $id, array $data)
    {
        $isProxy = $entity instanceof GhostObjectInterface && ! $entity->isProxyInitialized();
        $oid     = spl_object_id($entity);

        $this->entityIdentifiers[$oid]  = $id;
        $this->entityStates[$oid]       = self::STATE_MANAGED;
        $this->originalEntityData[$oid] = $data;

        $this->addToIdentityMap($entity);

        if ($entity instanceof NotifyPropertyChanged && ! $isProxy) {
            $entity->addPropertyChangedListener($this);
        }
    }

    /**
     * INTERNAL:
     * Clears the property changeset of the entity with the given OID.
     *
     * @param string $oid The entity's OID.
     */
    public function clearEntityChangeSet($oid)
    {
        unset($this->entityChangeSets[$oid]);
    }

    /* PropertyChangedListener implementation */

    /**
     * Notifies this UnitOfWork of a property change in an entity.
     *
     * @param object $entity       The entity that owns the property.
     * @param string $propertyName The name of the property that changed.
     * @param mixed  $oldValue     The old value of the property.
     * @param mixed  $newValue     The new value of the property.
     */
    public function propertyChanged($entity, $propertyName, $oldValue, $newValue)
    {
        $class = $this->em->getClassMetadata(get_class($entity));

        if (! $class->getProperty($propertyName)) {
            return; // ignore non-persistent fields
        }

        $oid = spl_object_id($entity);

        // Update changeset and mark entity for synchronization
        $this->entityChangeSets[$oid][$propertyName] = [$oldValue, $newValue];

        if (! isset($this->scheduledForSynchronization[$class->getRootClassName()][$oid])) {
            $this->scheduleForSynchronization($entity);
        }
    }

    /**
     * Gets the currently scheduled entity insertions in this UnitOfWork.
     *
     * @return object[]
     */
    public function getScheduledEntityInsertions()
    {
        return $this->entityInsertions;
    }

    /**
     * Gets the currently scheduled entity updates in this UnitOfWork.
     *
     * @return object[]
     */
    public function getScheduledEntityUpdates()
    {
        return $this->entityUpdates;
    }

    /**
     * Gets the currently scheduled entity deletions in this UnitOfWork.
     *
     * @return object[]
     */
    public function getScheduledEntityDeletions()
    {
        return $this->entityDeletions;
    }

    /**
     * Gets the currently scheduled complete collection deletions
     *
     * @return Collection[]|object[][]
     */
    public function getScheduledCollectionDeletions()
    {
        return $this->collectionDeletions;
    }

    /**
     * Gets the currently scheduled collection inserts, updates and deletes.
     *
     * @return Collection[]|object[][]
     */
    public function getScheduledCollectionUpdates()
    {
        return $this->collectionUpdates;
    }

    /**
     * Helper method to initialize a lazy loading proxy or persistent collection.
     *
     * @param object $obj
     */
    public function initializeObject($obj)
    {
        if ($obj instanceof GhostObjectInterface) {
            $obj->initializeProxy();

            return;
        }

        if ($obj instanceof PersistentCollection) {
            $obj->initialize();
        }
    }

    /**
     * Helper method to show an object as string.
     *
     * @param object $obj
     *
     * @return string
     */
    private static function objToStr($obj)
    {
        return method_exists($obj, '__toString') ? (string) $obj : get_class($obj) . '@' . spl_object_id($obj);
    }

    /**
     * Marks an entity as read-only so that it will not be considered for updates during UnitOfWork#commit().
     *
     * This operation cannot be undone as some parts of the UnitOfWork now keep gathering information
     * on this object that might be necessary to perform a correct update.
     *
     * @param object $object
     *
     * @throws ORMInvalidArgumentException
     */
    public function markReadOnly($object)
    {
        if (! is_object($object) || ! $this->isInIdentityMap($object)) {
            throw ORMInvalidArgumentException::readOnlyRequiresManagedEntity($object);
        }

        $this->readOnlyObjects[spl_object_id($object)] = true;
    }

    /**
     * Is this entity read only?
     *
     * @param object $object
     *
     * @return bool
     *
     * @throws ORMInvalidArgumentException
     */
    public function isReadOnly($object)
    {
        if (! is_object($object)) {
            throw ORMInvalidArgumentException::readOnlyRequiresManagedEntity($object);
        }

        return isset($this->readOnlyObjects[spl_object_id($object)]);
    }

    /**
     * Perform whatever processing is encapsulated here after completion of the transaction.
     */
    private function afterTransactionComplete()
    {
        $this->performCallbackOnCachedPersister(function (CachedPersister $persister) {
            $persister->afterTransactionComplete();
        });
    }

    /**
     * Perform whatever processing is encapsulated here after completion of the rolled-back.
     */
    private function afterTransactionRolledBack()
    {
        $this->performCallbackOnCachedPersister(function (CachedPersister $persister) {
            $persister->afterTransactionRolledBack();
        });
    }

    /**
     * Performs an action after the transaction.
     */
    private function performCallbackOnCachedPersister(callable $callback)
    {
        if (! $this->hasCache) {
            return;
        }

        foreach (array_merge($this->entityPersisters, $this->collectionPersisters) as $persister) {
            if ($persister instanceof CachedPersister) {
                $callback($persister);
            }
        }
    }

    private function dispatchOnFlushEvent()
    {
        if ($this->eventManager->hasListeners(Events::onFlush)) {
            $this->eventManager->dispatchEvent(Events::onFlush, new OnFlushEventArgs($this->em));
        }
    }

    private function dispatchPostFlushEvent()
    {
        if ($this->eventManager->hasListeners(Events::postFlush)) {
            $this->eventManager->dispatchEvent(Events::postFlush, new PostFlushEventArgs($this->em));
        }
    }

    /**
     * Verifies if two given entities actually are the same based on identifier comparison
     *
     * @param object $entity1
     * @param object $entity2
     *
     * @return bool
     */
    private function isIdentifierEquals($entity1, $entity2)
    {
        if ($entity1 === $entity2) {
            return true;
        }

        $class     = $this->em->getClassMetadata(get_class($entity1));
        $persister = $this->getEntityPersister($class->getClassName());

        if ($class !== $this->em->getClassMetadata(get_class($entity2))) {
            return false;
        }

        $identifierFlattener = $this->em->getIdentifierFlattener();

        $oid1 = spl_object_id($entity1);
        $oid2 = spl_object_id($entity2);

        $id1 = $this->entityIdentifiers[$oid1]
            ?? $identifierFlattener->flattenIdentifier($class, $persister->getIdentifier($entity1));
        $id2 = $this->entityIdentifiers[$oid2]
            ?? $identifierFlattener->flattenIdentifier($class, $persister->getIdentifier($entity2));

        return $id1 === $id2 || implode(' ', $id1) === implode(' ', $id2);
    }

    /**
     * @throws ORMInvalidArgumentException
     */
    private function assertThatThereAreNoUnintentionallyNonPersistedAssociations() : void
    {
        $entitiesNeedingCascadePersist = array_diff_key($this->nonCascadedNewDetectedEntities, $this->entityInsertions);

        $this->nonCascadedNewDetectedEntities = [];

        if ($entitiesNeedingCascadePersist) {
            throw ORMInvalidArgumentException::newEntitiesFoundThroughRelationships(
                array_values($entitiesNeedingCascadePersist)
            );
        }
    }

    /**
     * This method called by hydrators, and indicates that hydrator totally completed current hydration cycle.
     * Unit of work able to fire deferred events, related to loading events here.
     *
     * @internal should be called internally from object hydrators
     */
    public function hydrationComplete()
    {
        $this->hydrationCompleteHandler->hydrationComplete();
    }
}
