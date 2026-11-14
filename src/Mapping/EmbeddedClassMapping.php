<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use function property_exists;

final class EmbeddedClassMapping
{
    public string|false|null $columnPrefix = null;
    public string|null $declaredField      = null;
    public string|null $originalField      = null;

    /**
     * This is set when this embedded-class field is inherited by this class
     * from another (inheritance) parent <em>entity</em> class. The value is
     * the FQCN of the topmost entity class that contains mapping information
     * for this field. (If there are transient classes in the class hierarchy,
     * these are ignored, so the class property may in fact come from a class
     * further up in the PHP class hierarchy.) Fields initially declared in
     * mapped superclasses are <em>not</em> considered 'inherited' in the
     * nearest entity subclasses.
     *
     * @var class-string|null
     */
    public string|null $inherited = null;

    /**
     * This is set when the embedded-class field does not appear for the first
     * time in this class, but is originally declared in another parent
     * <em>entity or mapped superclass</em>. The value is the FQCN of the
     * topmost non-transient class that contains mapping information for this
     * field.
     *
     * @var class-string|null
     */
    public string|null $declared = null;

    /** @param class-string $class */
    public function __construct(public string $class)
    {
    }

    /**
     * @phpstan-param array{
     *     class: class-string,
     *     columnPrefix?: false|string|null,
     *     declaredField?: string|null,
     *     originalField?: string|null,
     *     inherited?: class-string|null,
     *     declared?: class-string|null,
     * } $mappingArray
     */
    public static function fromMappingArray(array $mappingArray): self
    {
        $mapping = new self($mappingArray['class']);
        foreach ($mappingArray as $key => $value) {
            if ($key === 'class') {
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
        $serialized = ['class'];

        if ($this->columnPrefix) {
            $serialized[] = 'columnPrefix';
        }

        foreach (['declaredField', 'originalField', 'inherited', 'declared'] as $property) {
            if ($this->$property !== null) {
                $serialized[] = $property;
            }
        }

        return $serialized;
    }
}
