<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

use function str_contains;
use function strrpos;
use function strtolower;
use function substr;

/**
 * The default NamingStrategy
 *
 * @link    www.doctrine-project.org
 */
class DefaultNamingStrategy implements NamingStrategy
{
    #[Override]
    public function classToTableName(string $className): string
    {
        if (str_contains($className, '\\')) {
            return substr($className, strrpos($className, '\\') + 1);
        }

        return $className;
    }

    #[Override]
    public function propertyToColumnName(string $propertyName, string $className): string
    {
        return $propertyName;
    }

    #[Override]
    public function embeddedFieldToColumnName(
        string $propertyName,
        string $embeddedColumnName,
        string $className,
        string $embeddedClassName,
    ): string {
        return $propertyName . '_' . $embeddedColumnName;
    }

    #[Override]
    public function referenceColumnName(): string
    {
        return 'id';
    }

    #[Override]
    public function joinColumnName(string $propertyName, string $className): string
    {
        return $propertyName . '_' . $this->referenceColumnName();
    }

    #[Override]
    public function joinTableName(
        string $sourceEntity,
        string $targetEntity,
        string $propertyName,
    ): string {
        return strtolower($this->classToTableName($sourceEntity) . '_' .
            $this->classToTableName($targetEntity));
    }

    #[Override]
    public function joinKeyColumnName(
        string $entityName,
        string|null $referencedColumnName,
    ): string {
        return strtolower($this->classToTableName($entityName) . '_' .
            ($referencedColumnName ?: $this->referenceColumnName()));
    }
}
