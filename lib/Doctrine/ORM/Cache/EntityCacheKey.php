<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use function implode;
use function ksort;
use function str_replace;
use function strtolower;

/**
 * Defines entity classes roles to be stored in the cache region.
 */
class EntityCacheKey extends CacheKey
{
    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var array<string, mixed> The entity identifier
     */
    public $identifier;

    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var string The entity class name
     */
    public $entityClass;

    /**
     * @param string               $entityClass The entity class name. In a inheritance hierarchy it should always be the root entity class.
     * @param array<string, mixed> $identifier  The entity identifier
     */
    public function __construct($entityClass, array $identifier)
    {
        ksort($identifier);

        $this->identifier  = $identifier;
        $this->entityClass = $entityClass;
        $this->hash        = str_replace('\\', '.', strtolower($entityClass) . '_' . implode(' ', $identifier));
    }
}
