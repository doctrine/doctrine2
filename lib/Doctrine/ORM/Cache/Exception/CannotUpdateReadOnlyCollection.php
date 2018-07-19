<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use Doctrine\ORM\Cache\CacheException;
use function sprintf;

class CannotUpdateReadOnlyCollection extends \LogicException implements CacheException
{
    public static function fromEntityAndField(string $sourceEntity, string $fieldName) : self
    {
        return new self(sprintf(
            'Cannot update a readonly collection "%s#%s"',
            $sourceEntity,
            $fieldName
        ));
    }
}
