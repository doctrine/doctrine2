<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * This annotation is used to override association mapping of property for an entity relationship.
 *
 * @Annotation
 * @Target("ANNOTATION")
 */
final class AssociationOverride implements Annotation
{
    /**
     * The name of the relationship property whose mapping is being overridden.
     *
     * @var string
     */
    public $name;

    /**
     * The join column that is being mapped to the persistent attribute.
     *
     * @var array<\Doctrine\ORM\Mapping\JoinColumn>
     */
    public $joinColumns;

    /**
     * The join table that maps the relationship.
     *
     * @var \Doctrine\ORM\Mapping\JoinTable
     */
    public $joinTable;

    /**
     * The name of the association-field on the inverse-side.
     *
     * @var string
     */
    public $inversedBy;

    /**
     * The fetching strategy to use for the association.
     *
     * @var string
     * @Enum({"LAZY", "EAGER", "EXTRA_LAZY"})
     */
    public $fetch;
}
