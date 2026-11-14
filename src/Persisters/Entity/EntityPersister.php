<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use SortDirection;

/**
 * Entity persister interface
 * Define the behavior that should be implemented by all entity persisters.
 */
interface EntityPersister
{
    public function getClassMetadata(): ClassMetadata;

    /**
     * Gets the ResultSetMapping used for hydration.
     */
    public function getResultSetMapping(): ResultSetMapping;

    /**
     * Get all queued inserts.
     *
     * @return object[]
     */
    public function getInserts(): array;

     /**
      * Gets the INSERT SQL used by the persister to persist a new entity.
      *
      * @TODO It should not be here.
      *       But its necessary since JoinedSubclassPersister#executeInserts invoke the root persister.
      */
    public function getInsertSQL(): string;

    /**
     * Gets the SELECT SQL to select one or more entities by a set of field criteria.
     *
     * @param mixed[]|Criteria $criteria
     * @param mixed[]|null     $orderBy
     * @phpstan-param AssociationMapping|null $assoc
     */
    public function getSelectSQL(
        array|Criteria $criteria,
        AssociationMapping|null $assoc = null,
        LockMode|null $lockMode = null,
        int|null $limit = null,
        int|null $offset = null,
        array|null $orderBy = null,
    ): string;

    /**
     * Get the COUNT SQL to count entities (optionally based on a criteria)
     *
     * @param mixed[]|Criteria $criteria
     */
    public function getCountSQL(array|Criteria $criteria = []): string;

    /**
     * Expands the parameters from the given criteria and use the correct binding types if found.
     *
     * @param array<string, mixed> $criteria
     *
     * @phpstan-return array{list<mixed>, list<ParameterType|ArrayParameterType|string>}
     */
    public function expandParameters(array $criteria): array;

    /**
     * Expands Criteria Parameters by walking the expressions and grabbing all parameters and types from it.
     *
     * @phpstan-return array{list<mixed>, list<ParameterType|ArrayParameterType|string>}
     */
    public function expandCriteriaParameters(Criteria $criteria): array;

    /** Gets the SQL WHERE condition for matching a field with a given value. */
    public function getSelectConditionStatementSQL(
        string $field,
        mixed $value,
        AssociationMapping|null $assoc = null,
        string|null $comparison = null,
    ): string;

    /**
     * Adds an entity to the queued insertions.
     * The entity remains queued until {@link executeInserts} is invoked.
     */
    public function addInsert(object $entity): void;

    /**
     * Executes all queued entity insertions.
     *
     * If no inserts are queued, invoking this method is a NOOP.
     */
    public function executeInserts(): void;

    /**
     * Updates a managed entity. The entity is updated according to its current changeset
     * in the running UnitOfWork. If there is no changeset, nothing is updated.
     */
    public function update(object $entity): void;

    /**
     * Deletes a managed entity.
     *
     * The entity to delete must be managed and have a persistent identifier.
     * The deletion happens instantaneously.
     *
     * Subclasses may override this method to customize the semantics of entity deletion.
     *
     * @return bool TRUE if the entity got deleted in the database, FALSE otherwise.
     */
    public function delete(object $entity): bool;

    /**
     * Count entities (optionally filtered by a criteria)
     *
     * @param mixed[]|Criteria $criteria
     *
     * @phpstan-return 0|positive-int
     */
    public function count(array|Criteria $criteria = []): int;

    /**
     * Gets the name of the table that owns the column the given field is mapped to.
     *
     * The default implementation in BasicEntityPersister always returns the name
     * of the table the entity type of this persister is mapped to, since an entity
     * is always persisted to a single table with a BasicEntityPersister.
     */
    public function getOwningTable(string $fieldName): string;

