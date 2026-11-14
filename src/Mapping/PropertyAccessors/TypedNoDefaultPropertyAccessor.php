<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use Closure;
use InvalidArgumentException;
use Override;
use ReflectionProperty;

use function assert;
use function sprintf;

use const PHP_VERSION_ID;

/** @internal */
class TypedNoDefaultPropertyAccessor implements PropertyAccessor
{
    private Closure|null $unsetter = null;

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
    }

    #[Override]
    public function setValue(object $object, mixed $value): void
    {
        if ($value === null) {
            /* The property is not initialized yet on a lazy object, so it is already unset.
               Calling unset() on it would trigger the initialization of the whole object. */
            if (PHP_VERSION_ID >= 80400 && $this->reflectionProperty->isLazy($object)) {
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

    #[Override]
    public function getValue(object $object): mixed
    {
        /* The property is not initialized yet on a lazy object. Calling isInitialized()
           on it would trigger the initialization of the whole object. */
        if (PHP_VERSION_ID >= 80400 && $this->reflectionProperty->isLazy($object)) {
            return null;
        }

        return $this->reflectionProperty->isInitialized($object) ? $this->parent->getValue($object) : null;
    }

    #[Override]
    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->reflectionProperty;
    }
}
