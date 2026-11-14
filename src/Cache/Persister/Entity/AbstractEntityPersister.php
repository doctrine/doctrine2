<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\EntityHydrator;
use Doctrine\ORM\Cache\Logging\CacheLogger;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\Cache\QueryCacheKey;
use Doctrine\ORM\Cache\Region;
use Doctrine\ORM\Cache\TimestampCacheKey;
use Doctrine\ORM\Cache\TimestampRegion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\UnitOfWork;
use Override;
use SortDirection;

use function array_merge;
use function serialize;
use function sha1;

abstract class AbstractEntityPersister implements CachedEntityPersister
{
    protected UnitOfWork $uow;
    protected ClassMetadataFactory $metadataFactory;

    /** @var mixed[] */
    protected array $queuedCache = [];

    protected TimestampRegion $timestampRegion;
    protected TimestampCacheKey $timestampKey;
    protected EntityHydrator $hydrator;
    protected Cache $cache;
    protected FilterCollection $filters;
    protected CacheLogger|null $cacheLogger = null;
    protected string $regionName;

    /**
     * Associations configured as FETCH_EAGER, as well as all inverse one-to-one associations.
     *
     * @var array<string>|null
     */
    protected array|null $joinedAssociations = null;

    public function __construct(
        protected EntityPersister $persister,
        protected Region $region,
        EntityManagerInterface $em,
        protected ClassMetadata $class,
    ) {
        $configuration = $em->getConfiguration();
        $cacheConfig   = $configuration->getSecondLevelCacheConfiguration();
        $cacheFactory  = $cacheConfig->getCacheFactory();

        $this->cache           = $em->getCache();
        $this->filters         = $em->getFilters();
        $this->regionName      = $region->getName();
        $this->uow             = $em->getUnitOfWork();
        $this->metadataFactory = $em->getMetadataFactory();
        $this->cacheLogger     = $cacheConfig->getCacheLogger();
        $this->timestampRegion = $cacheFactory->getTimestampRegion();
        $this->hydrator        = $cacheFactory->buildEntityHydrator($em, $class);
        $this->timestampKey    = new TimestampCacheKey($this->class->rootEntityName);
    }

