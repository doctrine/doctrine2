<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

/**
 * Collection cache entry
 */
class CollectionCacheEntry implements CacheEntry
{
    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var CacheKey[] The list of entity identifiers hold by the collection
     */
    public $identifiers;

    /**
     * @param CacheKey[] $identifiers List of entity identifiers hold by the collection
     */
    public function __construct(array $identifiers)
    {
        $this->identifiers = $identifiers;
    }

    /**
     * Creates a new CollectionCacheEntry
     *
     * This method allows for Doctrine\Common\Cache\PhpFileCache compatibility
     *
     * @param array<string, mixed> $values array containing property values
     *
     * @return CollectionCacheEntry
     */
    public static function __set_state(array $values)
    {
        return new self($values['identifiers']);
    }
}
