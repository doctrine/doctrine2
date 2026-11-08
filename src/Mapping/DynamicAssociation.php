<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use SortDirection;

/**
 * Maps the key of a dynamic properties container to an association.
 *
 * Inverse side one-to-one associations are not supported.
 *
 * @see DynamicProperties
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class DynamicAssociation implements MappingAttribute
{
    /**
     * @param string                                         $key         The array key, which is also used as the field name.
     * @param list<JoinColumn>                               $joinColumns Only for to-one associations.
     * @param JoinTable|null                                 $joinTable   Only for many-to-many associations.
     * @param array<string, SortDirection|'ASC'|'DESC'>|null $orderBy     Only for to-many associations.
     */
    public function __construct(
        public readonly string $key,
        public readonly ManyToOne|OneToOne|OneToMany|ManyToMany $association,
        public readonly array $joinColumns = [],
        public readonly JoinTable|null $joinTable = null,
        public readonly array|null $orderBy = null,
    ) {
    }
}