    /**
     * Loads an entity by a list of field criteria.
     *
     * @param mixed[]                                  $criteria The criteria by which to load the entity.
     * @param object|null                              $entity   The entity to load the data into. If not specified,
     *                                                           a new entity is created.
     * @param AssociationMapping|null                  $assoc    The association that connects the entity
     *                                                           to load to another entity, if any.
     * @param mixed[]                                  $hints    Hints for entity creation.
     * @param LockMode|null                            $lockMode The lock mode or NULL if no specific lock mode
     *                                                           should be used for loading the entity.
     * @param int|null                                 $limit    Limit number of results.
     * @param array<string, SortDirection|string>|null $orderBy  Criteria to order by.
     * @phpstan-param array<string, mixed>       $criteria
     * @phpstan-param array<string, mixed>       $hints
     *
     * @return object|null The loaded and managed entity instance or NULL if the entity can not be found.
     *
     * @todo Check identity map? loadById method? Try to guess whether $criteria is the id?
     */
    public function load(
        array $criteria,
        object|null $entity = null,
        AssociationMapping|null $assoc = null,
        array $hints = [],
        LockMode|null $lockMode = null,
        int|null $limit = null,
        array|null $orderBy = null,
    ): object|null;

    /**
     * Loads an entity by identifier.
     *
     * @param object|null $entity The entity to load the data into. If not specified, a new entity is created.
     * @phpstan-param array<string, mixed> $identifier The entity identifier.
     *
     * @return object|null The loaded and managed entity instance or NULL if the entity can not be found.
     *
     * @todo Check parameters
     */
    public function loadById(array $identifier, object|null $entity = null): object|null;

    /**
     * Loads an entity of this persister's mapped class as part of a single-valued
     * association from another entity.
     *
     * @param AssociationMapping $assoc        The association to load.
     * @param object             $sourceEntity The entity that owns the association (not necessarily the "owning side").
     * @phpstan-param array<string, mixed> $identifier The identifier of the entity to load. Must be provided if
     *                                               the association to load represents the owning side, otherwise
     *                                               the identifier is derived from the $sourceEntity.
     *
     * @return object|null The loaded and managed entity instance or NULL if the entity can not be found.
     *
     * @throws MappingException
     */
    public function loadOneToOneEntity(AssociationMapping $assoc, object $sourceEntity, array $identifier = []): object|null;

    /**
     * Refreshes a managed entity.
     *
     * @param LockMode|null $lockMode The lock mode or NULL if no specific lock mode
     *                                should be used for refreshing the managed entity.
     * @phpstan-param array<string, mixed> $id The identifier of the entity as an
     *                                       associative array from column or
     *                                       field names to values.
     */
    public function refresh(array $id, object $entity, LockMode|null $lockMode = null): void;

    /**
     * Loads Entities matching the given Criteria object.
     *
     * @return mixed[]
     */
    public function loadCriteria(Criteria $criteria): array;

    /**
     * Loads a list of entities by a list of field criteria.
     *
     * @param array<string, SortDirection|string>|null $orderBy Criteria to order by.
     * @phpstan-param array<string, mixed>                       $criteria
     *
     * @return mixed[]
     */
    public function loadAll(
        array $criteria = [],
        array|null $orderBy = null,
        int|null $limit = null,
        int|null $offset = null,
    ): array;

    /**
     * Gets (sliced or full) elements of the given collection.
     *
     * @return mixed[]
     */
    public function getManyToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array;

    /**
     * Loads a collection of entities of a many-to-many association.
     *
     * @param AssociationMapping   $assoc        The association mapping of the association being loaded.
     * @param object               $sourceEntity The entity that owns the collection.
     * @param PersistentCollection $collection   The collection to fill.
     *
     * @return mixed[]
     */
    public function loadManyToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        PersistentCollection $collection,
    ): array;

    /**
     * Loads a collection of entities in a one-to-many association.
     *
     * @param PersistentCollection $collection The collection to load/fill.
     */
    public function loadOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        PersistentCollection $collection,
    ): mixed;

    /**
     * Locks all rows of this entity matching the given criteria with the specified pessimistic lock mode.
     *
     * @phpstan-param array<string, mixed> $criteria
     */
    public function lock(array $criteria, LockMode $lockMode): void;

    /**
     * Returns an array with (sliced or full list) of elements in the specified collection.
     *
     * @return mixed[]
     */
    public function getOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array;

    /**
     * Checks whether the given managed entity exists in the database.
     */
    public function exists(object $entity, Criteria|null $extraConditions = null): bool;
}
