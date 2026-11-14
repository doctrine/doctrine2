<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use function property_exists;

final class JoinColumnMapping
{
    public bool|null $deferrable         = null;
    public bool|null $unique             = null;
    public bool|null $quoted             = null;
    public string|null $fieldName        = null;
    public string|null $onDelete         = null;
    public string|null $columnDefinition = null;
    public bool|null $nullable           = null;
    public string|null $foreignKeyName   = null;

    /** @var array<string, mixed>|null */
    public array|null $options = null;

    public function __construct(
        public string $name,
        public string $referencedColumnName,
    ) {
    }

    /**
     * @param array<string, mixed> $mappingArray
     * @phpstan-param array{
     *     name: string,
     *     referencedColumnName: string|null,
     *     unique?: bool|null,
     *     quoted?: bool|null,
     *     fieldName?: string|null,
     *     onDelete?: string|null,
     *     columnDefinition?: string|null,
     *     nullable?: bool|null,
     *     foreignKeyName?: string|null,
     *     options?: array<string, mixed>|null,
     * } $mappingArray
     */
    public static function fromMappingArray(array $mappingArray): self
    {
        $mapping = new self($mappingArray['name'], $mappingArray['referencedColumnName']);
        foreach ($mappingArray as $key => $value) {
            if (property_exists($mapping, $key) && $value !== null) {
                $mapping->$key = $value;
            }
        }

        return $mapping;
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        $serialized = [];

        foreach (
            [
                'columnDefinition',
                'fieldName',
                'foreignKeyName',
                'name',
                'onDelete',
                'options',
                'referencedColumnName',
            ] as $stringOrArrayKey
        ) {
            if ($this->$stringOrArrayKey !== null) {
                $serialized[] = $stringOrArrayKey;
            }
        }

        foreach (['deferrable', 'unique', 'quoted', 'nullable'] as $boolKey) {
            if ($this->$boolKey !== null) {
                $serialized[] = $boolKey;
            }
        }

        return $serialized;
    }
}
