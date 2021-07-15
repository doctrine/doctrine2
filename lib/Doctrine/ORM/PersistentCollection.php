<?php

namespace Doctrine\ORM;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping\ClassMetadata;
use RuntimeException;

use function array_combine;
use function array_diff_key;
use function array_map;
use function array_udiff_assoc;
use function array_values;
use function array_walk;
use function get_class;
use function is_object;
use function spl_object_hash;

/**
 * A PersistentCollection represents a collection of elements that have persistent state.
 *
 * Collections of entities represent only the associations (links) to those entities.
 * That means, if the collection is part of a many-many mapping and you remove
 * entities from the collection, only the links in the relation table are removed (on flush).
 * Similarly, if you remove entities from a collection that is part of a one-many
 * mapping this will only result in the nulling out of the foreign keys on flush.
 *
 * @phpstan-template TKey
 * @psalm-template TKey of array-key
 * @psalm-template T
 * @template-implements Collection<TKey,T>
 */
final class PersistentCollection extends AbstractLazyCollection implements Selectable
{
    /**
     * A snapshot of the collection at the moment it was fetched from the database.
     * This is used to create a diff of the collection at commit time.
     *
     * @psalm-var array<string|int, mixed>
     */
    private $snapshot = [];

    /**
     * The entity that owns this collection.
     *
     * @var object|null
     */
    private $owner;

    /**
     * The association mapping the collection belongs to.
     * This is currently either a OneToManyMapping or a ManyToManyMapping.
     *
     * @psalm-var array<string, mixed>|null
     */
    private $association;

    /**
     * The EntityManager that manages the persistence of the collection.
     *
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * The name of the field on the target entities that points to the owner
     * of the collection. This is only set if the association is bi-directional.
     *
     * @var string
     */
    private $backRefFieldName;

    /**
     * The class descriptor of the collection's entity type.
     *
     * @var ClassMetadata
     */
    private $typeClass;

    /**
     * Whether the collection is dirty and needs to be synchronized with the database
     * when the UnitOfWork that manages its persistent state commits.
     *
     * @var bool
     */
    private $isDirty = false;

    /**
     * Creates a new persistent collection.
     *
     * @param EntityManagerInterface $em    The EntityManager the collection will be associated with.
     * @param ClassMetadata          $class The class descriptor of the entity type of this collection.
     * @psalm-param Collection<TKey, T> $collection The collection elements.
     */
    public function __construct(EntityManagerInterface $em, $class, Collection $collection)
    {
        $this->collection  = $collection;
        $this->em          = $em;
        $this->typeClass   = $class;
        $this->initialized = true;
    }

    /**
     * INTERNAL:
     * Sets the collection's owning entity together with the AssociationMapping that
     * describes the association between the owner and the elements of the collection.
     *
     * @param object $entity
     * @psalm-param array<string, mixed> $assoc
     */
    public function setOwner($entity, array $assoc): void
    {
        $this->owner            = $entity;
        $this->association      = $assoc;
        $this->backRefFieldName = $assoc['inversedBy'] ?: $assoc['mappedBy'];
    }

    /**
     * INTERNAL:
     * Gets the collection owner.
     *
     * @return object|null
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * @return Mapping\ClassMetadata
     */
    public function getTypeClass(): Mapping\ClassMetadataInfo
    {
        return $this->typeClass;
    }

    /**
     * INTERNAL:
     * Adds an element to a collection during hydration. This will automatically
     * complete bidirectional associations in the case of a one-to-many association.
     *
     * @param mixed $element The element to add.
     */
    public function hydrateAdd($element): void
    {
        $this->collection->add($element);

        // If _backRefFieldName is set and its a one-to-many association,
        // we need to set the back reference.
        if ($this->backRefFieldName && $this->association['type'] === ClassMetadata::ONE_TO_MANY) {
            // Set back reference to owner
            $this->typeClass->reflFields[$this->backRefFieldName]->setValue(
                $element,
                $this->owner
            );

            $this->em->getUnitOfWork()->setOriginalEntityProperty(
                spl_object_hash($element),
                $this->backRefFieldName,
                $this->owner
            );
        }
    }

