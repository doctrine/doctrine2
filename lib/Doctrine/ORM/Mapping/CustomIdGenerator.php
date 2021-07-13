<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * @Annotation
 * @NamedArgumentConstructor
 * @Target("PROPERTY")
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class CustomIdGenerator implements Annotation
{
    /** @var string */
    public $class;

    public function __construct(?string $class = null)
    {
        $this->class = $class;
    }
}
