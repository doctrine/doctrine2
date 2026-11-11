<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use function array_map;
use function array_values;
use function sort;

/**
 * A custom collection on the inverse side of a many-to-many association,
 * extending a base class that declares the $collection property as private.
 *
 * @template-extends AbstractNamedList<Library>
 */
final class LibraryList extends AbstractNamedList
{
    /** @return list<string> alphabetically sorted */
    public function names(): array
    {
        $names = array_values(array_map(
            static fn (Library $library): string => $library->name,
            $this->getDelegatee()->toArray(),
        ));
        sort($names);

        return $names;
    }
}
