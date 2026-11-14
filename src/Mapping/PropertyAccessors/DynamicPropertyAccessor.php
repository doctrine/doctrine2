<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use ReflectionProperty;

use function array_key_exists;
use function is_array;

/**
 * Reads and writes a single key of an array property (a dynamic properties container).
 *
 * Writing null removes the key from the array, reading a missing key returns null.
 *
 * @internal
 */
class DynamicPropertyAccessor implements PropertyAccessor
{
    public function __construct(
        private PropertyAccessor $container,
        private string $key,
    ) {
    }

    public function setValue(object $object, mixed $value): void
    {
        $values = $this->container->getValue($object);

        if (! is_array($values)) {
            $values = [];
        }

        if ($value === null) {
            if (! array_key_exists($this->key, $values)) {
                return;
            }

            unset($values[$this->key]);
        } else {
            $values[$this->key] = $value;
        }

        $this->container->setValue($object, $values);
    }

    public function getValue(object $object): mixed
    {
        $values = $this->container->getValue($object);

        return is_array($values) ? $values[$this->key] ?? null : null;
    }

    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->container->getUnderlyingReflector();
    }
}
