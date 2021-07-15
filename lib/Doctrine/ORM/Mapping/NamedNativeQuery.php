<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * Is used to specify a native SQL named query.
 * The NamedNativeQuery annotation can be applied to an entity or mapped superclass.
 *
 * @Annotation
 * @Target("ANNOTATION")
 */
final class NamedNativeQuery implements Annotation
{
    /**
     * The name used to refer to the query with the EntityManager methods that create query objects.
     *
     * @var string
     */
    public $name;

    /**
     * The SQL query string.
     *
     * @var string
     */
    public $query;

    /**
     * The class of the result.
     *
     * @var string
     */
    public $resultClass;

    /**
     * The name of a SqlResultSetMapping, as defined in metadata.
     *
     * @var string
     */
    public $resultSetMapping;
}
