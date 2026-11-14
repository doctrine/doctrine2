<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

abstract class InverseSideMapping extends AssociationMapping
{
    /**
     * required for bidirectional associations
     * The name of the field that completes the bidirectional association on
     * the owning side. This key must be specified on the inverse side of a
     * bidirectional association.
     */
    public string $mappedBy;

    final public function backRefFieldName(): string
    {
        return $this->mappedBy;
    }

    /** @return list<string> */
    #[Override]
    public function __sleep(): array
    {
        return [
            ...parent::__sleep(),
            'mappedBy',
        ];
    }
}
