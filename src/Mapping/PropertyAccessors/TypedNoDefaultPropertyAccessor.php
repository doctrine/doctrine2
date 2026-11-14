<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use Closure;
use InvalidArgumentException;
use ReflectionProperty;

use function assert;
use function sprintf;

use const PHP_VERSION_ID;

/** @internal */
class TypedNoDefaultPropertyAccessor implements PropertyAccessor
{
    private Closure|null $unsetter = null;

    private bool $hasHooks;

    public function __construct(private PropertyAccessor $parent, private ReflectionProperty $reflectionProperty)
    {
        if (! $this->reflectionProperty->hasType()) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s must have a type when used with TypedNoDefaultPropertyAccessor',
                $this->reflectionProperty->getDeclaringClass()->getName(),
                $this->reflectionProperty->getName(),
            ));
        }

        if ($this->reflectionProperty->getType()->allowsNull()) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s must not be nullable when used with TypedNoDefaultPropertyAccessor',
                $this->reflectionProperty->getDeclaringClass()->getName(),
                $this->reflectionProperty->getName(),
            ));
        }

        $this->hasHooks = PHP_VERSION_ID >= 80400 && $this->reflectionProperty->hasHooks();
    }

    public function setValue(object $object, mixed $value): void
    {
        if ($value === null) {
            /* The property is not initialized yet on a lazy object, so it is already unset.
               Calling unset() on it would trigger the initialization of the whole object. */
            if (PHP_VERSION_ID >= 80400 && $this->reflectionProperty->isLazy($object)) {
                return;
            }

            // A hooked property cannot be returned to its uninitialized state: unset() on one
            // raises an Error, and null would violate the type. Leaving it alone keeps a property
            // that was never written uninitialized, which is the state unset() produced here.
            if ($this->hasHooks) {
                return;
            }

            if ($this->unsetter === null) {
                $propertyName   = $this->reflectionProperty->getName();
                $this->unsetter = function () use ($propertyName): void {
                    unset($this->$propertyName);
                };
            }

            $unsetter = $this->unsetter->bindTo($object, $this->reflectionProperty->getDeclaringClass()->getName());

            assert($unsetter instanceof Closure);

            $unsetter();

            return;
        }

        $this->parent->setValue($object, $value);
    }

    public function getValue(object $object): mixed
    {
        /* The property is not initialized yet on a lazy object. Calling isInitialized()
           on it would trigger the initialization of the whole object. */
        if (PHP_VERSION_ID >= 80400 && $this->reflectionProperty->isLazy($object)) {
            return null;
        }

        return $this->reflectionProperty->isInitialized($object) ? $this->parent->getValue($object) : null;
    }

    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->reflectionProperty;
    }
}
