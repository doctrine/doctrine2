<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Cache;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\UnitOfWork;

use function is_array;
use function is_object;

/**
 * Provides an API for querying/managing the second level cache regions.
 */
class DefaultCache implements Cache
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var UnitOfWork */
    private $uow;

     /** @var CacheFactory */
    private $cacheFactory;

    /** @var QueryCache[] */
    private $queryCaches = [];

    /** @var QueryCache|null */
    private $defaultQueryCache;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em           = $em;
        $this->uow          = $em->getUnitOfWork();
        $this->cacheFactory = $em->getConfiguration()
            ->getSecondLevelCacheConfiguration()
            ->getCacheFactory();
    }

    /**
     * {@inheritdoc}
     */
    public function getEntityCacheRegion($className)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return null;
        }

        return $persister->getCacheRegion();
    }

    /**
     * {@inheritdoc}
     */
    public function getCollectionCacheRegion($className, $association)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return null;
        }

        return $persister->getCacheRegion();
    }

    /**
     * {@inheritdoc}
     */
    public function containsEntity($className, $identifier)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return false;
        }

        return $persister->getCacheRegion()->contains($this->buildEntityCacheKey($metadata, $identifier));
    }

    /**
     * {@inheritdoc}
     */
    public function evictEntity($className, $identifier)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evict($this->buildEntityCacheKey($metadata, $identifier));
    }

    /**
     * {@inheritdoc}
     */
    public function evictEntityRegion($className)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getEntityPersister($metadata->rootEntityName);

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evictAll();
    }

    /**
     * {@inheritdoc}
     */
    public function evictEntityRegions()
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

    /**
     * {@inheritdoc}
     */
    public function containsCollection($className, $association, $ownerIdentifier)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return false;
        }

        return $persister->getCacheRegion()->contains($this->buildCollectionCacheKey($metadata, $association, $ownerIdentifier));
    }

    /**
     * {@inheritdoc}
     */
    public function evictCollection($className, $association, $ownerIdentifier)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evict($this->buildCollectionCacheKey($metadata, $association, $ownerIdentifier));
    }

    /**
     * {@inheritdoc}
     */
    public function evictCollectionRegion($className, $association)
    {
        $metadata  = $this->em->getClassMetadata($className);
        $persister = $this->uow->getCollectionPersister($metadata->getAssociationMapping($association));

        if (! ($persister instanceof CachedPersister)) {
            return;
        }

        $persister->getCacheRegion()->evictAll();
    }

    /**
     * {@inheritdoc}
     */
    public function evictCollectionRegions()
    {
        $metadatas = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ($metadatas as $metadata) {
            foreach ($metadata->associationMappings as $association) {
                if (! $association['type'] & ClassMetadata::TO_MANY) {
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

    /**
     * {@inheritdoc}
     */
    public function containsQuery($regionName)
    {
        return isset($this->queryCaches[$regionName]);
    }

    /**
     * {@inheritdoc}
     */
    public function evictQueryRegion($regionName = null)
    {
        if ($regionName === null && $this->defaultQueryCache !== null) {
            $this->defaultQueryCache->clear();

            return;
        }

        if (isset($this->queryCaches[$regionName])) {
            $this->queryCaches[$regionName]->clear();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function evictQueryRegions()
    {
        $this->getQueryCache()->clear();

        foreach ($this->queryCaches as $queryCache) {
            $queryCache->clear();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getQueryCache($regionName = null)
    {
        if ($regionName === null) {
            return $this->defaultQueryCache ?:
                $this->defaultQueryCache = $this->cacheFactory->buildQueryCache($this->em);
        }

        if (! isset($this->queryCaches[$regionName])) {
            $this->queryCaches[$regionName] = $this->cacheFactory->buildQueryCache($this->em, $regionName);
        }

        return $this->queryCaches[$regionName];
    }

    /**
     * @param ClassMetadata $metadata   The entity metadata.
     * @param mixed         $identifier The entity identifier.
     */
    private function buildEntityCacheKey(ClassMetadata $metadata, $identifier): EntityCacheKey
    {
        if (! is_array($identifier)) {
            $identifier = $this->toIdentifierArray($metadata, $identifier);
        }

        return new EntityCacheKey($metadata->rootEntityName, $identifier);
    }

    /**
     * @param ClassMetadata $metadata        The entity metadata.
     * @param string        $association     The field name that represents the association.
     * @param mixed         $ownerIdentifier The identifier of the owning entity.
     */
    private function buildCollectionCacheKey(
        ClassMetadata $metadata,
        string $association,
        $ownerIdentifier
    ): CollectionCacheKey {
        if (! is_array($ownerIdentifier)) {
            $ownerIdentifier = $this->toIdentifierArray($metadata, $ownerIdentifier);
        }

        return new CollectionCacheKey($metadata->rootEntityName, $association, $ownerIdentifier);
    }

    /**
     * @param ClassMetadata $metadata   The entity metadata.
     * @param mixed         $identifier The entity identifier.
     *
     * @return array<string, mixed>
     */
    private function toIdentifierArray(ClassMetadata $metadata, $identifier): array
    {
        if (is_object($identifier) && $this->em->getMetadataFactory()->hasMetadataFor(ClassUtils::getClass($identifier))) {
            $identifier = $this->uow->getSingleIdentifierValue($identifier);

            if ($identifier === null) {
                throw ORMInvalidArgumentException::invalidIdentifierBindingEntity();
            }
        }

        return [$metadata->identifier[0] => $identifier];
    }
}
