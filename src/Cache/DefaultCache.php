<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\Proxy\DefaultProxyClassNameResolver;
use Doctrine\ORM\UnitOfWork;

use function is_array;
use function is_object;

/**
 * Provides an API for querying/managing the second level cache regions.
 */
class DefaultCache implements Cache
{
    private readonly UnitOfWork $uow;
    private readonly CacheFactory $cacheFactory;

    /**
     * @var QueryCache[]
     * @phpstan-var array<string, QueryCache>
     */
    private array $queryCaches = [];

    private QueryCache|null $defaultQueryCache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->uow          = $em->getUnitOfWork();
        $this->cacheFactory = $em->getConfiguration()
            ->getSecondLevelCacheConfiguration()
            ->getCacheFactory();
    }

    public function getEntityCacheRegion(string $className): Region|null
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return null;
        }

        return $persister->getCacheRegion();
    }

    public function getCollectionCacheRegion(string $className, string $association): Region|null
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return null;
        }

        return $persister->getCacheRegion();
    }

    public function containsEntity(string $className, mixed $identifier): bool
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return false;
        }

        return $persister->getCacheRegion()->contains($this->buildEntityCacheKey($metadata, $identifier));
    }

    public function evictEntity(string $className, mixed $identifier): void
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evict($this->buildEntityCacheKey($metadata, $identifier));
    }

    public function evictEntityRegion(string $className): void
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evictAll();
    }

    public function evictEntityRegions(): void
    {
        $metadatas = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ($metadatas as $metadata) {
            $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

            if (! ($persister instanceof CachedPersister)) {
                continue;
            }

            $persister->getCacheRegion()->evictAll();
        }
    }

    public function containsCollection(string $className, string $association, mixed $ownerIdentifier): bool
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return false;
        }

        return $persister->getCacheRegion()->contains($this->buildCollectionCacheKey($metadata, $association, $ownerIdentifier));
    }

    public function evictCollection(string $className, string $association, mixed $ownerIdentifier): void
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evict($this->buildCollectionCacheKey($metadata, $association, $ownerIdentifier));
    }

    public function evictCollectionRegion(string $className, string $association): void
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evictAll();
    }

    public function evictCollectionRegions(): void
    {
        $metadatas = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ($metadatas as $metadata) {
            foreach ($metadata->associationMappings as $association) {
                if (! $association->isToMany()) {
                    continue;
                }

                $persister = $this->uow->getCollectionPersister($association);

                if (! ($persister instanceof CachedPersister)) {
                    continue;
                }

                $persister->getCacheRegion()->evictAll();
            }
        }
    }

    public function containsQuery(string $regionName): bool
    {
        return isset($this->queryCaches[$regionName]);
    }

    public function evictQueryRegion(string|null $regionName = null): void
    {
        if ($regionName === null && $this->defaultQueryCache !== null) {
            $this->defaultQueryCache->clear();

            return;
        }

        if (isset($this->queryCaches[$regionName])) {
            $this->queryCaches[$regionName]->clear();
        }
    }

    public function evictQueryRegions(): void
    {
        $this->getQueryCache()->clear();

        foreach ($this->queryCaches as $queryCache) {
            $queryCache->clear();
        }
    }

    public function getQueryCache(string|null $regionName = null): QueryCache
    {
        if ($regionName === null) {
            return $this->defaultQueryCache ??= $this->cacheFactory->buildQueryCache($this->em);
        }

        return $this->queryCaches[$regionName] ??= $this->cacheFactory->buildQueryCache($this->em, $regionName);
    }

    private function buildEntityCacheKey(ClassMetadata $metadata, mixed $identifier): EntityCacheKey
    {
        if (! is_array($identifier)) {
            $identifier = $this->toIdentifierArray($metadata, $identifier);
        }

        return new EntityCacheKey($metadata->rootEntityName, $identifier);
    }

    private function buildCollectionCacheKey(
        ClassMetadata $metadata,
        string $association,
        mixed $ownerIdentifier,
    ): CollectionCacheKey {
        if (! is_array($ownerIdentifier)) {
            $ownerIdentifier = $this->toIdentifierArray($metadata, $ownerIdentifier);
        }

        return new CollectionCacheKey($metadata->rootEntityName, $association, $ownerIdentifier);
    }

    /** @return array<string, mixed> */
    private function toIdentifierArray(ClassMetadata $metadata, mixed $identifier): array
    {
        if (is_object($identifier)) {
            $class = DefaultProxyClassNameResolver::getClass($identifier);
            if ($this->em->getMetadataFactory()->isMapped($class)) {
                $identifier = $this->uow->getSingleIdentifierValue($identifier)
                    ?? throw ORMInvalidArgumentException::invalidIdentifierBindingEntity($class);
            }
        }

        return [$metadata->identifier[0] => $identifier];
    }
}
