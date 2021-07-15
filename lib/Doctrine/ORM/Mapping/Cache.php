<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * Caching to an entity or a collection.
 *
 * @Annotation
 * @NamedArgumentConstructor()
 * @Target({"CLASS","PROPERTY"})
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class Cache implements Annotation
{
    /**
     * @Enum({"READ_ONLY", "NONSTRICT_READ_WRITE", "READ_WRITE"})
     * @var string The concurrency strategy.
     */
    public $usage = 'READ_ONLY';

    /** @var string Cache region name. */
    public $region;

    public function __construct(string $usage = 'READ_ONLY', ?string $region = null)
    {
        $this->usage  = $usage;
        $this->region = $region;
    }
}
