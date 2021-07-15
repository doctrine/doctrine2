<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister;

use Doctrine\ORM\Cache\Region;

/**
 * Interface for persister that support second level cache.
 */
interface CachedPersister
{
    /**
     * Perform whatever processing is encapsulated here after completion of the transaction.
     */
    public function afterTransactionComplete();

    /**
     * Perform whatever processing is encapsulated here after completion of the rolled-back.
     */
    public function afterTransactionRolledBack();

    /**
     * Gets the The region access.
     *
     * @return Region
     */
    public function getCacheRegion();
}
