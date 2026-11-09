<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\ORM\Collection\DelegateCollection;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToMany implements MappingAttribute
{
    /**
     * @param class-string|null                                       $targetEntity
     * @param string[]|null                                           $cascade
     * @param class-string<DelegateCollection<array-key, mixed>>|null $collectionClass
     * @phpstan-param 'LAZY'|'EAGER'|'EXTRA_LAZY' $fetch
     */
    public function __construct(
        public readonly string|null $targetEntity = null,
        public readonly string|null $mappedBy = null,
        public readonly array|null $cascade = null,
        public readonly string $fetch = 'LAZY',
        public readonly bool $orphanRemoval = false,
        public readonly string|null $indexBy = null,
        public readonly string|null $collectionClass = null,
    ) {
    }
}
