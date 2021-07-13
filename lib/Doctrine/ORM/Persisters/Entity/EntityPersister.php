<?php

namespace Doctrine\ORM\Persisters\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query\ResultSetMapping;

/**
 * Entity persister interface
 * Define the behavior that should be implemented by all entity persisters.
 */
interface EntityPersister
{
    /**
     * @return ClassMetadata
     */
    public function getClassMetadata();

    /**
     * Gets the ResultSetMapping used for hydration.
     *
     * @return ResultSetMapping
     */
    public function getResultSetMapping();

    /**
     * Get all queued inserts.
     *
     * @psalm-return array<string|int, object>
     */
    public function getInserts();

     /**
      * @return string
      *
      * @TODO - It should not be here.
      * But its necessary since JoinedSubclassPersister#executeInserts invoke the root persister.
      *
      * Gets the INSERT SQL used by the persister to persist a new entity.
      */
    public function getInsertSQL();

    /**
     * Gets the SELECT SQL to select one or more entities by a set of field criteria.
     *
     * @param mixed[]|Criteria $criteria
     * @param mixed[]|null     $assoc
     * @param int|null         $lockMode
     * @param int|null         $limit
     * @param int|null         $offset
     * @param mixed[]|null     $orderBy
     *
     * @return string
     */
    public function getSelectSQL($criteria, $assoc = null, $lockMode = null, $limit = null, $offset = null, ?array $orderBy = null);

    /**
     * Get the COUNT SQL to count entities (optionally based on a criteria)
     *
     * @param mixed[]|Criteria $criteria
     *
     * @return string
     */
    public function getCountSQL($criteria = []);

    /**
     * Expands the parameters from the given criteria and use the correct binding types if found.
     *
     * @param string[] $criteria
     *
     * @psalm-return array{list<mixed>, list<int|string|null>}
     */
    public function expandParameters($criteria);

    /**
     * Expands Criteria Parameters by walking the expressions and grabbing all parameters and types from it.
     *
     * @psalm-return array{list<mixed>, list<int|string|null>}
     */
    public function expandCriteriaParameters(Criteria $criteria);

    /**
     * Gets the SQL WHERE condition for matching a field with a given value.
     *
     * @param string       $field
     * @param mixed        $value
     * @param mixed[]|null $assoc
     * @param string|null  $comparison
     * @psalm-param array<string, mixed>|null  $assoc
     *
     * @return string
     */
    public function getSelectConditionStatementSQL($field, $value, $assoc = null, $comparison = null);

    /**
     * Adds an entity to the queued insertions.
     * The entity remains queued until {@link executeInserts} is invoked.
     *
     * @param object $entity The entity to queue for insertion.
     *
     * @return void
     */
    public function addInsert($entity);

    /**
     * Executes all queued entity insertions and returns any generated post-insert
     * identifiers that were created as a result of the insertions.
     *
     * If no inserts are queued, invoking this method is a NOOP.
     *
     * @psalm-return list<array{
     *                   generatedId: int,
     *                   entity: object
     *               }> An array of any generated post-insert IDs. This will be
     *                  an empty array if the entity class does not use the
     *                  IDENTITY generation strategy.
     */
    public function executeInserts();

    /**
     * Updates a managed entity. The entity is updated according to its current changeset
     * in the running UnitOfWork. If there is no changeset, nothing is updated.
     *
     * @param object $entity The entity to update.
     *
     * @return void
     */
    public function update($entity);

    /**
     * Deletes a managed entity.
     *
     * The entity to delete must be managed and have a persistent identifier.
     * The deletion happens instantaneously.
     *
     * Subclasses may override this method to customize the semantics of entity deletion.
     *
     * @param object $entity The entity to delete.
     *
     * @return bool TRUE if the entity got deleted in the database, FALSE otherwise.
     */
    public function delete($entity);

    /**
     * Count entities (optionally filtered by a criteria)
     *
     * @param  mixed[]|Criteria $criteria
     *
     * @return int
     */
    public function count($criteria = []);

    /**
     * Gets the name of the table that owns the column the given field is mapped to.
     *
     * The default implementation in BasicEntityPersister always returns the name
     * of the table the entity type of this persister is mapped to, since an entity
     * is always persisted to a single table with a BasicEntityPersister.
     *
     * @param string $fieldName The field name.
     *
     * @return string The table name.
     */
    public function getOwningTable($fieldName);

