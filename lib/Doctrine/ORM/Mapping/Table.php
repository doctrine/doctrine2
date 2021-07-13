<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * @Annotation
 * @NamedArgumentConstructor
 * @Target("CLASS")
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table implements Annotation
{
    /** @var string */
    public $name;

    /** @var string */
    public $schema;

    /** @var array<\Doctrine\ORM\Mapping\Index> */
    public $indexes;

    /** @var array<\Doctrine\ORM\Mapping\UniqueConstraint> */
    public $uniqueConstraints;

    /** @var array<string,mixed> */
    public $options = [];

    /**
     * @param array<\Doctrine\ORM\Mapping\Index>            $indexes
     * @param array<\Doctrine\ORM\Mapping\UniqueConstraint> $uniqueConstraints
     * @param array<string,mixed>                           $options
     */
    public function __construct(
        ?string $name = null,
        ?string $schema = null,
        ?array $indexes = null,
        ?array $uniqueConstraints = null,
        array $options = []
    ) {
        $this->name              = $name;
        $this->schema            = $schema;
        $this->indexes           = $indexes;
        $this->uniqueConstraints = $uniqueConstraints;
        $this->options           = $options;
    }
}
