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
final class DiscriminatorColumn implements Annotation
{
    /** @var string */
    public $name;

    /** @var string */
    public $type;

    /** @var int */
    public $length;

    /**
     * Field name used in non-object hydration (array/scalar).
     *
     * @var mixed
     */
    public $fieldName;

    /** @var string */
    public $columnDefinition;

    public function __construct(
        ?string $name = null,
        ?string $type = null,
        ?int $length = null,
        ?string $columnDefinition = null
    ) {
        $this->name             = $name;
        $this->type             = $type;
        $this->length           = $length;
        $this->columnDefinition = $columnDefinition;
    }
}
