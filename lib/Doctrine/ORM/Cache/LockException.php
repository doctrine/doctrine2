<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

/**
 * Lock exception for cache.
 */
class LockException extends \RuntimeException implements CacheException
{
}
