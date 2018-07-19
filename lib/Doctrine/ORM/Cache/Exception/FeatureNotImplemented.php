<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use Doctrine\ORM\Cache\CacheException;

class FeatureNotImplemented extends \LogicException implements CacheException
{
    public static function scalarResults() : self
    {
        return new self('Second level cache does not support scalar results.');
    }

    public static function multipleRootEntities() : self
    {
        return new self('Second level cache does not support multiple root entities.');
    }

    public static function nonSelectStatements() : self
    {
        return new self('Second-level cache query supports only select statements.');
    }

    public static function partialEntities() : self
    {
        return new self('Second level cache does not support partial entities.');
    }
}
