<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping\PropertyAccessors;

use Doctrine\ORM\Mapping\PropertyAccessors\DynamicPropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\EnumPropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\PropertyAccessorFactory;
use Doctrine\Tests\OrmTestCase;

class DynamicPropertyAccessorTest extends OrmTestCase
{
    public function testSetAndGetValue(): void
    {
        $object   = new DynamicPropertiesClass();
        $accessor = $this->createAccessor('values', 'color');

        self::assertNull($accessor->getValue($object));

        $accessor->setValue($object, 'red');

        self::assertSame(['color' => 'red'], $object->values);
        self::assertSame('red', $accessor->getValue($object));
    }

    public function testSettingNullRemovesTheKey(): void
    {
        $object   = new DynamicPropertiesClass();
        $accessor = $this->createAccessor('values', 'color');

        $object->values = ['color' => 'red', 'size' => 42];

        $accessor->setValue($object, null);

        self::assertSame(['size' => 42], $object->values);
        self::assertNull($accessor->getValue($object));
    }

    public function testSettingNullOnUninitializedContainerKeepsItUninitialized(): void
    {
        $object   = new DynamicPropertiesClass();
        $accessor = $this->createAccessor('uninitialized', 'color');

        $accessor->setValue($object, null);

        self::assertNull($accessor->getValue($object));
        self::assertFalse(isset($object->uninitialized));

        $accessor->setValue($object, 'red');

        self::assertSame(['color' => 'red'], $object->uninitialized);
    }

    public function testNonArrayContainerIsTreatedAsEmpty(): void
    {
        $object   = new DynamicPropertiesClass();
        $accessor = $this->createAccessor('untyped', 'color');

        $object->untyped = 'not an array';

        self::assertNull($accessor->getValue($object));

        $accessor->setValue($object, 'red');

        self::assertSame(['color' => 'red'], $object->untyped);
    }

    public function testEnumValues(): void
    {
        $object   = new DynamicPropertiesClass();
        $accessor = new EnumPropertyAccessor($this->createAccessor('values', 'enum'), DynamicPropertiesEnum::class);

        $accessor->setValue($object, DynamicPropertiesEnum::A->value);

        self::assertSame(['enum' => DynamicPropertiesEnum::A], $object->values);
        self::assertSame(DynamicPropertiesEnum::A->value, $accessor->getValue($object));
    }

    public function testUnderlyingReflectorIsTheContainer(): void
    {
        $reflector = $this->createAccessor('values', 'color')->getUnderlyingReflector();

        self::assertSame(DynamicPropertiesClass::class, $reflector->class);
        self::assertSame('values', $reflector->name);
    }

    private function createAccessor(string $container, string $key): DynamicPropertyAccessor
    {
        return new DynamicPropertyAccessor(
            PropertyAccessorFactory::createPropertyAccessor(DynamicPropertiesClass::class, $container),
            $key,
        );
    }
}

class DynamicPropertiesClass
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, mixed> */
    public array $uninitialized;

    /** @var mixed */
    public $untyped;
}

enum DynamicPropertiesEnum: string
{
    case A = 'a';
}
