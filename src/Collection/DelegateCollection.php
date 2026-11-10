<?php

declare(strict_types=1);

namespace Doctrine\ORM\Collection;

use IteratorAggregate;

/**
 * Marks a custom collection class for to-many associations: the ORM stores the
 * managed collection in its $collection property, typed {@see \Doctrine\Common\Collections\Collection}.
 *
 * This contract is specific to the ORM: Doctrine MongoDB ODM custom collections
 * extend ArrayCollection instead.
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/association-mapping.html#custom-collection-classes
 *
 * @phpstan-template TKey of array-key
 * @phpstan-template T
 * @template-extends IteratorAggregate<TKey, T>
 */
interface DelegateCollection extends IteratorAggregate
{
}
