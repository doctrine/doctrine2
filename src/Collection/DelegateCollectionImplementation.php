<?php

declare(strict_types=1);

namespace Doctrine\ORM\Collection;

use Doctrine\Common\Collections\Criteria;

use function defined;

if (defined(Criteria::class . '::ASC')) {
    // collections 2
    /** @internal */
    trait DelegateCollectionImplementation
    {
        abstract private function doAdd(mixed $value): void;

        final public function add(mixed $value): bool
        {
            $this->doAdd($value);

            return true;
        }
    }
} else {
    // collections 3
    /** @internal */
    trait DelegateCollectionImplementation
    {
        abstract private function doAdd(mixed $value): void;

        final public function add(mixed $value): void
        {
            $this->doAdd($value);
        }
    }
}
