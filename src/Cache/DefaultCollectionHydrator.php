<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use Override;

use function assert;

/**
 * Default hydrator cache for collections
 */
class DefaultCollectionHydrator implements CollectionHydrator
{
    private readonly UnitOfWork $uow;

    /** @var array<string,mixed> */
    private static array $hints = [Query::HINT_CACHE_ENABLED => true];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->uow = $em->getUnitOfWork();
    }

    #[Override]
    public function buildCacheEntry(ClassMetadata $metadata, CollectionCacheKey $key, array|Collection $collection): CollectionCacheEntry
    {
        $data = [];

        foreach ($collection as $index => $entity) {
            $data[$index] = new EntityCacheKey($metadata->rootEntityName, $this->uow->getEntityIdentifier($entity));
        }

        return new CollectionCacheEntry($data);
    }

    #[Override]
    public function loadCacheEntry(ClassMetadata $metadata, CollectionCacheKey $key, CollectionCacheEntry $entry, PersistentCollection $collection): array|null
    {
        $assoc           = $metadata->associationMappings[$key->association];
        $targetPersister = $this->uow->getEntityPersister($assoc->targetEntity);
        assert($targetPersister instanceof CachedPersister);
        $targetRegion = $targetPersister->getCacheRegion();
        $list         = [];

        /** @var EntityCacheEntry[]|null $entityEntries */
        $entityEntries = $targetRegion->getMultiple($entry);

        if ($entityEntries === null) {
            return null;
        }

        foreach ($entityEntries as $index => $entityEntry) {
            $entity = $this->uow->createEntity(
                $entityEntry->class,
                $entityEntry->resolveAssociationEntries($this->em),
                self::$hints,
            );

            $collection->hydrateSet($index, $entity);

            $list[$index] = $entity;
        }

        $this->uow->hydrationComplete();

        return $list;
    }
}
