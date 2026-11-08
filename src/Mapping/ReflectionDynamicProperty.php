<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use ReflectionProperty;

use function array_key_exists;
use function is_array;

/**
 * Acts as a proxy to a single key of a dynamic properties container array.
 *
 * Only used by the deprecated {@see ClassMetadata::$reflFields}.
 *
 * @internal
 */
final class ReflectionDynamicProperty extends ReflectionProperty
{
    /** @param ReflectionProperty $containerProperty reflection property of the dynamic properties container */
    public function __construct(
        private readonly ReflectionProperty $containerProperty,
        private readonly string $key,
    ) {
        parent::__construct($containerProperty->getDeclaringClass()->name, $containerProperty->getName());
    }

    public function getValue(object|null $object = null): mixed
    {
        $values = $this->containerProperty->getValue($object);

        return is_array($values) ? $values[$this->key] ?? null : null;
    }

    public function setValue(mixed $object, mixed $value = null): void
    {
        $values = $this->containerProperty->getValue($object);

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

        $this->containerProperty->setValue($object, $values);
    }
}