    /**
     * INTERNAL:
     * Sets a keyed element in the collection during hydration.
     *
     * @param mixed $key     The key to set.
     * @param mixed $element The element to set.
     */
    public function hydrateSet($key, $element): void
    {
        $this->collection->set($key, $element);

        // If _backRefFieldName is set, then the association is bidirectional
        // and we need to set the back reference.
        if ($this->backRefFieldName && $this->association['type'] === ClassMetadata::ONE_TO_MANY) {
            // Set back reference to owner
            $this->typeClass->reflFields[$this->backRefFieldName]->setValue(
                $element,
                $this->owner
            );
        }
    }

    /**
     * Initializes the collection by loading its contents from the database
     * if the collection is not yet initialized.
     */
    public function initialize(): void
    {
        if ($this->initialized || ! $this->association) {
            return;
        }

        $this->doInitialize();

        $this->initialized = true;
    }

    /**
     * INTERNAL:
     * Tells this collection to take a snapshot of its current state.
     */
    public function takeSnapshot(): void
    {
        $this->snapshot = $this->collection->toArray();
        $this->isDirty  = false;
    }

    /**
     * INTERNAL:
     * Returns the last snapshot of the elements in the collection.
     *
     * @psalm-return array<string|int, mixed> The last snapshot of the elements.
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * INTERNAL:
     * getDeleteDiff
     *
     * @return mixed[]
     */
    public function getDeleteDiff(): array
    {
        $collectionItems = $this->collection->toArray();

        return array_values(array_diff_key(
            array_combine(array_map('spl_object_id', $this->snapshot), $this->snapshot),
            array_combine(array_map('spl_object_id', $collectionItems), $collectionItems)
        ));
    }

    /**
     * INTERNAL:
     * getInsertDiff
     *
     * @return mixed[]
     */
    public function getInsertDiff(): array
    {
        $collectionItems = $this->collection->toArray();

        return array_values(array_diff_key(
            array_combine(array_map('spl_object_id', $collectionItems), $collectionItems),
            array_combine(array_map('spl_object_id', $this->snapshot), $this->snapshot)
        ));
    }

    /**
     * INTERNAL: Gets the association mapping of the collection.
     *
     * @psalm-return array<string, mixed>|null
     */
    public function getMapping(): ?array
    {
        return $this->association;
    }

    /**
     * Marks this collection as changed/dirty.
     */
    private function changed(): void
    {
        if ($this->isDirty) {
            return;
        }

        $this->isDirty = true;

        if (
            $this->association !== null &&
            $this->association['isOwningSide'] &&
            $this->association['type'] === ClassMetadata::MANY_TO_MANY &&
            $this->owner &&
            $this->em->getClassMetadata(get_class($this->owner))->isChangeTrackingNotify()
        ) {
            $this->em->getUnitOfWork()->scheduleForDirtyCheck($this->owner);
        }
    }

    /**
     * Gets a boolean flag indicating whether this collection is dirty which means
     * its state needs to be synchronized with the database.
     *
     * @return bool TRUE if the collection is dirty, FALSE otherwise.
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Sets a boolean flag, indicating whether this collection is dirty.
     *
     * @param bool $dirty Whether the collection should be marked dirty or not.
     */
    public function setDirty($dirty): void
    {
        $this->isDirty = $dirty;
    }

    /**
     * Sets the initialized flag of the collection, forcing it into that state.
     *
     * @param bool $bool
     */
    public function setInitialized($bool): void
    {
        $this->initialized = $bool;
    }

