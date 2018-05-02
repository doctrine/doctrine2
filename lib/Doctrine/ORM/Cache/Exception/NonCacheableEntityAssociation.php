<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Exception;

use function sprintf;

final class NonCacheableEntityAssociation extends \LogicException implements CacheException
{
    public static function fromEntityAndField(string $entityName, string $field) : self
    {
        return new self(sprintf(
            'Entity association field "%s#%s" not configured as part of the second-level cache.',
            $entityName,
            $field
        ));
    }
}
