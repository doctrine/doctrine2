<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * This annotation is used to override the mapping of a entity property.
 *
 * @Annotation
 * @Target("CLASS")
 */
final class AttributeOverrides implements Annotation
{
    /**
     * One or more field or property mapping overrides.
     *
     * @var array<\Doctrine\ORM\Mapping\AttributeOverride>
     */
    public $value;
}
