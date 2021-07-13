<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * References name of a column in the SELECT clause of a SQL query.
 * Scalar result types can be included in the query result by specifying this annotation in the metadata.
 *
 * @Annotation
 * @Target("ANNOTATION")
 */
final class ColumnResult implements Annotation
{
    /**
     * The name of a column in the SELECT clause of a SQL query.
     *
     * @var string
     */
    public $name;
}
