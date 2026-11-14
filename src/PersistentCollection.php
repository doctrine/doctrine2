<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use Doctrine\Common\Collections\AbstractLazyCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToManyAssociationMapping;
use Override;
use RuntimeException;
use UnexpectedValueException;

use function array_combine;
use function array_diff_key;
use function array_map;
use function array_values;
use function array_walk;
use function assert;
use function is_object;
use function spl_object_id;

/**
 * A PersistentCollection represents a collection of elements that have persistent state.
 *
 * Collections of entities represent only the associations (links) to those entities.
 * That means, if the collection is part of a many-many mapping and you remove
 * entities from the collection, only the links in the relation table are removed (on flush).
 * Similarly, if you remove entities from a collection that is part of a one-many
 * mapping this will only result in the nulling out of the foreign keys on flush.
 *
 * @phpstan-template TKey of array-key
 * @phpstan-template T
 * @template-extends AbstractLazyCollection<TKey,T>
 * @template-implements Selectable<TKey,T>
 */
final class PersistentCollection extends AbstractLazyCollection implements Selectable
{
    /**
     * A snapshot of the collection at the moment it was fetched from the database.
     * This is used to create a diff of the collection at commit time.
     *
     * @phpstan-var array<string|int, mixed>
     */
    private array $snapshot = [];

    /**
     * The entity that owns this collection.
     */
    private object|null $owner = null;

    /**
     * The association mapping the collection belongs to.
     * This is currently either a OneToManyMapping or a ManyToManyMapping.
     *
     * @var (AssociationMapping&ToManyAssociationMapping)|null
     */
    private AssociationMapping|null $association = null;

    /**
     * The name of the field on the target entities that points to the owner
     * of the collection. This is only set if the association is bi-directional.
     */
    private string|null $backRefFieldName = null;

    /**
     * Whether the collection is dirty and needs to be synchronized with the database
     * when the UnitOfWork that manages its persistent state commits.
     */
    private bool $isDirty = false;

    /**
     * Creates a new persistent collection.
     *
     * @param EntityManagerInterface $em        The EntityManager the collection will be associated with.
     * @param ClassMetadata          $typeClass The class descriptor of the entity type of this collection.
     * @phpstan-param Collection<TKey, T>&Selectable<TKey, T> $collection The collection elements.
     */
    public function __construct(
        private EntityManagerInterface|null $em,
        private readonly ClassMetadata|null $typeClass,
        Collection $collection,
    ) {
        $this->collection  = $collection;
        $this->initialized = true;
    }

    /**
     * INTERNAL:
     * Sets the collection's owning entity together with the AssociationMapping that
     * describes the association between the owner and the elements of the collection.
     */
    public function setOwner(object $entity, AssociationMapping&ToManyAssociationMapping $assoc): void
    {
        $this->owner            = $entity;
        $this->association      = $assoc;
        $this->backRefFieldName = $assoc->isOwningSide() ? $assoc->inversedBy : $assoc->mappedBy;
    }

    /**
     * INTERNAL:
     * Gets the collection owner.
     */
    public function getOwner(): object|null
    {
        return $this->owner;
    }

    public function getTypeClass(): ClassMetadata
    {
        assert($this->typeClass !== null);

        return $this->typeClass;
    }

    private function getUnitOfWork(): UnitOfWork
    {
        assert($this->em !== null);

        return $this->em->getUnitOfWork();
    }

    /**
     * INTERNAL:
     * Adds an element to a collection during hydration. This will automatically
     * complete bidirectional associations in the case of a one-to-many association.
     */
    public function hydrateAdd(mixed $element): void
    {
        $this->unwrap()->add($element);

        // If _backRefFieldName is set and its a one-to-many association,
        // we need to set the back reference.
        if ($this->backRefFieldName && $this->getMapping()->isOneToMany()) {
            assert($this->typeClass !== null);
            // Set back reference to owner
            $this->typeClass->propertyAccessors[$this->backRefFieldName]->setValue(
                $element,
                $this->owner,
            );

            $this->getUnitOfWork()->setOriginalEntityProperty(
                spl_object_id($element),
                $this->backRefFieldName,
                $this->owner,
            );
        }
    }

    /**
     * INTERNAL:
     * Sets a keyed element in the collection during hydration.
     */
    public function hydrateSet(mixed $key, mixed $element): void
    {
        $this->unwrap()->set($key, $element);

        // If _backRefFieldName is set, then the association is bidirectional
        // and we need to set the back reference.
        if ($this->backRefFieldName && $this->getMapping()->isOneToMany()) {
            assert($this->typeClass !== null);
            // Set back reference to owner
            $this->typeClass->propertyAccessors[$this->backRefFieldName]->setValue(
                $element,
                $this->owner,
            );
        }
    }

