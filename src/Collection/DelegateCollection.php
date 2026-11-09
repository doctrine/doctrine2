<?php

declare(strict_types=1);

namespace Doctrine\ORM\Collection;

use IteratorAggregate;

/**
 * Marks user-defined custom collection classes used on to-many associations.
 *
 * The ORM stores the managed collection (a {@see \Doctrine\ORM\PersistentCollection})
 * inside the implementing object and only ever interacts with that underlying
 * collection. The implementing class is free to expose any API it wants — it
 * does not have to implement {@see \Doctrine\Common\Collections\Collection}
 * itself — as long as it follows these rules:
 *
 * - The class must declare exactly one non-static, non-readonly property
 *   whose type is exactly {@see \Doctrine\Common\Collections\Collection}
 *   (nullable allowed). The ORM reads and writes the managed collection in
 *   that property through reflection, exactly like it hydrates entity fields.
 * - That property is the single source of truth for the elements. All reads
 *   and writes performed by the implementing class must go through it. State
 *   kept outside of it (arrays, cached copies, ...) is invisible to the ORM
 *   and will not be persisted.
 * - Like entities, the class is instantiated without calling its constructor
 *   when the ORM creates an instance during hydration: state initialized only
 *   in the constructor is not available then.
 * - The underlying collection must be read from the property on each call:
 *   do not keep it in another property or a closure, as the ORM replaces it
 *   on the first flush and on refresh.
 *
 * @phpstan-template TKey of array-key
 * @phpstan-template T
 * @template-extends IteratorAggregate<TKey, T>
 */
interface DelegateCollection extends IteratorAggregate
{
}