    /**
     * Loads an entity by a list of field criteria.
     *
     * @param mixed[]       $criteria The criteria by which to load the entity.
     * @param object|null   $entity   The entity to load the data into. If not specified,
     *                                a new entity is created.
     * @param mixed[]|null  $assoc    The association that connects the entity
     *                                to load to another entity, if any.
     * @param mixed[]       $hints    Hints for entity creation.
     * @param int|null      $lockMode One of the \Doctrine\DBAL\LockMode::* constants
     *                                or NULL if no specific lock mode should be used
     *                                for loading the entity.
     * @param int|null      $limit    Limit number of results.
     * @param string[]|null $orderBy  Criteria to order by.
     * @psalm-param array<string, mixed>       $criteria
     * @psalm-param array<string, mixed>|null  $assoc
     * @psalm-param array<string, mixed>       $hints
     * @psalm-param array<string, string>|null $orderBy
     *
     * @return object|null The loaded and managed entity instance or NULL if the entity can not be found.
     *
     * @todo Check identity map? loadById method? Try to guess whether $criteria is the id?
     */
    public function load(
        array $criteria,
        $entity = null,
        $assoc = null,
        array $hints = [],
        $lockMode = null,
        $limit = null,
        ?array $orderBy = null
    );

    /**
     * Loads an entity by identifier.
     *
     * @param object|null $entity The entity to load the data into. If not specified, a new entity is created.
     * @psalm-param array<string, mixed> $identifier The entity identifier.
     *
     * @return object|null The loaded and managed entity instance or NULL if the entity can not be found.
     *
     * @todo Check parameters
     */
    public function loadById(array $identifier, $entity = null);

    /**
     * Loads an entity of this persister's mapped class as part of a single-valued
     * association from another entity.
     *
     * @param object $sourceEntity The entity that owns the association (not necessarily the "owning side").
     * @psalm-param array<string, mixed> $identifier The identifier of the entity to load. Must be provided if
     *                                               the association to load represents the owning side, otherwise
     *                                               the identifier is derived from the $sourceEntity.
     * @psalm-param array<string, mixed> $assoc        The association to load.
     *
     * @return object The loaded and managed entity instance or NULL if the entity can not be found.
     *
     * @throws MappingException
     */
    public function loadOneToOneEntity(array $assoc, $sourceEntity, array $identifier = []);

    /**
     * Refreshes a managed entity.
     *
     * @param object   $entity   The entity to refresh.
     * @param int|null $lockMode One of the \Doctrine\DBAL\LockMode::* constants
     *                           or NULL if no specific lock mode should be used
     *                           for refreshing the managed entity.
     * @psalm-param array<string, mixed> $id The identifier of the entity as an
     *                                       associative array from column or
     *                                       field names to values.
     *
     * @return void
     */
    public function refresh(array $id, $entity, $lockMode = null);

    /**
     * Loads Entities matching the given Criteria object.
     *
     * @return mixed[]
     */
    public function loadCriteria(Criteria $criteria);

    /**
     * Loads a list of entities by a list of field criteria.
     *
     * @param int|null $limit
     * @param int|null $offset
     * @psalm-param array<string, string>|null $orderBy
     * @psalm-param array<string, mixed>       $criteria
     */
    public function loadAll(array $criteria = [], ?array $orderBy = null, $limit = null, $offset = null);

    /**
     * Gets (sliced or full) elements of the given collection.
     *
     * @param object   $sourceEntity
     * @param int|null $offset
     * @param int|null $limit
     * @psalm-param array<string, mixed> $assoc
     *
     * @return mixed[]
     */
    public function getManyToManyCollection(array $assoc, $sourceEntity, $offset = null, $limit = null);

    /**
     * Loads a collection of entities of a many-to-many association.
     *
     * @param object               $sourceEntity The entity that owns the collection.
     * @param PersistentCollection $collection   The collection to fill.
     * @psalm-param array<string, mixed> $assoc The association mapping of the association being loaded.
     *
     * @return mixed[]
     */
    public function loadManyToManyCollection(array $assoc, $sourceEntity, PersistentCollection $collection);

    /**
     * Loads a collection of entities in a one-to-many association.
     *
     * @param object               $sourceEntity
     * @param PersistentCollection $collection   The collection to load/fill.
     * @psalm-param array<string, mixed> $assoc
     *
     * @return mixed
     */
    public function loadOneToManyCollection(array $assoc, $sourceEntity, PersistentCollection $collection);

    /**
     * Locks all rows of this entity matching the given criteria with the specified pessimistic lock mode.
     *
     * @param int $lockMode One of the Doctrine\DBAL\LockMode::* constants.
     * @psalm-param array<string, mixed> $criteria
     *
     * @return void
     */
    public function lock(array $criteria, $lockMode);

    /**
     * Returns an array with (sliced or full list) of elements in the specified collection.
     *
     * @param object   $sourceEntity
     * @param int|null $offset
     * @param int|null $limit
     * @psalm-param array<string, mixed> $assoc
     *
     * @return mixed[]
     */
    public function getOneToManyCollection(array $assoc, $sourceEntity, $offset = null, $limit = null);

    /**
     * Checks whether the given managed entity exists in the database.
     *
     * @param object $entity
     *
     * @return bool TRUE if the entity exists in the database, FALSE otherwise.
     */
    public function exists($entity, ?Criteria $extraConditions = null);
}
