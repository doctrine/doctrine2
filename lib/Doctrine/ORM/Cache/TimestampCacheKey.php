<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

/**
 * A key that identifies a timestamped space.
 */
class TimestampCacheKey extends CacheKey
{
    /**
     * @param string $space Result cache id
     */
    public function __construct($space)
    {
        $this->hash = (string) $space;
    }
}
