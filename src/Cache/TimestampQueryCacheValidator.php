<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Override;

use function microtime;

class TimestampQueryCacheValidator implements QueryCacheValidator
{
    public function __construct(private readonly TimestampRegion $timestampRegion)
    {
    }

    #[Override]
    public function isValid(QueryCacheKey $key, QueryCacheEntry $entry): bool
    {
        if ($this->regionUpdated($key, $entry)) {
            return false;
        }

        if ($key->lifetime === 0) {
            return true;
        }

        return $entry->time + $key->lifetime > microtime(true);
    }

    private function regionUpdated(QueryCacheKey $key, QueryCacheEntry $entry): bool
    {
        if ($key->timestampKey === null) {
            return false;
        }

        $timestamp = $this->timestampRegion->get($key->timestampKey);

        return $timestamp && $timestamp->time > $entry->time;
    }
}
