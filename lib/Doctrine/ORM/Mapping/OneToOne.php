<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * @Annotation
 * @NamedArgumentConstructor()
 * @Target("PROPERTY")
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToOne implements Annotation
{
    /** @var string */
    public $targetEntity;

    /** @var string */
    public $mappedBy;

    /** @var string */
    public $inversedBy;

    /** @var array<string> */
    public $cascade;

    /**
     * The fetching strategy to use for the association.
     *
     * @var string
     * @Enum({"LAZY", "EAGER", "EXTRA_LAZY"})
     */
    public $fetch = 'LAZY';

    /** @var bool */
    public $orphanRemoval = false;

    /**
     * @param array<string> $cascade
     */
    public function __construct(
        ?string $mappedBy = null,
        ?string $inversedBy = null,
        ?string $targetEntity = null,
        ?array $cascade = null,
        string $fetch = 'LAZY',
        bool $orphanRemoval = false
    ) {
        $this->mappedBy      = $mappedBy;
        $this->inversedBy    = $inversedBy;
        $this->targetEntity  = $targetEntity;
        $this->cascade       = $cascade;
        $this->fetch         = $fetch;
        $this->orphanRemoval = $orphanRemoval;
    }
}
