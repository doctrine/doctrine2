<?php

declare(strict_types=1);

namespace Doctrine\ORM\Annotation;

/**
 * @Annotation
 * @Target({"PROPERTY","ANNOTATION"})
 */
final class JoinTable implements Annotation
{
    /** @var string */
    public $name;

    /** @var string */
    public $schema;

    /** @var array<\Doctrine\ORM\Annotation\JoinColumn> */
    public $joinColumns = [];

    /** @var array<\Doctrine\ORM\Annotation\JoinColumn> */
    public $inverseJoinColumns = [];
}
