<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use BackedEnum;
use Exception;

use function in_array;
use function property_exists;

final class DiscriminatorColumnMapping
{
    /** The database length of the column. Optional. Default value taken from the type. */
    public int|null $length = null;

    public string|null $columnDefinition = null;

    /** @var class-string<BackedEnum>|null */
    public string|null $enumType = null;

    /** @var array<string, mixed> */
    public array $options = [];

    public function __construct(
        public string $type,
        public string $fieldName,
        public string $name,
    ) {
    }

    /**
     * @phpstan-param array{
     *     type: string,
     *     fieldName: string,
     *     name: string,
     *     length?: int|null,
     *     columnDefinition?: string|null,
     *     enumType?: class-string<BackedEnum>|null,
     *     options?: array<string, mixed>|null,
     * } $mappingArray
     */
    public static function fromMappingArray(array $mappingArray): self
    {
        $mapping = new self(
            $mappingArray['type'],
            $mappingArray['fieldName'],
            $mappingArray['name'],
        );
        foreach ($mappingArray as $key => $value) {
            if (in_array($key, ['type', 'fieldName', 'name'])) {
                continue;
            }

            if (property_exists($mapping, $key)) {
                $mapping->$key = $value ?? $mapping->$key;
            } else {
                throw new Exception('Unknown property ' . $key . ' on class ' . static::class);
            }
        }

        return $mapping;
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        $serialized = ['type', 'fieldName', 'name'];

        foreach (['length', 'columnDefinition', 'enumType', 'options'] as $stringOrArrayKey) {
            if ($this->$stringOrArrayKey !== null) {
                $serialized[] = $stringOrArrayKey;
            }
        }

        return $serialized;
    }
}
