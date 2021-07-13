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
final class OneToMany implements Annotation
{
    /** @var string */
    public $mappedBy;

    /** @var string */
    public $targetEntity;

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

    /** @var string */
    public $indexBy;

    /**
     * @param array<string> $cascade
     */
    public function __construct(
        ?string $mappedBy = null,
        ?string $targetEntity = null,
        ?array $cascade = null,
        string $fetch = 'LAZY',
        bool $orphanRemoval = false,
        ?string $indexBy = null
    ) {
        $this->mappedBy      = $mappedBy;
        $this->targetEntity  = $targetEntity;
        $this->cascade       = $cascade;
        $this->fetch         = $fetch;
        $this->orphanRemoval = $orphanRemoval;
        $this->indexBy       = $indexBy;
    }
}
