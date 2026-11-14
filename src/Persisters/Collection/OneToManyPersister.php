<?php

declare(strict_types=1);

namespace Doctrine\ORM\Persisters\Collection;

use BadMethodCallException;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Internal\Query\TemporaryIdTable;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Utility\PersisterHelper;

use function array_fill;
use function array_keys;
use function array_reverse;
use function array_values;
use function assert;
use function count;
use function defined;
use function implode;
use function is_int;
use function is_string;

/**
 * Persister for one-to-many collections.
 */
class OneToManyPersister extends AbstractCollectionPersister
{
    public function delete(PersistentCollection $collection): void
    {
        // The only valid case here is when you have weak entities. In this
        // scenario, you have @OneToMany with orphanRemoval=true, and replacing
        // the entire collection with a new would trigger this operation.
        $mapping = $this->getMapping($collection);

        if (! $mapping->orphanRemoval) {
            // Handling non-orphan removal should never happen, as @OneToMany
            // can only be inverse side. For owning side one to many, it is
            // required to have a join table, which would classify as a ManyToManyPersister.
            return;
        }

        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);

        $targetClass->isInheritanceTypeJoined()
            ? $this->deleteJoinedEntityCollection($collection)
            : $this->deleteEntityCollection($collection);
    }

    public function update(PersistentCollection $collection): void
    {
        // This can never happen. One to many can only be inverse side.
        // For owning side one to many, it is required to have a join table,
        // then classifying it as a ManyToManyPersister.
        return;
    }

    public function get(PersistentCollection $collection, mixed $index): object|null
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->load(
            [
                $mapping->mappedBy  => $collection->getOwner(),
                $mapping->indexBy() => $index,
            ],
            null,
            $mapping,
            [],
            null,
            1,
        );
    }

    public function count(PersistentCollection $collection): int
    {
        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        // Doctrine Collections 2.x support
        $criteria = defined(Criteria::class . '::ASC') ? Criteria::create(true) : Criteria::create();

        // only works with single id identifier entities. Will throw an
        // exception in Entity Persisters if that is not the case for the
        // 'mappedBy' field.
        $criteria = $criteria->where(Criteria::expr()->eq($mapping->mappedBy, $collection->getOwner()));

        return $persister->count($criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function slice(PersistentCollection $collection, int $offset, int|null $length = null): array
    {
        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        return $persister->getOneToManyCollection($mapping, $collection->getOwner(), $offset, $length);
    }

    public function containsKey(PersistentCollection $collection, mixed $key): bool
    {
        $mapping = $this->getMapping($collection);

        if (! $mapping->isIndexed()) {
            throw new BadMethodCallException('Selecting a collection by index is only supported on indexed collections.');
        }

        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        // only works with single id identifier entities. Will throw an
        // exception in Entity Persisters if that is not the case for the
        // 'mappedBy' field.
        // Doctrine Collections 2.x support
        $criteria = defined(Criteria::class . '::ASC') ? Criteria::create(true) : Criteria::create();

        $criteria->andWhere(Criteria::expr()->eq($mapping->mappedBy, $collection->getOwner()));
        $criteria->andWhere(Criteria::expr()->eq($mapping->indexBy(), $key));

        return (bool) $persister->count($criteria);
    }

    public function contains(PersistentCollection $collection, object $element): bool
    {
        if (! $this->isValidEntityState($element)) {
            return false;
        }

        $mapping   = $this->getMapping($collection);
        $persister = $this->uow->getEntityPersister($mapping->targetEntity);

        // Doctrine Collections 2.x support
        $criteria = defined(Criteria::class . '::ASC') ? Criteria::create(true) : Criteria::create();
        // only works with single id identifier entities. Will throw an
        // exception in Entity Persisters if that is not the case for the
        // 'mappedBy' field.
        $criteria = $criteria->where(Criteria::expr()->eq($mapping->mappedBy, $collection->getOwner()));

        return $persister->exists($element, $criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function loadCriteria(PersistentCollection $collection, Criteria $criteria): array
    {
        throw new BadMethodCallException('Filtering a collection by Criteria is not supported by this CollectionPersister.');
    }

    /**
     * @throws DBALException
     * @throws EntityNotFoundException
     * @throws MappingException
     */
    private function deleteEntityCollection(PersistentCollection $collection): int
    {
        $mapping     = $this->getMapping($collection);
        $identifier  = $this->uow->getEntityIdentifier($collection->getOwner());
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $columns     = [];
        $parameters  = [];
        $types       = [];

        foreach ($this->em->getMetadataFactory()->getOwningSide($mapping)->joinColumns as $joinColumn) {
            $columns[]    = $this->quoteStrategy->getJoinColumnName($joinColumn, $targetClass, $this->platform);
            $parameters[] = $identifier[$sourceClass->getFieldForColumn($joinColumn->referencedColumnName)];
            $types[]      = PersisterHelper::getTypeOfColumn($joinColumn->referencedColumnName, $sourceClass, $this->em);
        }

        $statement = 'DELETE FROM ' . $this->quoteStrategy->getTableName($targetClass, $this->platform)
            . ' WHERE ' . implode(' = ? AND ', $columns) . ' = ?';

        if ($targetClass->isInheritanceTypeSingleTable()) {
            $discriminatorColumn = $targetClass->getDiscriminatorColumn();
            $discriminatorValues = $targetClass->discriminatorValue ? [$targetClass->discriminatorValue] : array_keys($targetClass->discriminatorMap);
            $statement          .= ' AND ' . $discriminatorColumn->name . ' IN (' . implode(', ', array_fill(0, count($discriminatorValues), '?')) . ')';
            foreach ($discriminatorValues as $discriminatorValue) {
                $parameters[] = $discriminatorValue;
                $types[]      = $discriminatorColumn->type;
            }
        }

        $numAffected = $this->conn->executeStatement($statement, $parameters, $types);

        assert(is_int($numAffected));

        return $numAffected;
    }

    /**
     * Delete Class Table Inheritance entities.
     * A temporary table is needed to keep IDs to be deleted in both parent and child class' tables.
     *
     * Thanks Steve Ebersole (Hibernate) for idea on how to tackle reliably this scenario, we owe him a beer! =)
     *
     * @throws DBALException
     */
    private function deleteJoinedEntityCollection(PersistentCollection $collection): int
    {
        $mapping     = $this->getMapping($collection);
        $sourceClass = $this->em->getClassMetadata($mapping->sourceEntity);
        $targetClass = $this->em->getClassMetadata($mapping->targetEntity);
        $rootClass   = $this->em->getClassMetadata($targetClass->rootEntityName);

        // 1) Build temporary table DDL
        $tempTable     = $this->platform->getTemporaryTableName($rootClass->getTemporaryIdTableName());
        $idColumnNames = $rootClass->getIdentifierColumnNames();
        $idColumnList  = implode(', ', $idColumnNames);

        $statement = TemporaryIdTable::getCreateSQL($tempTable, $rootClass, $this->em);

        $this->conn->executeStatement($statement);

        // 2) Build insert table records into temporary table
        $query = $this->em->createQuery(
            ' SELECT t0.' . implode(', t0.', $rootClass->getIdentifierFieldNames())
            . ' FROM ' . $targetClass->name . ' t0 WHERE t0.' . $mapping->mappedBy . ' = :owner',
        )->setParameter('owner', $collection->getOwner());

        $sql = $query->getSQL();
        assert(is_string($sql));
        $statement  = 'INSERT INTO ' . $tempTable . ' (' . $idColumnList . ') ' . $sql;
        $parameters = array_values($sourceClass->getIdentifierValues($collection->getOwner()));
        $numDeleted = $this->conn->executeStatement($statement, $parameters);

        // 3) Delete records on each table in the hierarchy
        $classNames = [...$targetClass->parentClasses, ...[$targetClass->name], ...$targetClass->subClasses];

        foreach (array_reverse($classNames) as $className) {
            $tableName = $this->quoteStrategy->getTableName($this->em->getClassMetadata($className), $this->platform);
            $statement = 'DELETE FROM ' . $tableName . ' WHERE (' . $idColumnList . ')'
                . ' IN (SELECT ' . $idColumnList . ' FROM ' . $tempTable . ')';

            $this->conn->executeStatement($statement);
        }

        // 4) Drop temporary table
        $statement = $this->platform->getDropTemporaryTableSQL($tempTable);

        $this->conn->executeStatement($statement);

        assert(is_int($numDeleted));

        return $numDeleted;
    }

    private function getMapping(PersistentCollection $collection): OneToManyAssociationMapping
    {
        $mapping = $collection->getMapping();

        assert($mapping->isOneToMany());

        return $mapping;
    }
}
