<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Collection;

use Doctrine\ORM\Cache\CollectionCacheKey;
use Doctrine\ORM\Cache\ConcurrentRegion;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Persisters\Collection\CollectionPersister;

use function spl_object_hash;

class ReadWriteCachedCollectionPersister extends AbstractCollectionPersister
{
    /**
     * @param CollectionPersister    $persister   The collection persister that will be cached.
     * @param ConcurrentRegion       $region      The collection region.
     * @param EntityManagerInterface $em          The entity manager.
     * @param mixed[]                $association The association mapping.
     */
    public function __construct(CollectionPersister $persister, ConcurrentRegion $region, EntityManagerInterface $em, array $association)
    {
        parent::__construct($persister, $region, $em, $association);
    }

    /**
     * {@inheritdoc}
     */
    public function afterTransactionComplete()
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

    /**
     * {@inheritdoc}
     */
    public function afterTransactionRolledBack()
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

    /**
     * {@inheritdoc}
     */
    public function delete(PersistentCollection $collection)
    {
        $ownerId = $this->uow->getEntityIdentifier($collection->getOwner());
        $key     = new CollectionCacheKey($this->sourceEntity->rootEntityName, $this->association['fieldName'], $ownerId);
        $lock    = $this->region->lock($key);

        $this->persister->delete($collection);

        if ($lock === null) {
            return;
        }

        $this->queuedCache['delete'][spl_object_hash($collection)] = [
            'key'   => $key,
            'lock'  => $lock,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function update(PersistentCollection $collection)
    {
        $isInitialized = $collection->isInitialized();
        $isDirty       = $collection->isDirty();

        if (! $isInitialized && ! $isDirty) {
            return;
        }

        $this->persister->update($collection);

        $ownerId = $this->uow->getEntityIdentifier($collection->getOwner());
        $key     = new CollectionCacheKey($this->sourceEntity->rootEntityName, $this->association['fieldName'], $ownerId);
        $lock    = $this->region->lock($key);

        if ($lock === null) {
            return;
        }

        $this->queuedCache['update'][spl_object_hash($collection)] = [
            'key'   => $key,
            'lock'  => $lock,
        ];
    }
}
