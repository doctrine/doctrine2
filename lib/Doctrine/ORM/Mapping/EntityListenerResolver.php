<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * A resolver is used to instantiate an entity listener.
 */
interface EntityListenerResolver
{
    /**
     * Clear all instances from the set, or a specific instance when given its identifier.
     *
     * @param string $className May be any arbitrary string. Name kept for BC only.
     *
     * @return void
     */
    public function clear($className = null);

    /**
     * Returns a entity listener instance for the given identifier.
     *
     * @param string $className May be any arbitrary string. Name kept for BC only.
     *
     * @return object An entity listener
     */
    public function resolve($className);

    /**
     * Register a entity listener instance.
     *
     * @param object $object An entity listener
     */
    public function register($object);
}
