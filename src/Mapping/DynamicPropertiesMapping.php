<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use ArrayAccess;

use function property_exists;

/**
 * Describes an array property whose keys are mapped to columns.
 *
 * Fields and associations stored in the container reference it through
 * {@see FieldMapping::$dynamicProperty} and {@see AssociationMapping::$dynamicProperty}.
 *
 * @template-implements ArrayAccess<string, mixed>
 */
final class DynamicPropertiesMapping implements ArrayAccess
{
    use ArrayAccessImplementation;

    /**
     * This is set when the container is inherited by this class from another
     * (inheritance) parent <em>entity</em> class. The value is the FQCN of the
     * topmost entity class that contains mapping information for it.
     *
     * @var class-string|null
     */
    public string|null $inherited = null;

    /**
     * This is set when the container does not appear for the first time in
     * this class, but is originally declared in another parent <em>entity or
     * mapped superclass</em>. The value is the FQCN of the topmost
     * non-transient class that contains mapping information for it.
     *
     * @var class-string|null
     */
    public string|null $declared = null;

    /**
     * Prepended to the column names of the fields and the join column names of the
     * to-one associations stored in the container, including explicitly named ones.
     */
    public string|null $columnPrefix = null;

    /** @param string $fieldName The name of the array property holding the dynamic properties. */
    public function __construct(public string $fieldName)
    {
    }

    /**
     * @phpstan-param array{
     *     fieldName: string,
     *     inherited?: class-string|null,
     *     declared?: class-string|null,
     *     columnPrefix?: string|null,
     * } $mappingArray
     */
    public static function fromMappingArray(array $mappingArray): self
    {
        $mapping = new self($mappingArray['fieldName']);
        foreach ($mappingArray as $key => $value) {
            if ($key === 'fieldName') {
                continue;
            }

            if (property_exists($mapping, $key)) {
                $mapping->$key = $value;
            }
        }

        return $mapping;
    }

    /** @return list<string> */
    public function __sleep(): array
    {
        $serialized = ['fieldName'];

        foreach (['inherited', 'declared', 'columnPrefix'] as $property) {
            if ($this->$property !== null) {
                $serialized[] = $property;
            }
        }

        return $serialized;
    }
}
