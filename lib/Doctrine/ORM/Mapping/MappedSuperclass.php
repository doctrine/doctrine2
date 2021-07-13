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
final class MappedSuperclass implements Annotation
{
    /** @var string */
    public $repositoryClass;

    public function __construct(?string $repositoryClass = null)
    {
        $this->repositoryClass = $repositoryClass;
    }
}
