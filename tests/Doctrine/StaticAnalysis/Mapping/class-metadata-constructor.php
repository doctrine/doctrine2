<?php

declare(strict_types=1);

namespace Doctrine\StaticAnalysis\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * @template T of object
 */
class MetadataGenerator
{
    /**
     * @psalm-param class-string<T> $entityName
     *
     * @psalm-return ClassMetadata<T>
     */
    public function createMetadata(string $entityName): ClassMetadata
    {
        return new ClassMetadata($entityName);
    }
}
