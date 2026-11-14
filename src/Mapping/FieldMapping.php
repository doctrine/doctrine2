<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use BackedEnum;

use function in_array;
use function property_exists;

final class FieldMapping
{
    /** The database length of the column. Optional. Default value taken from the type. */
    public int|null $length = null;
    /**
     * Marks the field as the primary key of the entity. Multiple
     * fields of an entity can have the id attribute, forming a composite key.
     */
    public bool|null $id                 = null;
    public int|null $idPosition          = null;
    public bool|null $nullable           = null;
    public bool|null $notInsertable      = null;
    public bool|null $notUpdatable       = null;
    public string|null $columnDefinition = null;
    /** @phpstan-var ClassMetadata::GENERATED_*|null */
    public int|null $generated = null;
    /** @var class-string<BackedEnum>|null */
    public string|null $enumType = null;
    /**
     * The precision of a decimal column.
     * Only valid if the column type is decimal
     */
    public int|null $precision = null;
    /**
     * The scale of a decimal column.
     * Only valid if the column type is decimal
     */
    public int|null $scale = null;
    /** Whether a unique constraint should be generated for the column. */
    public bool|null $unique = null;
    /** Whether an index should be generated for the column. */
    public bool|null $index = null;
    /**
     * @var class-string|null This is set when the field is inherited by this
     * class from another (inheritance) parent <em>entity</em> class. The value
     * is the FQCN of the topmost entity class that contains mapping information
     * for this field. (If there are transient classes in the class hierarchy,
     * these are ignored, so the class property may in fact come from a class
     * further up in the PHP class hierarchy.)
     * Fields initially declared in mapped superclasses are
     * <em>not</em> considered 'inherited' in the nearest entity subclasses.
     */
    public string|null $inherited = null;

    /** @var class-string|null */
    public string|null $originalClass = null;
    public string|null $originalField = null;
    public bool|null $quoted          = null;
    /**
     * @var class-string|null This is set when the field does not appear for
     * the first time in this class, but is originally declared in another
     * parent <em>entity or mapped superclass</em>. The value is the FQCN of
     * the topmost non-transient class that contains mapping information for
     * this field.
     */
    public string|null $declared      = null;
    public string|null $declaredField = null;
    public array|null $options        = null;
    public bool|null $version         = null;

    /**
     * @param string $type       The type name of the mapped field. Can be one of
     *                           Doctrine's mapping types or a custom mapping type.
     * @param string $fieldName  The name of the field in the Entity.
     * @param string $columnName The column name. Optional. Defaults to the field name.
     */
    public function __construct(
        public string $type,
        public string $fieldName,
        public string $columnName,
    ) {
    }

    /**
     * @param array<string, mixed> $mappingArray
     * @phpstan-param array{
     *     type: string,
     *     fieldName: string,
     *     columnName: string,
     *     length?: int|null,
     *     id?: bool|null,
     *     idPosition?: int|null,
     *     nullable?: bool|null,
     *     index?: bool|null,
     *     notInsertable?: bool|null,
     *     notUpdatable?: bool|null,
     *     columnDefinition?: string|null,
     *     generated?: ClassMetadata::GENERATED_*|null,
     *     enumType?: string|null,
     *     precision?: int|null,
     *     scale?: int|null,
     *     unique?: bool|null,
     *     inherited?: string|null,
     *     originalClass?: class-string|null,
     *     originalField?: string|null,
     *     quoted?: bool|null,
     *     declared?: string|null,
     *     declaredField?: string|null,
     *     options?: array<string, mixed>|null,
     *     version?: bool|null,
     *     default?: string|int|null,
     * } $mappingArray
     */
    public static function fromMappingArray(array $mappingArray): self
    {
        $mapping = new self(
            $mappingArray['type'],
            $mappingArray['fieldName'],
            $mappingArray['columnName'],
        );
        foreach ($mappingArray as $key => $value) {
            if (in_array($key, ['type', 'fieldName', 'columnName'])) {
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
        $serialized = ['type', 'fieldName', 'columnName'];

        foreach (['nullable', 'notInsertable', 'notUpdatable', 'id', 'unique', 'version', 'quoted', 'index'] as $boolKey) {
            if ($this->$boolKey) {
                $serialized[] = $boolKey;
            }
        }

        foreach (
            [
                'idPosition',
                'length',
                'columnDefinition',
                'generated',
                'enumType',
                'precision',
                'scale',
                'inherited',
                'originalClass',
                'originalField',
                'declared',
                'declaredField',
                'options',
            ] as $key
        ) {
            if ($this->$key !== null) {
                $serialized[] = $key;
            }
        }

        return $serialized;
    }
}
