<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Region;

use Doctrine\ORM\Cache\CacheKey;
use Doctrine\ORM\Cache\TimestampCacheEntry;
use Doctrine\ORM\Cache\TimestampRegion;
use Override;

/**
 * Tracks the timestamps of the most recent updates to particular keys.
 */
class UpdateTimestampCache extends DefaultRegion implements TimestampRegion
{
    #[Override]
    public function update(CacheKey $key): void
    {
        $this->put($key, new TimestampCacheEntry());
    }
}
