<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use ArrayAccess;
use Doctrine\Deprecations\Deprecation;
use Doctrine\Persistence\Mapping\ReflectionService;
use Doctrine\Persistence\Reflection\EnumReflectionProperty;
use Generator;
use IteratorAggregate;
use OutOfBoundsException;
use ReflectionProperty;
use Traversable;

use function array_keys;
use function assert;
use function is_string;
use function str_contains;
use function str_replace;

/**
 * @template-implements ArrayAccess<string, ReflectionProperty|null>
 * @template-implements IteratorAggregate<string, ReflectionProperty|null>
 */
class LegacyReflectionFields implements ArrayAccess, IteratorAggregate
{
    /** @var array<string, ReflectionProperty|null> */
    private array $reflFields = [];

    public function __construct(private ClassMetadata $classMetadata, private ReflectionService $reflectionService)
    {
    }

    /** @param string $offset */
    public function offsetExists($offset): bool // phpcs:ignore
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/pull/11659',
            'Access to ClassMetadata::$reflFields is deprecated and will be removed in Doctrine ORM 4.0.',
        );

        return isset($this->classMetadata->propertyAccessors[$offset]);
    }

    /**
     * @param string $field
     *
     * @psalm-suppress LessSpecificImplementedReturnType
     */
    public function offsetGet($field): mixed // phpcs:ignore
    {
        if (isset($this->reflFields[$field])) {
            return $this->reflFields[$field];
        }

        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/pull/11659',
            'Access to ClassMetadata::$reflFields is deprecated and will be removed in Doctrine ORM 4.0.',
        );

        if (isset($this->classMetadata->propertyAccessors[$field])) {
            $dynamicProperty = $this->classMetadata->fieldMappings[$field]->dynamicProperty
                ?? $this->classMetadata->associationMappings[$field]->dynamicProperty
                ?? null;

            if ($dynamicProperty !== null) {
                $container = $this->classMetadata->dynamicProperties[$dynamicProperty];

                /** @psalm-suppress ArgumentTypeCoercion */
                $this->reflFields[$field] = new ReflectionDynamicProperty(
                    $this->getAccessibleProperty($container->declared ?? $this->classMetadata->name, $dynamicProperty),
                    $field,
                );

                if (isset($this->classMetadata->fieldMappings[$field]) && $this->classMetadata->fieldMappings[$field]->enumType !== null) {
                    $this->reflFields[$field] = new EnumReflectionProperty(
                        $this->reflFields[$field],
                        $this->classMetadata->fieldMappings[$field]->enumType,
                    );
                }

                return $this->reflFields[$field];
            }

            $fieldName = str_contains($field, '.') ? $this->classMetadata->fieldMappings[$field]->originalField : $field;
            $className = $this->classMetadata->name;

            assert(is_string($fieldName));

            if (isset($this->classMetadata->fieldMappings[$field]) && $this->classMetadata->fieldMappings[$field]->originalClass !== null) {
                $className = $this->classMetadata->fieldMappings[$field]->originalClass;
            } elseif (isset($this->classMetadata->fieldMappings[$field]) && $this->classMetadata->fieldMappings[$field]->declared !== null) {
                $className = $this->classMetadata->fieldMappings[$field]->declared;
            } elseif (isset($this->classMetadata->associationMappings[$field]) && $this->classMetadata->associationMappings[$field]->declared !== null) {
                $className = $this->classMetadata->associationMappings[$field]->declared;
            } elseif (isset($this->classMetadata->embeddedClasses[$field]) && $this->classMetadata->embeddedClasses[$field]->declared !== null) {
                $className = $this->classMetadata->embeddedClasses[$field]->declared;
            }

            /** @psalm-suppress ArgumentTypeCoercion */
            $this->reflFields[$field] = $this->getAccessibleProperty($className, $fieldName);

            if (isset($this->classMetadata->fieldMappings[$field])) {
                if ($this->classMetadata->fieldMappings[$field]->enumType !== null) {
                    $this->reflFields[$field] = new EnumReflectionProperty(
                        $this->reflFields[$field],
                        $this->classMetadata->fieldMappings[$field]->enumType,
                    );
                }

                if ($this->classMetadata->fieldMappings[$field]->originalField !== null) {
                    $parentField   = str_replace('.' . $fieldName, '', $field);
                    $originalClass = $this->classMetadata->fieldMappings[$field]->originalClass;

                    if (! str_contains($parentField, '.')) {
                        $parentClass = $this->classMetadata->name;
                    } else {
                        $parentClass = $this->classMetadata->fieldMappings[$parentField]->originalClass;
                    }

                    /** @psalm-var class-string $parentClass */
                    /** @psalm-var class-string $originalClass */

                    $this->reflFields[$field] = new ReflectionEmbeddedProperty(
                        $this->getAccessibleProperty($parentClass, $parentField),
                        $this->reflFields[$field],
                        $originalClass,
                    );
                }
            }

            return $this->reflFields[$field];
        }

        throw new OutOfBoundsException('Unknown field: ' . $this->classMetadata->name . ' ::$' . $field);
    }

    /**
     * @param string             $offset
     * @param ReflectionProperty $value
     */
    public function offsetSet($offset, $value): void // phpcs:ignore
    {
        $this->reflFields[$offset] = $value;
    }

    /** @param string $offset */
    public function offsetUnset($offset): void // phpcs:ignore
    {
        unset($this->reflFields[$offset]);
    }

    /** @psalm-param class-string $class */
    private function getAccessibleProperty(string $class, string $field): ReflectionProperty
    {
        $reflectionProperty = $this->reflectionService->getAccessibleProperty($class, $field);

        assert($reflectionProperty !== null);

        if ($reflectionProperty->isReadOnly()) {
            $declaringClass = $reflectionProperty->class;
            if ($declaringClass !== $class) {
                $reflectionProperty = $this->reflectionService->getAccessibleProperty($declaringClass, $field);

                assert($reflectionProperty !== null);
            }

            $reflectionProperty = new ReflectionReadonlyProperty($reflectionProperty);
        }

        return $reflectionProperty;
    }

    /** @return Generator<string, ReflectionProperty> */
    public function getIterator(): Traversable
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/pull/11659',
            'Access to ClassMetadata::$reflFields is deprecated and will be removed in Doctrine ORM 4.0.',
        );

        $keys = array_keys($this->classMetadata->propertyAccessors);

        foreach ($keys as $key) {
            yield $key => $this->offsetGet($key);
        }
    }
}
