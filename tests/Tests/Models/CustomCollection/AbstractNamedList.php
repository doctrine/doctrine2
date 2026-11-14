<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\DelegateCollection;
use IteratorAggregate;
use Traversable;

/**
 * A user-land base class for custom collections, holding the managed
 * collection in a private property its subclasses cannot see.
 *
 * @template T
 * @template-implements IteratorAggregate<array-key, T>
 * @template-implements DelegateCollection<array-key, T>
 */
abstract class AbstractNamedList implements DelegateCollection, IteratorAggregate
{
    /** @param Collection<array-key, T> $collection */
    public function __construct(private Collection $collection = new ArrayCollection())
    {
    }

    /** @return Collection<array-key, T> */
    public function getDelegatee(): Collection
    {
        return $this->collection;
    }

    public function getIterator(): Traversable
    {
        return $this->collection->getIterator();
    }
}