    /**
     * {@inheritdoc}
     *
     * @return object
     */
    public function remove($key)
    {
        // TODO: If the keys are persistent as well (not yet implemented)
        //       and the collection is not initialized and orphanRemoval is
        //       not used we can issue a straight SQL delete/update on the
        //       association (table). Without initializing the collection.
        $removed = parent::remove($key);

        if (! $removed) {
            return $removed;
        }

        $this->changed();

        if (
            $this->association !== null &&
            $this->association['type'] & ClassMetadata::TO_MANY &&
            $this->owner &&
            $this->association['orphanRemoval']
        ) {
            $this->em->getUnitOfWork()->scheduleOrphanRemoval($removed);
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function removeElement($element): bool
    {
        $removed = parent::removeElement($element);

        if (! $removed) {
            return $removed;
        }

        $this->changed();

        if (
            $this->association !== null &&
            $this->association['type'] & ClassMetadata::TO_MANY &&
            $this->owner &&
            $this->association['orphanRemoval']
        ) {
            $this->em->getUnitOfWork()->scheduleOrphanRemoval($element);
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function containsKey($key): bool
    {
        if (
            ! $this->initialized && $this->association['fetch'] === ClassMetadata::FETCH_EXTRA_LAZY
            && isset($this->association['indexBy'])
        ) {
            $persister = $this->em->getUnitOfWork()->getCollectionPersister($this->association);

            return $this->collection->containsKey($key) || $persister->containsKey($this, $key);
        }

        return parent::containsKey($key);
    }

    /**
     * {@inheritdoc}
     */
    public function contains($element): bool
    {
        if (! $this->initialized && $this->association['fetch'] === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->em->getUnitOfWork()->getCollectionPersister($this->association);

            return $this->collection->contains($element) || $persister->contains($this, $element);
        }

        return parent::contains($element);
    }

    /**
     * {@inheritdoc}
     */
    public function get($key)
    {
        if (
            ! $this->initialized
            && $this->association['fetch'] === ClassMetadata::FETCH_EXTRA_LAZY
            && isset($this->association['indexBy'])
        ) {
            if (! $this->typeClass->isIdentifierComposite && $this->typeClass->isIdentifier($this->association['indexBy'])) {
                return $this->em->find($this->typeClass->name, $key);
            }

            return $this->em->getUnitOfWork()->getCollectionPersister($this->association)->get($this, $key);
        }

        return parent::get($key);
    }

    public function count(): int
    {
        if (! $this->initialized && $this->association !== null && $this->association['fetch'] === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->em->getUnitOfWork()->getCollectionPersister($this->association);

            return $persister->count($this) + ($this->isDirty ? $this->collection->count() : 0);
        }

        return parent::count();
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value): void
    {
        parent::set($key, $value);

        $this->changed();

        if (is_object($value) && $this->em) {
            $this->em->getUnitOfWork()->cancelOrphanRemoval($value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($value): bool
    {
        $this->collection->add($value);

        $this->changed();

        if (is_object($value) && $this->em) {
            $this->em->getUnitOfWork()->cancelOrphanRemoval($value);
        }

        return true;
    }

    /* ArrayAccess implementation */

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset): bool
    {
        return $this->containsKey($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $value): void
    {
        if (! isset($offset)) {
            $this->add($value);

            return;
        }

        $this->set($offset, $value);
    }

    /**
     * {@inheritdoc}
     *
     * @return object|null
     */
    public function offsetUnset($offset)
    {
        return $this->remove($offset);
    }

    public function isEmpty(): bool
    {
        return $this->collection->isEmpty() && $this->count() === 0;
    }

    public function clear(): void
    {
        if ($this->initialized && $this->isEmpty()) {
            $this->collection->clear();

            return;
        }

        $uow = $this->em->getUnitOfWork();

        if (
            $this->association['type'] & ClassMetadata::TO_MANY &&
            $this->association['orphanRemoval'] &&
            $this->owner
        ) {
            // we need to initialize here, as orphan removal acts like implicit cascadeRemove,
            // hence for event listeners we need the objects in memory.
            $this->initialize();

            foreach ($this->collection as $element) {
                $uow->scheduleOrphanRemoval($element);
            }
        }

        $this->collection->clear();

        $this->initialized = true; // direct call, {@link initialize()} is too expensive

        if ($this->association['isOwningSide'] && $this->owner) {
            $this->changed();

            $uow->scheduleCollectionDeletion($this);

            $this->takeSnapshot();
        }
    }

    /**
     * Called by PHP when this collection is serialized. Ensures that only the
     * elements are properly serialized.
     *
     * Internal note: Tried to implement Serializable first but that did not work well
     *                with circular references. This solution seems simpler and works well.
     *
     * @return string[]
     * @psalm-return array{0: string, 1: string}
     */
    public function __sleep(): array
    {
        return ['collection', 'initialized'];
    }

    /**
     * Extracts a slice of $length elements starting at position $offset from the Collection.
     *
     * If $length is null it returns all elements from $offset to the end of the Collection.
     * Keys have to be preserved by this method. Calling this method will only return the
     * selected slice and NOT change the elements contained in the collection slice is called on.
     *
     * @param int      $offset
     * @param int|null $length
     *
     * @return mixed[]
     * @psalm-return array<TKey,T>
     */
    public function slice($offset, $length = null): array
    {
        if (! $this->initialized && ! $this->isDirty && $this->association['fetch'] === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->em->getUnitOfWork()->getCollectionPersister($this->association);

            return $persister->slice($this, $offset, $length);
        }

        return parent::slice($offset, $length);
    }

    /**
     * Cleans up internal state of cloned persistent collection.
     *
     * The following problems have to be prevented:
     * 1. Added entities are added to old PC
     * 2. New collection is not dirty, if reused on other entity nothing
     * changes.
     * 3. Snapshot leads to invalid diffs being generated.
     * 4. Lazy loading grabs entities from old owner object.
     * 5. New collection is connected to old owner and leads to duplicate keys.
     */
    public function __clone()
    {
        if (is_object($this->collection)) {
            $this->collection = clone $this->collection;
        }

        $this->initialize();

        $this->owner    = null;
        $this->snapshot = [];

        $this->changed();
    }

    /**
     * Selects all elements from a selectable that match the expression and
     * return a new collection containing these elements.
     *
     * @psalm-return Collection<TKey, T>
     *
     * @throws RuntimeException
     */
    public function matching(Criteria $criteria): Collection
    {
        if ($this->isDirty) {
            $this->initialize();
        }

        if ($this->initialized) {
            return $this->collection->matching($criteria);
        }

        if ($this->association['type'] === ClassMetadata::MANY_TO_MANY) {
            $persister = $this->em->getUnitOfWork()->getCollectionPersister($this->association);

            return new ArrayCollection($persister->loadCriteria($this, $criteria));
        }

        $builder         = Criteria::expr();
        $ownerExpression = $builder->eq($this->backRefFieldName, $this->owner);
        $expression      = $criteria->getWhereExpression();
        $expression      = $expression ? $builder->andX($expression, $ownerExpression) : $ownerExpression;

        $criteria = clone $criteria;
        $criteria->where($expression);
        $criteria->orderBy($criteria->getOrderings() ?: $this->association['orderBy'] ?? []);

        $persister = $this->em->getUnitOfWork()->getEntityPersister($this->association['targetEntity']);

        return $this->association['fetch'] === ClassMetadata::FETCH_EXTRA_LAZY
            ? new LazyCriteriaCollection($persister, $criteria)
            : new ArrayCollection($persister->loadCriteria($criteria));
    }

    /**
     * Retrieves the wrapped Collection instance.
     *
     * @return Collection<TKey, T>
     */
    public function unwrap(): Collection
    {
        return $this->collection;
    }

    protected function doInitialize(): void
    {
        // Has NEW objects added through add(). Remember them.
        $newlyAddedDirtyObjects = [];

        if ($this->isDirty) {
            $newlyAddedDirtyObjects = $this->collection->toArray();
        }

        $this->collection->clear();
        $this->em->getUnitOfWork()->loadCollection($this);
        $this->takeSnapshot();

        if ($newlyAddedDirtyObjects) {
            $this->restoreNewObjectsInDirtyCollection($newlyAddedDirtyObjects);
        }
    }

    /**
     * @param object[] $newObjects
     *
     * Note: the only reason why this entire looping/complexity is performed via `spl_object_hash`
     *       is because we want to prevent using `array_udiff()`, which is likely to cause very
     *       high overhead (complexity of O(n^2)). `array_diff_key()` performs the operation in
     *       core, which is faster than using a callback for comparisons
     */
    private function restoreNewObjectsInDirtyCollection(array $newObjects): void
    {
        $loadedObjects               = $this->collection->toArray();
        $newObjectsByOid             = array_combine(array_map('spl_object_hash', $newObjects), $newObjects);
        $loadedObjectsByOid          = array_combine(array_map('spl_object_hash', $loadedObjects), $loadedObjects);
        $newObjectsThatWereNotLoaded = array_diff_key($newObjectsByOid, $loadedObjectsByOid);

        if ($newObjectsThatWereNotLoaded) {
            // Reattach NEW objects added through add(), if any.
            array_walk($newObjectsThatWereNotLoaded, [$this->collection, 'add']);

            $this->isDirty = true;
        }
    }
}
