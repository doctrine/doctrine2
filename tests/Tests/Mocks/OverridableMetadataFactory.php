<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;

use function ltrim;

final class OverridableMetadataFactory extends ClassMetadataFactory
{
    /** @var array<class-string, ClassMetadata<object>> */
    private array $overrides = [];

    /** @param ClassMetadata<object> $class */
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
