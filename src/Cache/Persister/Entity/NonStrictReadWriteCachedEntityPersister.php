<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\EntityCacheKey;
use Override;

/**
 * Specific non-strict read/write cached entity persister
 */
class NonStrictReadWriteCachedEntityPersister extends AbstractEntityPersister
{
    #[Override]
    public function afterTransactionComplete(): void
    {
        $isChanged = false;

        if (isset($this->queuedCache['insert'])) {
            foreach ($this->queuedCache['insert'] as $entity) {
                $isChanged = $this->updateCache($entity, $isChanged);
            }
        }

        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $entity) {
                $isChanged = $this->updateCache($entity, $isChanged);
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $key) {
                $this->region->evict($key);

                $isChanged = true;
            }
        }

        if ($isChanged) {
            $this->timestampRegion->update($this->timestampKey);
        }

        $this->queuedCache = [];
    }

    #[Override]
    public function afterTransactionRolledBack(): void
    {
        $this->queuedCache = [];
    }

    #[Override]
    public function delete(object $entity): bool
    {
        $key     = new EntityCacheKey($this->class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        $deleted = $this->persister->delete($entity);

        if ($deleted) {
            $this->region->evict($key);
        }

        $this->queuedCache['delete'][] = $key;

        return $deleted;
    }

    #[Override]
    public function update(object $entity): void
    {
        $this->persister->update($entity);

        $this->queuedCache['update'][] = $entity;
    }

    private function updateCache(object $entity, bool $isChanged): bool
    {
        $class     = $this->metadataFactory->getMetadataFor($entity::class);
        $key       = new EntityCacheKey($class->rootEntityName, $this->uow->getEntityIdentifier($entity));
        $entry     = $this->hydrator->buildCacheEntry($class, $key, $entity);
        $cached    = $this->region->put($key, $entry);
        $isChanged = $isChanged || $cached;

        if ($cached) {
            $this->cacheLogger?->entityCachePut($this->regionName, $key);
        }

        return $isChanged;
    }
}
