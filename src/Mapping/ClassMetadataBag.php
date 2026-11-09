<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

interface ClassMetadataBag
{
    /**
     * @param class-string $className
     *
     * @return ClassMetadata<object>
     */
    public function get(string $className): ClassMetadata;

    /** @return array<class-string, ClassMetadata<object>> */
    public function all(): array;

    /** @param class-string $className */
    public function has(string $className): bool;
}
