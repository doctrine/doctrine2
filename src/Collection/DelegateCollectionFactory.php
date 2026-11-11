<?php

declare(strict_types=1);

namespace Doctrine\ORM\Collection;

/**
 * Creates the custom collections of loaded entities. Use a custom factory when
 * a collection needs dependencies, for instance services from a container.
 */
interface DelegateCollectionFactory
{
    /**
     * @param class-string<DelegateCollection<array-key, mixed>> $collectionClass
     *
     * @return DelegateCollection<array-key, mixed>
     */
    public function create(string $collectionClass): DelegateCollection;
}
