<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use ReflectionProperty;

use const PHP_VERSION_ID;

class PropertyAccessorFactory
{
    /** @phpstan-param class-string $className */
    public static function createPropertyAccessor(
        string $className,
        string $propertyName,
        bool $identifier = false,
    ): PropertyAccessor {
        $reflectionProperty = new ReflectionProperty($className, $propertyName);

        $accessor = PHP_VERSION_ID >= 80400
            ? RawValuePropertyAccessor::fromReflectionProperty($reflectionProperty)
            : ObjectCastPropertyAccessor::fromReflectionProperty($reflectionProperty);

        if ($reflectionProperty->hasType() && ! $reflectionProperty->getType()->allowsNull()) {
            $accessor = new TypedNoDefaultPropertyAccessor($accessor, $reflectionProperty);
        }

        if ($reflectionProperty->isReadOnly()) {
            $accessor = new ReadonlyAccessor($accessor, $reflectionProperty, $identifier);
        }

        return $accessor;
    }
}
