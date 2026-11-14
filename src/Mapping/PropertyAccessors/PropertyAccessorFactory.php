<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\PropertyAccessors;

use ReflectionProperty;

class PropertyAccessorFactory
{
    /** @phpstan-param class-string $className */
    public static function createPropertyAccessor(string $className, string $propertyName): PropertyAccessor
    {
        $reflectionProperty = new ReflectionProperty($className, $propertyName);

        $accessor = RawValuePropertyAccessor::fromReflectionProperty($reflectionProperty);

        if ($reflectionProperty->hasType() && ! $reflectionProperty->getType()->allowsNull()) {
            $accessor = new TypedNoDefaultPropertyAccessor($accessor, $reflectionProperty);
        }

        if ($reflectionProperty->isReadOnly()) {
            $accessor = new ReadonlyAccessor($accessor, $reflectionProperty);
        }

        return $accessor;
    }
}