    #[Override]
    public function addInsert(object $entity): void
    {
        $this->persister->addInsert($entity);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getInserts(): array
    {
        return $this->persister->getInserts();
    }

    #[Override]
    public function getSelectSQL(
        array|Criteria $criteria,
        AssociationMapping|null $assoc = null,
        LockMode|null $lockMode = null,
        int|null $limit = null,
        int|null $offset = null,
        array|null $orderBy = null,
    ): string {
        return $this->persister->getSelectSQL($criteria, $assoc, $lockMode, $limit, $offset, $orderBy);
    }

    #[Override]
    public function getCountSQL(array|Criteria $criteria = []): string
    {
        return $this->persister->getCountSQL($criteria);
    }

    #[Override]
    public function getInsertSQL(): string
    {
        return $this->persister->getInsertSQL();
    }

    #[Override]
    public function getResultSetMapping(): ResultSetMapping
    {
        return $this->persister->getResultSetMapping();
    }

    #[Override]
    public function getSelectConditionStatementSQL(
        string $field,
        mixed $value,
        AssociationMapping|null $assoc = null,
        string|null $comparison = null,
    ): string {
        return $this->persister->getSelectConditionStatementSQL($field, $value, $assoc, $comparison);
    }

    #[Override]
    public function exists(object $entity, Criteria|null $extraConditions = null): bool
    {
        if ($extraConditions === null) {
            $key = new EntityCacheKey($this->class->rootEntityName, $this->class->getIdentifierValues($entity));

            if ($this->region->contains($key)) {
                return true;
            }
        }

        return $this->persister->exists($entity, $extraConditions);
    }

    #[Override]
    public function getCacheRegion(): Region
    {
        return $this->region;
    }

    #[Override]
    public function getEntityHydrator(): EntityHydrator
    {
        return $this->hydrator;
    }

    #[Override]
    public function storeEntityCache(object $entity, EntityCacheKey $key): bool
    {
        $class     = $this->class;
        $className = $entity::class;

        if ($className !== $this->class->name) {
            $class = $this->metadataFactory->getMetadataFor($className);
        }

        $entry  = $this->hydrator->buildCacheEntry($class, $key, $entity);
        $cached = $this->region->put($key, $entry);

        if ($cached) {
            $this->cacheLogger?->entityCachePut($this->regionName, $key);
        }

        return $cached;
    }

    private function storeJoinedAssociations(object $entity): void
    {
        if ($this->joinedAssociations === null) {
            $associations = [];

            foreach ($this->class->associationMappings as $name => $assoc) {
                if (
                    isset($assoc->cache) &&
                    ($assoc->isToOne()) &&
                    ($assoc->fetch === ClassMetadata::FETCH_EAGER || ! $assoc->isOwningSide())
                ) {
                    $associations[] = $name;
                }
            }

            $this->joinedAssociations = $associations;
        }

        foreach ($this->joinedAssociations as $name) {
            $assoc       = $this->class->associationMappings[$name];
            $assocEntity = $this->class->getFieldValue($entity, $name);

            if ($assocEntity === null) {
                continue;
            }

            $assocId        = $this->uow->getEntityIdentifier($assocEntity);
            $assocMetadata  = $this->metadataFactory->getMetadataFor($assoc->targetEntity);
            $assocKey       = new EntityCacheKey($assocMetadata->rootEntityName, $assocId);
            $assocPersister = $this->uow->getEntityPersister($assoc->targetEntity);

            $assocPersister->storeEntityCache($assocEntity, $assocKey);
        }
    }

    /**
     * Generates a string of currently query
     *
     * @param string[]|Criteria                 $criteria
     * @param array<string, SortDirection>|null $orderBy
     */
    protected function getHash(
        string $query,
        array|Criteria $criteria,
        array|null $orderBy = null,
        int|null $limit = null,
        int|null $offset = null,
    ): string {
        [$params] = $criteria instanceof Criteria
            ? $this->persister->expandCriteriaParameters($criteria)
            : $this->persister->expandParameters($criteria);

        return sha1($query . serialize($params) . serialize($orderBy) . $limit . $offset . $this->filters->getHash());
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function expandParameters(array $criteria): array
    {
        return $this->persister->expandParameters($criteria);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function expandCriteriaParameters(Criteria $criteria): array
    {
        return $this->persister->expandCriteriaParameters($criteria);
    }

    #[Override]
    public function getClassMetadata(): ClassMetadata
    {
        return $this->persister->getClassMetadata();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getManyToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array {
        return $this->persister->getManyToManyCollection($assoc, $sourceEntity, $offset, $limit);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function getOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        int|null $offset = null,
        int|null $limit = null,
    ): array {
        return $this->persister->getOneToManyCollection($assoc, $sourceEntity, $offset, $limit);
    }

    #[Override]
    public function getOwningTable(string $fieldName): string
    {
        return $this->persister->getOwningTable($fieldName);
    }

    #[Override]
    public function executeInserts(): void
    {
        // The commit order/foreign key relationships may make it necessary that multiple calls to executeInsert()
        // are performed, so collect all the new entities.
        $newInserts = $this->persister->getInserts();

        if ($newInserts) {
            $this->queuedCache['insert'] = array_merge($this->queuedCache['insert'] ?? [], $newInserts);
        }

        $this->persister->executeInserts();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function load(
        array $criteria,
        object|null $entity = null,
        AssociationMapping|null $assoc = null,
        array $hints = [],
        LockMode|null $lockMode = null,
        int|null $limit = null,
        array|null $orderBy = null,
    ): object|null {
        if ($entity !== null || $assoc !== null || $hints !== [] || $lockMode !== null) {
            return $this->persister->load($criteria, $entity, $assoc, $hints, $lockMode, $limit, $orderBy);
        }

        //handle only EntityRepository#findOneBy
        $query      = $this->persister->getSelectSQL($criteria, null, null, $limit, null, $orderBy);
        $hash       = $this->getHash($query, $criteria);
        $rsm        = $this->getResultSetMapping();
        $queryKey   = new QueryCacheKey($hash, 0, Cache::MODE_NORMAL, $this->timestampKey);
        $queryCache = $this->cache->getQueryCache($this->regionName);
        $result     = $queryCache->get($queryKey, $rsm);

        if ($result !== null) {
            $this->cacheLogger?->queryCacheHit($this->regionName, $queryKey);

            return $result[0];
        }

        $result = $this->persister->load($criteria, $entity, $assoc, $hints, $lockMode, $limit, $orderBy);

        if ($result === null) {
            return null;
        }

        $cached = $queryCache->put($queryKey, $rsm, [$result]);

        $this->cacheLogger?->queryCacheMiss($this->regionName, $queryKey);

        if ($cached) {
            $this->cacheLogger?->queryCachePut($this->regionName, $queryKey);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function loadAll(
        array $criteria = [],
        array|null $orderBy = null,
        int|null $limit = null,
        int|null $offset = null,
    ): array {
        $query      = $this->persister->getSelectSQL($criteria, null, null, $limit, $offset, $orderBy);
        $hash       = $this->getHash($query, $criteria);
        $rsm        = $this->getResultSetMapping();
        $queryKey   = new QueryCacheKey($hash, 0, Cache::MODE_NORMAL, $this->timestampKey);
        $queryCache = $this->cache->getQueryCache($this->regionName);
        $result     = $queryCache->get($queryKey, $rsm);

        if ($result !== null) {
            $this->cacheLogger?->queryCacheHit($this->regionName, $queryKey);

            return $result;
        }

        $result = $this->persister->loadAll($criteria, $orderBy, $limit, $offset);
        $cached = $queryCache->put($queryKey, $rsm, $result);

        if ($result) {
            $this->cacheLogger?->queryCacheMiss($this->regionName, $queryKey);
        }

        if ($cached) {
            $this->cacheLogger?->queryCachePut($this->regionName, $queryKey);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function loadById(array $identifier, object|null $entity = null): object|null
    {
        $cacheKey   = new EntityCacheKey($this->class->rootEntityName, $identifier);
        $cacheEntry = $this->region->get($cacheKey);
        $class      = $this->class;

        if ($cacheEntry !== null) {
            if ($cacheEntry->class !== $this->class->name) {
                $class = $this->metadataFactory->getMetadataFor($cacheEntry->class);
            }

            $cachedEntity = $this->hydrator->loadCacheEntry($class, $cacheKey, $cacheEntry, $entity);

            if ($cachedEntity !== null) {
                $this->cacheLogger?->entityCacheHit($this->regionName, $cacheKey);

                return $cachedEntity;
            }
        }

        $entity = $this->persister->loadById($identifier, $entity);

        if ($entity === null) {
            return null;
        }

        $class     = $this->class;
        $className = $entity::class;

        if ($className !== $this->class->name) {
            $class = $this->metadataFactory->getMetadataFor($className);
        }

        $cacheEntry = $this->hydrator->buildCacheEntry($class, $cacheKey, $entity);
        $cached     = $this->region->put($cacheKey, $cacheEntry);

        if ($cached && ($this->joinedAssociations === null || $this->joinedAssociations)) {
            $this->storeJoinedAssociations($entity);
        }

        if ($cached) {
            $this->cacheLogger?->entityCachePut($this->regionName, $cacheKey);
        }

        $this->cacheLogger?->entityCacheMiss($this->regionName, $cacheKey);

        return $entity;
    }

    #[Override]
    public function count(array|Criteria $criteria = []): int
    {
        return $this->persister->count($criteria);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function loadCriteria(Criteria $criteria): array
    {
        $orderBy     = $criteria->getOrderings();
        $limit       = $criteria->getMaxResults();
        $offset      = $criteria->getFirstResult();
        $query       = $this->persister->getSelectSQL($criteria);
        $hash        = $this->getHash($query, $criteria, $orderBy, $limit, $offset);
        $rsm         = $this->getResultSetMapping();
        $queryKey    = new QueryCacheKey($hash, 0, Cache::MODE_NORMAL, $this->timestampKey);
        $queryCache  = $this->cache->getQueryCache($this->regionName);
        $cacheResult = $queryCache->get($queryKey, $rsm);

        if ($cacheResult !== null) {
            $this->cacheLogger?->queryCacheHit($this->regionName, $queryKey);

            return $cacheResult;
        }

        $result = $this->persister->loadCriteria($criteria);
        $cached = $queryCache->put($queryKey, $rsm, $result);

        if ($result) {
            $this->cacheLogger?->queryCacheMiss($this->regionName, $queryKey);
        }

        if ($cached) {
            $this->cacheLogger?->queryCachePut($this->regionName, $queryKey);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function loadManyToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        PersistentCollection $collection,
    ): array {
        $persister = $this->uow->getCollectionPersister($assoc);
        $hasCache  = ($persister instanceof CachedPersister);

        if (! $hasCache) {
            return $this->persister->loadManyToManyCollection($assoc, $sourceEntity, $collection);
        }

        $ownerId = $this->uow->getEntityIdentifier($collection->getOwner());
        $key     = $this->buildCollectionCacheKey($assoc, $ownerId, $this->filters->getHash());
        $list    = $persister->loadCollectionCache($collection, $key);

        if ($list !== null) {
            $this->cacheLogger?->collectionCacheHit($persister->getCacheRegion()->getName(), $key);

            return $list;
        }

        $list = $this->persister->loadManyToManyCollection($assoc, $sourceEntity, $collection);

        $persister->storeCollectionCache($key, $list);

        $this->cacheLogger?->collectionCacheMiss($persister->getCacheRegion()->getName(), $key);

        return $list;
    }

    #[Override]
    public function loadOneToManyCollection(
        AssociationMapping $assoc,
        object $sourceEntity,
        PersistentCollection $collection,
    ): mixed {
        $persister = $this->uow->getCollectionPersister($assoc);
        $hasCache  = ($persister instanceof CachedPersister);

        if (! $hasCache) {
            return $this->persister->loadOneToManyCollection($assoc, $sourceEntity, $collection);
        }

        $ownerId = $this->uow->getEntityIdentifier($collection->getOwner());
        $key     = $this->buildCollectionCacheKey($assoc, $ownerId, $this->filters->getHash());
        $list    = $persister->loadCollectionCache($collection, $key);

        if ($list !== null) {
            $this->cacheLogger?->collectionCacheHit($persister->getCacheRegion()->getName(), $key);

            return $list;
        }

        $list = $this->persister->loadOneToManyCollection($assoc, $sourceEntity, $collection);

        $persister->storeCollectionCache($key, $list);

        $this->cacheLogger?->collectionCacheMiss($persister->getCacheRegion()->getName(), $key);

        return $list;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function loadOneToOneEntity(AssociationMapping $assoc, object $sourceEntity, array $identifier = []): object|null
    {
        return $this->persister->loadOneToOneEntity($assoc, $sourceEntity, $identifier);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function lock(array $criteria, LockMode $lockMode): void
    {
        $this->persister->lock($criteria, $lockMode);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function refresh(array $id, object $entity, LockMode|null $lockMode = null): void
    {
        $this->persister->refresh($id, $entity, $lockMode);
    }

    /** @param array<string, mixed> $ownerId */
    protected function buildCollectionCacheKey(AssociationMapping $association, array $ownerId, string $filterHash): CollectionCacheKey
    {
        return new CollectionCacheKey(
            $this->metadataFactory->getMetadataFor($association->sourceEntity)->rootEntityName,
            $association->fieldName,
            $ownerId,
            $filterHash,
        );
    }
}
