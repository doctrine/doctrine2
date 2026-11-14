<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Collection;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Collection\CollectionPersister;
use Override;

use function spl_object_id;

class ReadWriteCachedCollectionPersister extends AbstractCollectionPersister
{
    public function __construct(
        CollectionPersister $persister,
        ConcurrentRegion $region,
        EntityManagerInterface $em,
        AssociationMapping $association,
    ) {
        parent::__construct($persister, $region, $em, $association);
    }

    #[Override]
    public function afterTransactionComplete(): void
    {
        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        $this->queuedCache = [];
    }

    #[Override]
    public function afterTransactionRolledBack(): void
    {
        if (isset($this->queuedCache['update'])) {
            foreach ($this->queuedCache['update'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        if (isset($this->queuedCache['delete'])) {
            foreach ($this->queuedCache['delete'] as $item) {
                $this->region->evict($item['key']);
            }
        }

        $this->queuedCache = [];
    }

    #[Override]
    public function delete(PersistentCollection $collection): void
    {
        $ownerId = $this->uow->getEntityIdentifier($collection->getOwner());
        $key     = new CollectionCacheKey($this->sourceEntity->rootEntityName, $this->association->fieldName, $ownerId, $this->filters->getHash());
        $lock    = $this->region->lock($key);

        $this->persister->delete($collection);

        if ($lock === null) {
            return;
        }

        $this->queuedCache['delete'][spl_object_id($collection)] = [
            'key'   => $key,
            'lock'  => $lock,
        ];
    }

    #[Override]
    public function update(PersistentCollection $collection): void
    {
        $isInitialized = $collection->isInitialized();
        $isDirty       = $collection->isDirty();

        if (! $isInitialized && ! $isDirty) {
            return;
        }

        $this->persister->update($collection);

        $ownerId = $this->uow->getEntityIdentifier($collection->getOwner());
        $key     = new CollectionCacheKey($this->sourceEntity->rootEntityName, $this->association->fieldName, $ownerId, $this->filters->getHash());
        $lock    = $this->region->lock($key);

        if ($lock === null) {
            return;
        }

        $this->queuedCache['update'][spl_object_id($collection)] = [
            'key'   => $key,
            'lock'  => $lock,
        ];
    }
}
