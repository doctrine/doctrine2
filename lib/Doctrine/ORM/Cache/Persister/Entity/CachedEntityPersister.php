<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\EntityCacheKey;
use Doctrine\ORM\Cache\EntityHydrator;
use Doctrine\ORM\Cache\Persister\CachedPersister;
use Doctrine\ORM\Persisters\Entity\EntityPersister;

/**
 * Interface for second level cache entity persisters.
 */
interface CachedEntityPersister extends CachedPersister, EntityPersister
{
    /**
     * @return EntityHydrator
     */
    public function getEntityHydrator();

    /**
     * @param object $entity
     *
     * @return bool
     */
    public function storeEntityCache($entity, EntityCacheKey $key);
}
