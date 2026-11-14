<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Override;

use function preg_replace;
use function str_contains;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

use const CASE_LOWER;
use const CASE_UPPER;

/**
 * Naming strategy implementing the underscore naming convention.
 * Converts 'MyEntity' to 'my_entity' or 'MY_ENTITY'.
 *
 * @link    www.doctrine-project.org
 */
class UnderscoreNamingStrategy implements NamingStrategy
{
    /**
     * Underscore naming strategy construct.
     *
     * @param int $case CASE_LOWER | CASE_UPPER
     */
    public function __construct(private int $case = CASE_LOWER)
    {
    }

    /** @return int CASE_LOWER | CASE_UPPER */
    public function getCase(): int
    {
        return $this->case;
    }

    /**
     * Sets string case CASE_LOWER | CASE_UPPER.
     * Alphabetic characters converted to lowercase or uppercase.
     */
    public function setCase(int $case): void
    {
        $this->case = $case;
    }

    #[Override]
    public function classToTableName(string $className): string
    {
        if (str_contains($className, '\\')) {
            $className = substr($className, strrpos($className, '\\') + 1);
        }

        return $this->underscore($className);
    }

    #[Override]
    public function propertyToColumnName(string $propertyName, string $className): string
    {
        return $this->underscore($propertyName);
    }

    #[Override]
    public function embeddedFieldToColumnName(
        string $propertyName,
        string $embeddedColumnName,
        string $className,
        string $embeddedClassName,
    ): string {
        return $this->underscore($propertyName) . '_' . $embeddedColumnName;
    }

    #[Override]
    public function referenceColumnName(): string
    {
        return $this->case === CASE_UPPER ?  'ID' : 'id';
    }

    #[Override]
    public function joinColumnName(string $propertyName, string $className): string
    {
        return $this->underscore($propertyName) . '_' . $this->referenceColumnName();
    }

    #[Override]
    public function joinTableName(
        string $sourceEntity,
        string $targetEntity,
        string $propertyName,
    ): string {
        return $this->classToTableName($sourceEntity) . '_' . $this->classToTableName($targetEntity);
    }

    #[Override]
    public function joinKeyColumnName(
        string $entityName,
        string|null $referencedColumnName,
    ): string {
        return $this->classToTableName($entityName) . '_' .
                ($referencedColumnName ?: $this->referenceColumnName());
    }

    private function underscore(string $string): string
    {
        $string = preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $string);

        if ($this->case === CASE_UPPER) {
            return strtoupper($string);
        }

        return strtolower($string);
    }
}
