<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

final class InvalidResultCacheDriver extends \LogicException implements CacheException
{
    public static function new() : self
    {
        return new self(
            'Invalid result cache driver; it must implement Doctrine\\Common\\Cache\\Cache.'
        );
    }
}
