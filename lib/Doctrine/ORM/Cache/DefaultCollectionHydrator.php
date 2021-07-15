<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;

use function array_walk;
use function assert;

/**
 * Default hydrator cache for collections
 */
class DefaultCollectionHydrator implements CollectionHydrator
{
    /** @var EntityManagerInterface */
    private $em;

    /** @var UnitOfWork */
    private $uow;

    /** @var array<string,mixed> */
    private static $hints = [Query::HINT_CACHE_ENABLED => true];

    /**
     * @param EntityManagerInterface $em The entity manager.
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em  = $em;
        $this->uow = $em->getUnitOfWork();
    }

    /**
     * {@inheritdoc}
     */
    public function buildCacheEntry(ClassMetadata $metadata, CollectionCacheKey $key, $collection)
    {
        $data = [];

        foreach ($collection as $index => $entity) {
            $data[$index] = new EntityCacheKey($metadata->rootEntityName, $this->uow->getEntityIdentifier($entity));
        }

        return new CollectionCacheEntry($data);
    }

    /**
     * {@inheritdoc}
     */
    public function loadCacheEntry(ClassMetadata $metadata, CollectionCacheKey $key, CollectionCacheEntry $entry, PersistentCollection $collection)
    {
        $assoc           = $metadata->associationMappings[$key->association];
        $targetPersister = $this->uow->getEntityPersister($assoc['targetEntity']);
        assert($targetPersister instanceof CachedPersister);
        $targetRegion = $targetPersister->getCacheRegion();
        $list         = [];

        /** @var EntityCacheEntry[]|null $entityEntries */
        $entityEntries = $targetRegion->getMultiple($entry);

        if ($entityEntries === null) {
            return null;
        }

        foreach ($entityEntries as $index => $entityEntry) {
            $list[$index] = $this->uow->createEntity($entityEntry->class, $entityEntry->resolveAssociationEntries($this->em), self::$hints);
        }

        array_walk($list, static function ($entity, $index) use ($collection) {
            $collection->hydrateSet($index, $entity);
        });

        $this->uow->hydrationComplete();

        return $list;
    }
}
