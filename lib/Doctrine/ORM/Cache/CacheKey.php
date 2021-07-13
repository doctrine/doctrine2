<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

/**
 * Defines entity / collection / query key to be stored in the cache region.
 * Allows multiple roles to be stored in the same cache region.
 */
abstract class CacheKey
{
    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var string Unique identifier
     */
    public $hash;
}
