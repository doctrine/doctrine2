<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table implements MappingAttribute
{
    /** @param array<string,mixed> $options */
    public function __construct(
        public readonly string|null $name = null,
        public readonly string|null $schema = null,
        public readonly array $options = [],
    ) {
    }
}
