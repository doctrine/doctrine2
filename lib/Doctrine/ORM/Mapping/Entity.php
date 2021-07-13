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
final class Entity implements Annotation
{
    /** @var string */
    public $repositoryClass;

    /** @var bool */
    public $readOnly = false;

    public function __construct(?string $repositoryClass = null, bool $readOnly = false)
    {
        $this->repositoryClass = $repositoryClass;
        $this->readOnly        = $readOnly;
    }
}
