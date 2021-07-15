<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * Is used to map the columns specified in the SELECT list of the query to the properties or fields of the entity class.
 *
 * @Annotation
 * @Target("ANNOTATION")
 */
final class FieldResult implements Annotation
{
    /**
     * Name of the column in the SELECT clause.
     *
     * @var string
     */
    public $name;

    /**
     * Name of the persistent field or property of the class.
     *
     * @var string
     */
    public $column;
}
