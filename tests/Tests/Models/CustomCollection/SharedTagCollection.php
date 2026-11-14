<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Collection\AbstractDelegateCollection;

/** @template-extends AbstractDelegateCollection<array-key, SharedTag> */
final class SharedTagCollection extends AbstractDelegateCollection
{
    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->toArray() as $tag) {
            $names[] = $tag->name;
        }

        return $names;
    }
}
