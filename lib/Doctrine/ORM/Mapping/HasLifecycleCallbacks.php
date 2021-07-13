<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

/**
 * @Annotation
 * @Target("CLASS")
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class HasLifecycleCallbacks implements Annotation
{
}
