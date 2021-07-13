<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * @Annotation
 * @Target("PROPERTY")
 */
final class JoinColumns implements Annotation
{
    /** @var array<\Doctrine\ORM\Mapping\JoinColumn> */
    public $value;
}
