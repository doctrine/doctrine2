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
final class ManyToOne implements Annotation
{
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

    /** @var string */
    public $inversedBy;

    /**
     * @param array<string> $cascade
     */
    public function __construct(
        ?string $targetEntity = null,
        ?array $cascade = null,
        string $fetch = 'LAZY',
        ?string $inversedBy = null
    ) {
        $this->targetEntity = $targetEntity;
        $this->cascade      = $cascade;
        $this->fetch        = $fetch;
        $this->inversedBy   = $inversedBy;
    }
}
