<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use Doctrine\ORM\Cache\CacheException;

final class MetadataCacheNotConfigured extends \LogicException implements CacheException
{
    public static function create() : self
    {
        return new self('Class Metadata Cache is not configured.');
    }
}
