<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

/**
 * This annotation is used to override the mapping of a entity property.
 *
 * @Annotation
 * @Target("ANNOTATION")
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AttributeOverride implements Annotation
{
    /**
     * The name of the property whose mapping is being overridden.
     *
     * @var string
     */
    public $name;

    /**
     * The column definition.
     *
     * @var \Doctrine\ORM\Mapping\Column
     */
    public $column;
}
