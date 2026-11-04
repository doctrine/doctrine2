<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Attribute;
use Doctrine\ORM\EntityRepository;

/**
 * Marks a class as an entity, whose objects are stored in a database table.
 *
 * @template T of object
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Entity implements MappingAttribute
{
    /** @phpstan-param class-string<EntityRepository<T>>|null $repositoryClass */
    public function __construct(
        public readonly string|null $repositoryClass = null,
        public readonly bool $readOnly = false,
    ) {
    }
}
