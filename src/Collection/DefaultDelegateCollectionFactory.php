<?php

declare(strict_types=1);

namespace Doctrine\ORM\Collection;

use ReflectionClass;

/**
 * Like entities, custom collections are created without calling their constructor.
 */
final class DefaultDelegateCollectionFactory implements DelegateCollectionFactory
{
    public function create(string $collectionClass): DelegateCollection
    {
        return (new ReflectionClass($collectionClass))->newInstanceWithoutConstructor();
    }
}
