<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Hydrator cache entry for entities
 */
interface EntityHydrator
{
    /**
     * @param ClassMetadata  $metadata The entity metadata.
     * @param EntityCacheKey $key      The entity cache key.
     * @param object         $entity   The entity.
     *
     * @return EntityCacheEntry
     */
    public function buildCacheEntry(ClassMetadata $metadata, EntityCacheKey $key, $entity);

    /**
     * @param ClassMetadata    $metadata The entity metadata.
     * @param EntityCacheKey   $key      The entity cache key.
     * @param EntityCacheEntry $entry    The entity cache entry.
     * @param object           $entity   The entity to load the cache into. If not specified, a new entity is created.
     */
    public function loadCacheEntry(ClassMetadata $metadata, EntityCacheKey $key, EntityCacheEntry $entry, $entity = null);
}
