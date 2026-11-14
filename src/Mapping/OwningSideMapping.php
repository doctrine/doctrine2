<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

abstract class OwningSideMapping extends AssociationMapping
{
    /**
     * required for bidirectional associations
     * The name of the field that completes the bidirectional association on
     * the inverse side. This key must be specified on the owning side of a
     * bidirectional association.
     */
    public string|null $inversedBy = null;

    /** @return list<string> */
    #[Override]
    public function __sleep(): array
    {
        $serialized = parent::__sleep();

        if ($this->inversedBy !== null) {
            $serialized[] = 'inversedBy';
        }

        return $serialized;
    }
}
