<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ReflectionService;

use function sprintf;

final class ReadonlyClassMetadataBag implements ClassMetadataBag
{
    /** @param array<class-string, ClassMetadata<object>> $classMetadata */
    public function __construct(
        private readonly array $classMetadata,
    ) {
    }

    public function get(string $className): ClassMetadata
    {
        return $this->classMetadata[$className]
            ?? throw new MappingException(sprintf('No metadata found for class "%s".', $className));
    }

    /** @return array<class-string, ClassMetadata<object>> */
    public function all(): array
    {
        return $this->classMetadata;
    }

    public function has(string $className): bool
    {
        return isset($this->classMetadata[$className]);
    }

    public function wakeupReflection(ReflectionService $reflectionService): void
    {
        foreach ($this->classMetadata as $metadata) {
            $metadata->wakeupReflection($reflectionService);
        }
    }
}
