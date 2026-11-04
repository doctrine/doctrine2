<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

/**
 * Marks a class as embeddable, so that its properties are stored in the table of the entities that embed it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Embeddable implements MappingAttribute
{
}
