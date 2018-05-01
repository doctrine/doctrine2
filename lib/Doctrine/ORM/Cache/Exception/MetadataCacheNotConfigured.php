<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

final class MetadataCacheNotConfigured extends \LogicException implements CacheException
{
    public static function new() : self
    {
        return new self('Class Metadata Cache is not configured.');
    }
}
