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
final class UniqueConstraint implements Annotation
{
    /** @var string */
    public $name;

    /** @var array<string> */
    public $columns;

    /** @var array<string> */
    public $fields;

    /** @var array<string,mixed> */
    public $options;

    /**
     * @param array<string>       $columns
     * @param array<string>       $fields
     * @param array<string,mixed> $options
     */
    public function __construct(
        ?string $name = null,
        ?array $columns = null,
        ?array $fields = null,
        ?array $options = null
    ) {
        $this->name    = $name;
        $this->columns = $columns;
        $this->fields  = $fields;
        $this->options = $options;
    }
}
