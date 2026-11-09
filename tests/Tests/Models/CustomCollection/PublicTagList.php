<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\DelegateCollection;
use IteratorAggregate;
use Traversable;

use function str_starts_with;

/**
 * A custom collection whose iteration hides internal tags (prefixed with "_").
 *
 * @template-implements IteratorAggregate<array-key, Tag>
 * @template-implements DelegateCollection<array-key, Tag>
 */
final class PublicTagList implements DelegateCollection, IteratorAggregate
{
    /** @param Collection<array-key, Tag> $collection */
    public function __construct(private Collection $collection = new ArrayCollection())
    {
    }

    public function getDelegatee(): Collection
    {
        return $this->collection;
    }

    public function getIterator(): Traversable
    {
        return $this->collection->filter(static fn (Tag $tag): bool => ! str_starts_with($tag->name, '_'))->getIterator();
    }

    public function add(Tag $tag): void
    {
        $this->collection->add($tag);
    }
}
