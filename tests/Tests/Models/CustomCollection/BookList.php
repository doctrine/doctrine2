<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Countable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\DelegateCollection;
use IteratorAggregate;
use Traversable;

use function array_map;
use function array_values;
use function sort;

/**
 * A custom collection exposing only a domain-specific API: it implements the
 * DelegateCollection contract directly, without being a Collection itself.
 *
 * @template-implements IteratorAggregate<array-key, Book>
 * @template-implements DelegateCollection<array-key, Book>
 */
final class BookList implements Countable, DelegateCollection, IteratorAggregate
{
    /** @param Collection<array-key, Book> $collection */
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

    public function count(): int
    {
        return $this->collection->count();
    }

    public function addBook(Book $book): void
    {
        $this->collection->add($book);
    }

    public function removeBook(Book $book): void
    {
        $this->collection->removeElement($book);
    }

    /** @return list<string> alphabetically sorted */
    public function titles(): array
    {
        $titles = array_values(array_map(
            static fn (Book $book): string => $book->title,
            $this->collection->toArray(),
        ));
        sort($titles);

        return $titles;
    }

    public function __clone()
    {
        $this->collection = clone $this->collection;
    }
}
