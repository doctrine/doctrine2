<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use Override;
use ReflectionProperty;

use function ltrim;

/**
 * This is a PHP 8.4 and up only class and replaces ObjectCastPropertyAccessor.
 *
 * It works based on the raw values of a property, which for a case of property hooks
 * is the backed value. If we kept using setValue/getValue, this would go through the hooks,
 * which potentially change the data.
 */
class RawValuePropertyAccessor implements PropertyAccessor
{
    public static function fromReflectionProperty(ReflectionProperty $reflectionProperty): self
    {
        $name = $reflectionProperty->getName();
        $key  = $reflectionProperty->isPrivate() ? "\0" . ltrim($reflectionProperty->getDeclaringClass()->getName(), '\\') . "\0" . $name : ($reflectionProperty->isProtected() ? "\0*\0" . $name : $name);

        return new self($reflectionProperty, $key);
    }

    private function __construct(private ReflectionProperty $reflectionProperty, private string $key)
    {
    }

    #[Override]
    public function setValue(object $object, mixed $value): void
    {
        $this->reflectionProperty->setRawValueWithoutLazyInitialization($object, $value);
    }

    #[Override]
    public function getValue(object $object): mixed
    {
        return ((array) $object)[$this->key] ?? null;
    }

    #[Override]
    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->reflectionProperty;
    }
}
