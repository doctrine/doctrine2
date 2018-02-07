<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Entity cache entry
 */
class EntityCacheEntry implements CacheEntry
{
    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var mixed[] The entity map data
     */
    public $data;

    /**
     * READ-ONLY: Public only for performance reasons, it should be considered immutable.
     *
     * @var string The entity class name
     */
    public $class;

    /**
     * @param string  $class The entity class.
     * @param mixed[] $data  The entity data.
     */
    public function __construct($class, array $data)
    {
        $this->class = $class;
        $this->data  = $data;
    }

    /**
     * Creates a new EntityCacheEntry
     *
     * This method allow Doctrine\Common\Cache\PhpFileCache compatibility
     *
     * @param mixed[] $values array containing property values
     *
     * @return EntityCacheEntry
     */
    public static function __set_state(array $values)
    {
        return new self($values['class'], $values['data']);
    }

    /**
     * Retrieves the entity data resolving cache entries
     *
     * @return mixed[]
     */
    public function resolveAssociationEntries(EntityManagerInterface $em)
    {
        return array_map(function ($value) use ($em) {
            if (! ($value instanceof AssociationCacheEntry)) {
                return $value;
            }

            return $em->getReference($value->class, $value->identifier);
        }, $this->data);
    }
}
