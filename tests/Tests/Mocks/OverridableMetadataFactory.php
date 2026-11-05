<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;

use function ltrim;

/**
 * A ClassMetadataFactory that lets a test inject metadata for one class via setMetadataFor(), without
 * relying on AbstractClassMetadataFactory's own implementation of that method, which doctrine/persistence
 * deprecated since 4.2. AbstractClassMetadataFactory's own loaded-metadata map is private, so the override
 * is kept in a map of its own, consulted before falling back to the parent factory's normal lazy loading.
 */
final class OverridableMetadataFactory extends ClassMetadataFactory
{
    /** @var array<class-string, ClassMetadata<object>> */
    private array $overrides = [];

    /**
     * This override is not deprecated, unlike the one it replaces.
     *
     * @param ClassMetadata<object> $class
     */
    public function setMetadataFor(string $className, ClassMetadataInterface $class): void
    {
        $this->overrides[ltrim($className, '\\')] = $class;
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        return $this->overrides[ltrim($className, '\\')] ?? parent::getMetadataFor($className);
    }

    public function hasMetadataFor(string $className): bool
    {
        return isset($this->overrides[ltrim($className, '\\')]) || parent::hasMetadataFor($className);
    }
}
