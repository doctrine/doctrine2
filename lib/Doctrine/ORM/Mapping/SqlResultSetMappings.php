<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * Is used to specify an array of mappings.
 * The SqlResultSetMappings annotation can be applied to an entity or mapped superclass.
 *
 * @Annotation
 * @Target("CLASS")
 */
final class SqlResultSetMappings implements Annotation
{
    /**
     * One or more SqlResultSetMapping annotations.
     *
     * @var array<\Doctrine\ORM\Mapping\SqlResultSetMapping>
     */
    public $value = [];
}
