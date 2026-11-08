<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

/**
 * Maps the key of a dynamic properties container to a column.
 *
 * @see DynamicProperties
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class DynamicField implements MappingAttribute
{
    /** @param string $key The array key, which is also used as the field name. */
    public function __construct(
        public readonly string $key,
        public readonly Column $column = new Column(),
    ) {
    }
}
