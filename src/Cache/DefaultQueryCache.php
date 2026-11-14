<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\Exception\FeatureNotImplemented;
use Doctrine\ORM\Cache\Exception\NonCacheableEntity;
use Doctrine\ORM\Cache\Logging\CacheLogger;
use Doctrine\ORM\Cache\Persister\Entity\CachedEntityPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\UnitOfWork;

use function array_map;
use function array_shift;
use function array_unshift;
use function assert;
use function count;
use function is_array;
use function key;
use function reset;

/**
 * Default query cache implementation.
 */
class DefaultQueryCache implements QueryCache
{
    private readonly UnitOfWork $uow;
    private readonly QueryCacheValidator $validator;
    protected CacheLogger|null $cacheLogger = null;

    /** @var array<string,mixed> */
    private static array $hints = [Query::HINT_CACHE_ENABLED => true];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Region $region,
    ) {
        $cacheConfig = $em->getConfiguration()->getSecondLevelCacheConfiguration();

        $this->uow         = $em->getUnitOfWork();
        $this->cacheLogger = $cacheConfig->getCacheLogger();
        $this->validator   = $cacheConfig->getQueryValidator();
    }

    /**
     * {@inheritDoc}
     */
    public function get(QueryCacheKey $key, ResultSetMapping $rsm, array $hints = []): array|null
    {
        if (! ($key->cacheMode & Cache::MODE_GET)) {
            return null;
        }

        $cacheEntry = $this->region->get($key);

        if (! $cacheEntry instanceof QueryCacheEntry) {
            return null;
        }

        if (! $this->validator->isValid($key, $cacheEntry)) {
            $this->region->evict($key);

            return null;
        }

        $this->uow->enterHydration();

        try {
            return $this->hydrateCacheEntry($cacheEntry, $rsm);
        } finally {
            $this->uow->leaveHydration();
        }
    }

    /** @return mixed[]|null */
    private function hydrateCacheEntry(QueryCacheEntry $cacheEntry, ResultSetMapping $rsm): array|null
    {
        $result      = [];
        $entityName  = reset($rsm->aliasMap);
        $hasRelation = ! empty($rsm->relationMap);
        $persister   = $this->uow->getEntityPersister($entityName);
        assert($persister instanceof CachedEntityPersister);

        $region     = $persister->getCacheRegion();
        $regionName = $region->getName();

        $cm = $this->em->getClassMetadata($entityName);

        $generateKeys = static fn (array $entry): EntityCacheKey => new EntityCacheKey($cm->rootEntityName, $entry['identifier']);

        $cacheKeys = new CollectionCacheEntry(array_map($generateKeys, $cacheEntry->result));
        $entries   = $region->getMultiple($cacheKeys) ?? [];

        // @TODO - move to cache hydration component
        foreach ($cacheEntry->result as $index => $entry) {
            $entityEntry = $entries[$index] ?? null;

            if (! $entityEntry instanceof EntityCacheEntry) {
                $this->cacheLogger?->entityCacheMiss($regionName, $cacheKeys->identifiers[$index]);

                return null;
            }

            $this->cacheLogger?->entityCacheHit($regionName, $cacheKeys->identifiers[$index]);

            if (! $hasRelation) {
                $result[$index] = $this->uow->createEntity($entityEntry->class, $entityEntry->resolveAssociationEntries($this->em), self::$hints);

                continue;
            }

            $data = $entityEntry->data;

            foreach ($entry['associations'] as $name => $assoc) {
                $assocPersister = $this->uow->getEntityPersister($assoc['targetEntity']);
                assert($assocPersister instanceof CachedEntityPersister);

                $assocRegion   = $assocPersister->getCacheRegion();
                $assocMetadata = $this->em->getClassMetadata($assoc['targetEntity']);

                if ($assoc['type'] & ClassMetadata::TO_ONE) {
                    $assocKey   = new EntityCacheKey($assocMetadata->rootEntityName, $assoc['identifier']);
                    $assocEntry = $assocRegion->get($assocKey);

                    if ($assocEntry === null) {
                        $this->cacheLogger?->entityCacheMiss($assocRegion->getName(), $assocKey);

                        return null;
                    }

                    $data[$name] = $this->uow->createEntity($assocEntry->class, $assocEntry->resolveAssociationEntries($this->em), self::$hints);

                    $this->cacheLogger?->entityCacheHit($assocRegion->getName(), $assocKey);

                    continue;
                }

                if (! isset($assoc['list']) || empty($assoc['list'])) {
                    continue;
                }

                $generateKeys = static fn (array $id): EntityCacheKey => new EntityCacheKey($assocMetadata->rootEntityName, $id);

                $collection   = new PersistentCollection($this->em, $assocMetadata, new ArrayCollection());
                $assocKeys    = new CollectionCacheEntry(array_map($generateKeys, $assoc['list']));
                $assocEntries = $assocRegion->getMultiple($assocKeys);

                foreach ($assoc['list'] as $assocIndex => $assocId) {
                    $assocEntry = is_array($assocEntries) ? ($assocEntries[$assocIndex] ?? null) : null;

                    if ($assocEntry === null) {
                        $this->cacheLogger?->entityCacheMiss($assocRegion->getName(), $assocKeys->identifiers[$assocIndex]);

                        return null;
                    }

                    $element = $this->uow->createEntity($assocEntry->class, $assocEntry->resolveAssociationEntries($this->em), self::$hints);

                    $collection->hydrateSet($assocIndex, $element);

                    $this->cacheLogger?->entityCacheHit($assocRegion->getName(), $assocKeys->identifiers[$assocIndex]);
                }

                $data[$name] = $collection;

                $collection->setInitialized(true);
            }

            foreach ($data as $fieldName => $unCachedAssociationData) {
                // In some scenarios, such as EAGER+ASSOCIATION+ID+CACHE, the
                // cache key information in `$cacheEntry` will not contain details
                // for fields that are associations.
                //
                // This means that `$data` keys for some associations that may
                // actually not be cached will not be converted to actual association
                // data, yet they contain L2 cache AssociationCacheEntry objects.
                //
                // We need to unwrap those associations into proxy references,
                // since we don't have actual data for them except for identifiers.
                if ($unCachedAssociationData instanceof AssociationCacheEntry) {
                    $data[$fieldName] = $this->em->getReference(
                        $unCachedAssociationData->class,
                        $unCachedAssociationData->identifier,
                    );
                }
            }

            $result[$index] = $this->uow->createEntity($entityEntry->class, $data, self::$hints);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function put(QueryCacheKey $key, ResultSetMapping $rsm, mixed $result, array $hints = []): bool
    {
        if ($rsm->scalarMappings) {
            throw FeatureNotImplemented::scalarResults();
        }

        if (count($rsm->entityMappings) > 1) {
            throw FeatureNotImplemented::multipleRootEntities();
        }

        if (! $rsm->isSelect) {
            throw FeatureNotImplemented::nonSelectStatements();
        }

        if (($hints[SqlWalker::HINT_PARTIAL] ?? false) === true || ($hints[Query::HINT_FORCE_PARTIAL_LOAD] ?? false) === true) {
            throw FeatureNotImplemented::partialEntities();
        }

        if (! ($key->cacheMode & Cache::MODE_PUT) && ! ($key->cacheMode & Cache::MODE_REFRESH)) {
            return false;
        }

        $data       = [];
        $entityName = reset($rsm->aliasMap);
        $rootAlias  = key($rsm->aliasMap);
        $persister  = $this->uow->getEntityPersister($entityName);

        if (! $persister instanceof CachedEntityPersister) {
            throw NonCacheableEntity::fromEntity($entityName);
        }

        $region = $persister->getCacheRegion();

        $cm = $this->em->getClassMetadata($entityName);

        foreach ($result as $index => $entity) {
            $identifier = $this->uow->getEntityIdentifier($entity);
            $entityKey  = new EntityCacheKey($cm->rootEntityName, $identifier);

            if (($key->cacheMode & Cache::MODE_REFRESH) || ! $region->contains($entityKey)) {
                // Cancel put result if entity put fail
                if (! $persister->storeEntityCache($entity, $entityKey)) {
                    return false;
                }
            }

            $data[$index]['identifier']   = $identifier;
            $data[$index]['associations'] = [];

            // @TODO - move to cache hydration components
            foreach ($rsm->relationMap as $alias => $name) {
                $parentAlias = $rsm->parentAliasMap[$alias];
                $parentClass = $rsm->aliasMap[$parentAlias];
                $metadata    = $this->em->getClassMetadata($parentClass);
                $assoc       = $metadata->associationMappings[$name];
                $assocValue  = $this->getAssociationValue($rsm, $alias, $entity);

                if ($assocValue === null) {
                    continue;
                }

                // root entity association
                if ($rootAlias === $parentAlias) {
                    // Cancel put result if association put fail
                    $assocInfo = $this->storeAssociationCache($key, $assoc, $assocValue);
                    if ($assocInfo === null) {
                        return false;
                    }

                    $data[$index]['associations'][$name] = $assocInfo;

                    continue;
                }

                // store single nested association
                if (! is_array($assocValue)) {
                    // Cancel put result if association put fail
                    if ($this->storeAssociationCache($key, $assoc, $assocValue) === null) {
                        return false;
                    }

                    continue;
                }

                // store array of nested association
                foreach ($assocValue as $aVal) {
                    // Cancel put result if association put fail
                    if ($this->storeAssociationCache($key, $assoc, $aVal) === null) {
                        return false;
                    }
                }
            }
        }

        return $this->region->put($key, new QueryCacheEntry($data));
    }

    /**
     * @return mixed[]|null
     * @phpstan-return array{targetEntity: class-string, type: mixed, list?: array[], identifier?: array}|null
     */
    private function storeAssociationCache(QueryCacheKey $key, AssociationMapping $assoc, mixed $assocValue): array|null
    {
        $assocPersister = $this->uow->getEntityPersister($assoc->targetEntity);
        $assocMetadata  = $assocPersister->getClassMetadata();
        $assocRegion    = $assocPersister->getCacheRegion();

        // Handle *-to-one associations
        if ($assoc->isToOne()) {
            $assocIdentifier = $this->uow->getEntityIdentifier($assocValue);
            $entityKey       = new EntityCacheKey($assocMetadata->rootEntityName, $assocIdentifier);

            if (! $this->uow->isUninitializedObject($assocValue) && ($key->cacheMode & Cache::MODE_REFRESH) || ! $assocRegion->contains($entityKey)) {
                // Entity put fail
                if (! $assocPersister->storeEntityCache($assocValue, $entityKey)) {
                    return null;
                }
            }

            return [
                'targetEntity'  => $assocMetadata->rootEntityName,
                'identifier'    => $assocIdentifier,
                'type'          => $assoc->type(),
            ];
        }

        // Handle *-to-many associations
        $list = [];

        foreach ($assocValue as $assocItemIndex => $assocItem) {
            $assocIdentifier = $this->uow->getEntityIdentifier($assocItem);
            $entityKey       = new EntityCacheKey($assocMetadata->rootEntityName, $assocIdentifier);

            if (($key->cacheMode & Cache::MODE_REFRESH) || ! $assocRegion->contains($entityKey)) {
                // Entity put fail
                if (! $assocPersister->storeEntityCache($assocItem, $entityKey)) {
                    return null;
                }
            }

            $list[$assocItemIndex] = $assocIdentifier;
        }

        return [
            'targetEntity'  => $assocMetadata->rootEntityName,
            'type'          => $assoc->type(),
            'list'          => $list,
        ];
    }

    /** @phpstan-return list<mixed>|object|null */
    private function getAssociationValue(
        ResultSetMapping $rsm,
        string $assocAlias,
        object $entity,
    ): array|object|null {
        $path  = [];
        $alias = $assocAlias;

        while (isset($rsm->parentAliasMap[$alias])) {
            $parent = $rsm->parentAliasMap[$alias];
            $field  = $rsm->relationMap[$alias];
            $class  = $rsm->aliasMap[$parent];

            array_unshift($path, [
                'field'  => $field,
                'class'  => $class,
            ]);

            $alias = $parent;
        }

        return $this->getAssociationPathValue($entity, $path);
    }

    /**
     * @phpstan-param array<array-key, array{field: string, class: string}> $path
     *
     * @phpstan-return list<mixed>|object|null
     */
    private function getAssociationPathValue(mixed $value, array $path): array|object|null
    {
        $mapping  = array_shift($path);
        $metadata = $this->em->getClassMetadata($mapping['class']);
        $assoc    = $metadata->associationMappings[$mapping['field']];
        $value    = $metadata->getFieldValue($value, $mapping['field']);

        if ($value === null) {
            return null;
        }

        if ($path === []) {
            return $value;
        }

        // Handle *-to-one associations
        if ($assoc->isToOne()) {
            return $this->getAssociationPathValue($value, $path);
        }

        $values = [];

        foreach ($value as $item) {
            $values[] = $this->getAssociationPathValue($item, $path);
        }

        return $values;
    }

    public function clear(): bool
    {
        return $this->region->evictAll();
    }

    public function getRegion(): Region
    {
        return $this->region;
    }
}
