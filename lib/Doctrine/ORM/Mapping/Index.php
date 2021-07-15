<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;

/**
 * @Annotation
 * @NamedArgumentConstructor()
 * @Target("ANNOTATION")
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Index implements Annotation
{
    /** @var string */
    public $name;

    /** @var array<string> */
    public $columns;

    /** @var array<string> */
    public $fields;

    /** @var array<string> */
    public $flags;

    /** @var array<string,mixed> */
    public $options;

    /**
     * @param array<string> $columns
     * @param array<string> $fields
     * @param array<string> $flags
     * @param array<string> $options
     */
    public function __construct(
        ?array $columns = null,
        ?array $fields = null,
        ?string $name = null,
        ?array $flags = null,
        ?array $options = null
    ) {
        $this->columns = $columns;
        $this->fields  = $fields;
        $this->name    = $name;
        $this->flags   = $flags;
        $this->options = $options;
    }
}
