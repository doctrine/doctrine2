<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use Doctrine\ORM\Cache\CacheException;

final class QueryCacheNotConfigured extends \LogicException implements CacheException
{
    public static function create() : self
    {
        return new self('Query Cache is not configured.');
    }
}
