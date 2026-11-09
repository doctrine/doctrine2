<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Collection\AbstractDelegateCollection;

use function array_map;
use function array_values;

/**
 * A custom collection built on the convenience base class, exposing the full
 * Collection API in addition to its own methods.
 *
 * @template-extends AbstractDelegateCollection<array-key, Tag>
 */
final class TagCollection extends AbstractDelegateCollection
{
    /** @return list<string> */
    public function names(): array
    {
        return array_values(array_map(
            static fn (Tag $tag): string => $tag->name,
            $this->toArray(),
        ));
    }

    public function removeByName(string $name): void
    {
        foreach ($this->toArray() as $key => $tag) {
            if ($tag->name === $name) {
                $this->remove($key);
            }
        }
    }
}
