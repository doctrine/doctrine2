<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping\PropertyAccessors;

use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\ORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;
use ReflectionClass;

class TypedNoDefaultPropertyAccessorTest extends OrmTestCase
{
    public function testSetValueWithoutDefault(): void
    {
        $accessor = PropertyAccessorFactory::createPropertyAccessor(TypedClass::class, 'property');

        $this->assertInstanceOf(TypedNoDefaultPropertyAccessor::class, $accessor);

        $object = new TypedClass();
        $accessor->setValue($object, 42);
        $this->assertEquals(42, $accessor->getValue($object));
    }

    public function testSetNullWithoutDefault(): void
    {
        $accessor = PropertyAccessorFactory::createPropertyAccessor(TypedClass::class, 'property');

        $object = new TypedClass();
        $accessor->setValue($object, null);
        $this->assertNull($accessor->getValue($object));

        $accessor->setValue($object, 42);
        $this->assertEquals(42, $accessor->getValue($object));

        $accessor->setValue($object, null);
        $this->assertNull($accessor->getValue($object));
    }

    #[RequiresPhp('>= 8.4.0')]
    public function testGetValueDoesNotInitializeALazyObject(): void
    {
        $reflection = new ReflectionClass(TypedClass::class);
        $object     = $reflection->newLazyGhost(static function (TypedClass $object): void {
            $object->property = 42;
        });

        $accessor = PropertyAccessorFactory::createPropertyAccessor(TypedClass::class, 'property');

        self::assertTrue($reflection->isUninitializedLazyObject($object));
        self::assertNull($accessor->getValue($object));
        self::assertTrue($reflection->isUninitializedLazyObject($object), 'getValue() initialized the lazy object');
    }

    #[RequiresPhp('>= 8.4.0')]
    public function testSetNullDoesNotInitializeALazyObject(): void
    {
        $reflection = new ReflectionClass(TypedClass::class);
        $object     = $reflection->newLazyGhost(static function (TypedClass $object): void {
            $object->property = 42;
        });

        $accessor = PropertyAccessorFactory::createPropertyAccessor(TypedClass::class, 'property');

        self::assertTrue($reflection->isUninitializedLazyObject($object));
        $accessor->setValue($object, null);
        self::assertTrue($reflection->isUninitializedLazyObject($object), 'setValue(null) initialized the lazy object');
    }
}

class TypedClass
{
    public int $property;
}
