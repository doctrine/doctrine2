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
final class GeneratedValue implements Annotation
{
    /**
     * The type of Id generator.
     *
     * @var string
     * @Enum({"AUTO", "SEQUENCE", "TABLE", "IDENTITY", "NONE", "UUID", "CUSTOM"})
     */
    public $strategy = 'AUTO';

    public function __construct(string $strategy = 'AUTO')
    {
        $this->strategy = $strategy;
    }
}
