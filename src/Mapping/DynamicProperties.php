<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

/**
 * Marks an array property as a container for dynamic properties.
 *
 * Each key of the array is mapped to its own column or join column, declared
 * with {@see DynamicField} / {@see DynamicAssociation} attributes or added
 * programmatically during the loadClassMetadata event.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DynamicProperties implements MappingAttribute
{
}
