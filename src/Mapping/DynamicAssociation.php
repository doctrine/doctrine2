<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

/**
 * Maps the key of a dynamic properties container to an owning side to-one association.
 *
 * @see DynamicProperties
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class DynamicAssociation implements MappingAttribute
{
    /**
     * @param string           $key         The array key, which is also used as the field name.
     * @param list<JoinColumn> $joinColumns
     */
    public function __construct(
        public readonly string $key,
        public readonly ManyToOne|OneToOne $association,
        public readonly array $joinColumns = [],
    ) {
    }
}