    /**
     * Initializes the collection by loading its contents from the database
     * if the collection is not yet initialized.
     */
    #[Override]
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
        $this->snapshot = $this->unwrap()->toArray();
        $this->isDirty  = false;
    }

    /**
     * INTERNAL:
     * Returns the last snapshot of the elements in the collection.
     *
     * @phpstan-return array<string|int, mixed> The last snapshot of the elements.
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
        $collectionItems = $this->unwrap()->toArray();

        return array_values(array_diff_key(
            array_combine(array_map('spl_object_id', $this->snapshot), $this->snapshot),
            array_combine(array_map('spl_object_id', $collectionItems), $collectionItems),
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
        $collectionItems = $this->unwrap()->toArray();

        return array_values(array_diff_key(
            array_combine(array_map('spl_object_id', $collectionItems), $collectionItems),
            array_combine(array_map('spl_object_id', $this->snapshot), $this->snapshot),
        ));
    }

    /** INTERNAL: Gets the association mapping of the collection. */
    public function getMapping(): AssociationMapping&ToManyAssociationMapping
    {
        if ($this->association === null) {
            throw new UnexpectedValueException('The underlying association mapping is null although it should not be');
        }

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
    }

    /**
     * Gets a boolean flag indicating whether this collection is dirty which means
     * its state needs to be synchronized with the database.
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Sets a boolean flag, indicating whether this collection is dirty.
     */
    public function setDirty(bool $dirty): void
    {
        $this->isDirty = $dirty;
    }

    /**
     * Sets the initialized flag of the collection, forcing it into that state.
     */
    public function setInitialized(bool $bool): void
    {
        $this->initialized = $bool;
    }

    #[Override]
    public function remove(string|int $key): mixed
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
            $this->association->isToMany() &&
            $this->owner &&
            $this->getMapping()->orphanRemoval
        ) {
            $this->getUnitOfWork()->scheduleOrphanRemoval($removed);
        }

        return $removed;
    }

    #[Override]
    public function removeElement(mixed $element): bool
    {
        $removed = parent::removeElement($element);

        if (! $removed) {
            return $removed;
        }

        $this->changed();

        if (
            $this->association !== null &&
            $this->association->isToMany() &&
            $this->owner &&
            $this->getMapping()->orphanRemoval
        ) {
            $this->getUnitOfWork()->scheduleOrphanRemoval($element);
        }

        return $removed;
    }

    #[Override]
    public function containsKey(mixed $key): bool
    {
        if (
            ! $this->initialized && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY
            && isset($this->getMapping()->indexBy)
        ) {
            $persister = $this->getUnitOfWork()->getCollectionPersister($this->getMapping());

            return $this->unwrap()->containsKey($key) || $persister->containsKey($this, $key);
        }

        return parent::containsKey($key);
    }

    #[Override]
    public function contains(mixed $element): bool
    {
        if (! $this->initialized && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->getUnitOfWork()->getCollectionPersister($this->getMapping());

            return $this->unwrap()->contains($element) || $persister->contains($this, $element);
        }

        return parent::contains($element);
    }

    #[Override]
    public function get(string|int $key): mixed
    {
        if (
            ! $this->initialized
            && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY
            && isset($this->getMapping()->indexBy)
        ) {
            assert($this->em !== null);
            assert($this->typeClass !== null);
            if (! $this->typeClass->isIdentifierComposite && $this->typeClass->isIdentifier($this->getMapping()->indexBy)) {
                return $this->em->find($this->typeClass->name, $key);
            }

            return $this->getUnitOfWork()->getCollectionPersister($this->getMapping())->get($this, $key);
        }

        return parent::get($key);
    }

    #[Override]
    public function count(): int
    {
        if (! $this->initialized && $this->association !== null && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->getUnitOfWork()->getCollectionPersister($this->association);

            return $persister->count($this) + ($this->isDirty ? $this->unwrap()->count() : 0);
        }

        return parent::count();
    }

    #[Override]
    public function set(string|int $key, mixed $value): void
    {
        parent::set($key, $value);

        $this->changed();

        if (is_object($value) && $this->em) {
            $this->getUnitOfWork()->cancelOrphanRemoval($value);
        }
    }

    #[Override]
    public function add(mixed $value): void
    {
        $this->unwrap()->add($value);

        $this->changed();

        if (is_object($value) && $this->em) {
            $this->getUnitOfWork()->cancelOrphanRemoval($value);
        }
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->containsKey($offset);
    }

    #[Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! isset($offset)) {
            $this->add($value);

            return;
        }

        $this->set($offset, $value);
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    #[Override]
    public function isEmpty(): bool
    {
        return $this->unwrap()->isEmpty() && $this->count() === 0;
    }

    #[Override]
    public function clear(): void
    {
        if ($this->initialized && $this->isEmpty()) {
            $this->unwrap()->clear();

            return;
        }

        $uow         = $this->getUnitOfWork();
        $association = $this->getMapping();

        if (
            $association->isToMany() &&
            $association->orphanRemoval &&
            $this->owner
        ) {
            // we need to initialize here, as orphan removal acts like implicit cascadeRemove,
            // hence for event listeners we need the objects in memory.
            $this->initialize();

            foreach ($this->unwrap() as $element) {
                $uow->scheduleOrphanRemoval($element);
            }
        }

        $this->unwrap()->clear();

        $this->initialized = true; // direct call, {@link initialize()} is too expensive

        if ($association->isOwningSide() && $this->owner) {
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
     * @phpstan-return array{0: string, 1: string}
     */
    public function __sleep(): array
    {
        return ['collection', 'initialized'];
    }

    public function __wakeup(): void
    {
        $this->em = null;
    }

    #[Override]
    public function first(): mixed
    {
        if (! $this->initialized && ! $this->isDirty && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->getUnitOfWork()->getCollectionPersister($this->getMapping());

            return array_values($persister->slice($this, 0, 1))[0] ?? false;
        }

        return parent::first();
    }

    /**
     * Extracts a slice of $length elements starting at position $offset from the Collection.
     *
     * If $length is null it returns all elements from $offset to the end of the Collection.
     * Keys have to be preserved by this method. Calling this method will only return the
     * selected slice and NOT change the elements contained in the collection slice is called on.
     *
     * @return mixed[]
     * @phpstan-return array<TKey,T>
     */
    #[Override]
    public function slice(int $offset, int|null $length = null): array
    {
        if (! $this->initialized && ! $this->isDirty && $this->getMapping()->fetch === ClassMetadata::FETCH_EXTRA_LAZY) {
            $persister = $this->getUnitOfWork()->getCollectionPersister($this->getMapping());

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
     * @phpstan-return Collection<TKey, T>
     *
     * @throws RuntimeException
     */
    #[Override]
    public function matching(Criteria $criteria): Collection
    {
        if ($this->isDirty) {
            $this->initialize();
        }

        if ($this->initialized) {
            return $this->unwrap()->matching($criteria);
        }

        $association = $this->getMapping();
        if ($association->isManyToMany()) {
            $persister = $this->getUnitOfWork()->getCollectionPersister($association);

            return new ArrayCollection($persister->loadCriteria($this, $criteria));
        }

        $builder         = Criteria::expr();
        $ownerExpression = $builder->eq($this->backRefFieldName, $this->owner);
        $expression      = $criteria->getWhereExpression();
        $expression      = $expression ? $builder->andX($expression, $ownerExpression) : $ownerExpression;

        $criteria = clone $criteria;
        $criteria->where($expression);

        $criteria->orderBy($criteria->getOrderings() ?: $association->orderBy());

        $persister = $this->getUnitOfWork()->getEntityPersister($association->targetEntity);

        return $association->fetch === ClassMetadata::FETCH_EXTRA_LAZY
            ? new LazyCriteriaCollection($persister, $criteria)
            : new ArrayCollection($persister->loadCriteria($criteria));
    }

    /**
     * Retrieves the wrapped Collection instance.
     *
     * @return Collection<TKey, T>&Selectable<TKey, T>
     */
    public function unwrap(): Selectable&Collection
    {
        assert($this->collection instanceof Collection);

        return $this->collection;
    }

    #[Override]
    protected function doInitialize(): void
    {
        // Has NEW objects added through add(). Remember them.
        $newlyAddedDirtyObjects = [];

        if ($this->isDirty) {
            $newlyAddedDirtyObjects = $this->unwrap()->toArray();
        }

        $this->unwrap()->clear();
        $this->getUnitOfWork()->loadCollection($this);
        $this->takeSnapshot();

        if ($newlyAddedDirtyObjects) {
            $this->restoreNewObjectsInDirtyCollection($newlyAddedDirtyObjects);
        }
    }

    /**
     * @param object[] $newObjects
     *
     * Note: the only reason why this entire looping/complexity is performed via `spl_object_id`
     *       is because we want to prevent using `array_udiff()`, which is likely to cause very
     *       high overhead (complexity of O(n^2)). `array_diff_key()` performs the operation in
     *       core, which is faster than using a callback for comparisons
     */
    private function restoreNewObjectsInDirtyCollection(array $newObjects): void
    {
        $loadedObjects               = $this->unwrap()->toArray();
        $newObjectsByOid             = array_combine(array_map('spl_object_id', $newObjects), $newObjects);
        $loadedObjectsByOid          = array_combine(array_map('spl_object_id', $loadedObjects), $loadedObjects);
        $newObjectsThatWereNotLoaded = array_diff_key($newObjectsByOid, $loadedObjectsByOid);

        if ($newObjectsThatWereNotLoaded) {
            // Reattach NEW objects added through add(), if any.
            array_walk($newObjectsThatWereNotLoaded, [$this->unwrap(), 'add']);

            $this->isDirty = true;
        }
    }
}
