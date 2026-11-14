<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use BackedEnum;
use Override;
use ReflectionProperty;

use function array_map;
use function is_array;
use function reset;

/** @internal */
class EnumPropertyAccessor implements PropertyAccessor
{
    /** @param class-string<BackedEnum> $enumType */
    public function __construct(private PropertyAccessor $parent, private string $enumType)
    {
    }

    #[Override]
    public function setValue(object $object, mixed $value): void
    {
        if ($value !== null) {
            $value = $this->toEnum($value);
        }

        $this->parent->setValue($object, $value);
    }

    #[Override]
    public function getValue(object $object): mixed
    {
        $enum = $this->parent->getValue($object);

        if ($enum === null) {
            return null;
        }

        return $this->fromEnum($enum);
    }

    /**
     * @param BackedEnum|BackedEnum[] $enum
     *
     * @return ($enum is BackedEnum ? (string|int) : (string[]|int[]))
     */
    private function fromEnum($enum) // phpcs:ignore
    {
        if (is_array($enum)) {
            return array_map(static function (BackedEnum $enum) {
                return $enum->value;
            }, $enum);
        }

        return $enum->value;
    }

    /**
     * @phpstan-param BackedEnum|BackedEnum[]|int|string|int[]|string[] $value
     *
     * @return ($value is int|string|BackedEnum ? BackedEnum : BackedEnum[])
     */
    private function toEnum($value): BackedEnum|array
    {
        if ($value instanceof BackedEnum) {
            return $value;
        }

        if (is_array($value)) {
            $v = reset($value);
            if ($v instanceof BackedEnum) {
                return $value;
            }

            return array_map([$this->enumType, 'from'], $value);
        }

        return $this->enumType::from($value);
    }

    #[Override]
    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->parent->getUnderlyingReflector();
    }
}
