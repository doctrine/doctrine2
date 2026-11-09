<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\DelegateCollection;
use IteratorAggregate;
use Traversable;

use function array_map;
use function array_values;
use function sort;

/**
 * A custom collection on the inverse side of a many-to-many association.
 *
 * @template-implements IteratorAggregate<array-key, Library>
 * @template-implements DelegateCollection<array-key, Library>
 */
final class LibraryList implements DelegateCollection, IteratorAggregate
{
    /** @param Collection<array-key, Library> $collection */
    public function __construct(private Collection $collection = new ArrayCollection())
    {
    }

    public function getDelegatee(): Collection
    {
        return $this->collection;
    }

    public function getIterator(): Traversable
    {
        return $this->collection->getIterator();
    }

    /** @return list<string> alphabetically sorted */
    public function names(): array
    {
        $names = array_values(array_map(
            static fn (Library $library): string => $library->name,
            $this->collection->toArray(),
        ));
        sort($names);

        return $names;
    }
}
