<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * @Annotation
 * @NamedArgumentConstructor()
 * @Target("CLASS")
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DiscriminatorMap implements Annotation
{
    /** @var array<string> */
    public $value;

    /** @param array<string> $value */
    public function __construct(array $value)
    {
        $this->value = $value;
    }
}
