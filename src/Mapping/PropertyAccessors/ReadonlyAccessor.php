<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use InvalidArgumentException;
use LogicException;
use Override;
use ReflectionProperty;

use function sprintf;

use const PHP_VERSION_ID;

/** @internal */
class ReadonlyAccessor implements PropertyAccessor
{
    public function __construct(private PropertyAccessor $parent, private ReflectionProperty $reflectionProperty)
    {
        if (! $this->reflectionProperty->isReadOnly()) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s must be readonly property',
                $this->reflectionProperty->getDeclaringClass()->getName(),
                $this->reflectionProperty->getName(),
            ));
        }
    }

    #[Override]
    public function setValue(object $object, mixed $value): void
    {
        /* For lazy properties, skip the isInitialized() check
           because it would trigger the initialization of the whole object. */
        if (
            PHP_VERSION_ID >= 80400 && $this->reflectionProperty->isLazy($object)
            || ! $this->reflectionProperty->isInitialized($object)
        ) {
            $this->parent->setValue($object, $value);

            return;
        }

        if ($this->parent->getValue($object) !== $value) {
            throw new LogicException(sprintf(
                'Attempting to change readonly property %s::$%s.',
                $this->reflectionProperty->getDeclaringClass()->getName(),
                $this->reflectionProperty->getName(),
            ));
        }
    }

    #[Override]
    public function getValue(object $object): mixed
    {
        return $this->parent->getValue($object);
    }

    #[Override]
    public function getUnderlyingReflector(): ReflectionProperty
    {
        return $this->reflectionProperty;
    }
}
