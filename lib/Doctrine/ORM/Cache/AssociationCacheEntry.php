<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

/**
 * Association cache entry
 */
class AssociationCacheEntry implements CacheEntry
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
    public $class;

    /**
     * @param string               $class      The entity class.
     * @param array<string, mixed> $identifier The entity identifier.
     */
    public function __construct($class, array $identifier)
    {
        $this->class      = $class;
        $this->identifier = $identifier;
    }

    /**
     * Creates a new AssociationCacheEntry
     *
     * This method allow Doctrine\Common\Cache\PhpFileCache compatibility
     *
     * @param array<string, mixed> $values array containing property values
     *
     * @return AssociationCacheEntry
     */
    public static function __set_state(array $values)
    {
        return new self($values['class'], $values['identifier']);
    }
}
