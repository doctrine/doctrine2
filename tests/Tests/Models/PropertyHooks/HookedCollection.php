<?php
// phpcs:ignoreFile

declare(strict_types=1);

namespace Doctrine\Tests\Models\PropertyHooks;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Collection\DelegateCollection;
use Traversable;

/**
 * A custom collection whose $collection property has a hook.
 *
 * @template-implements DelegateCollection<array-key, mixed>
 */
final class HookedCollection implements DelegateCollection
{
    /** @var Collection<array-key, mixed> */
    private Collection $collection {
        get => $this->collection;
    }

    public function __construct()
    {
        $this->collection = new ArrayCollection();
    }

    public function getIterator(): Traversable
    {
        return $this->collection->getIterator();
    }
}
