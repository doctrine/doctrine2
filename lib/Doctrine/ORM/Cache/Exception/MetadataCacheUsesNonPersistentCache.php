<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use Doctrine\Common\Cache\Cache;
use Doctrine\ORM\Cache\CacheException;
use function get_class;

final class MetadataCacheUsesNonPersistentCache extends \LogicException implements CacheException
{
    public static function fromDriver(Cache $cache) : self
    {
        return new self(
            'Metadata Cache uses a non-persistent cache driver, ' . get_class($cache) . '.'
        );
    }
}
