<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * @Annotation
 * @NamedArgumentConstructor()
 * @Target({"PROPERTY","ANNOTATION"})
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column implements Annotation
{
    /** @var string */
    public $name;

    /** @var mixed */
    public $type;

    /** @var int */
    public $length;

    /**
     * The precision for a decimal (exact numeric) column (Applies only for decimal column).
     *
     * @var int
     */
    public $precision = 0;

    /**
     * The scale for a decimal (exact numeric) column (Applies only for decimal column).
     *
     * @var int
     */
    public $scale = 0;

    /** @var bool */
    public $unique = false;

    /** @var bool */
    public $nullable = false;

    /** @var array<string,mixed> */
    public $options = [];

    /** @var string */
    public $columnDefinition;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        ?string $name = null,
        ?string $type = null,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        bool $unique = false,
        bool $nullable = false,
        array $options = [],
        ?string $columnDefinition = null
    ) {
        $this->name             = $name;
        $this->type             = $type;
        $this->length           = $length;
        $this->precision        = $precision;
        $this->scale            = $scale;
        $this->unique           = $unique;
        $this->nullable         = $nullable;
        $this->options          = $options;
        $this->columnDefinition = $columnDefinition;
    }
}
