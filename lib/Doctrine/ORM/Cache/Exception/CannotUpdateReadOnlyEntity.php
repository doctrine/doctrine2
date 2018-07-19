<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use Doctrine\ORM\Cache\CacheException;
use function sprintf;

class CannotUpdateReadOnlyEntity extends \LogicException implements CacheException
{
    public static function fromEntity(string $entityName) : self
    {
        return new self(sprintf('Cannot update a readonly entity "%s"', $entityName));
    }
}
