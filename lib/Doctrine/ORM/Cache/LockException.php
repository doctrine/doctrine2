<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\Cache\Exception\CacheException;

/**
 * Lock exception for cache.
 */
class LockException extends CacheException
{
}
