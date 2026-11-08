<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\PropertyAccessors\DynamicPropertyAccessor;
use Doctrine\ORM\Mapping\PropertyAccessors\EnumPropertyAccessor;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\DynamicProperties\Customer;
use Doctrine\Tests\Models\DynamicProperties\LoyaltyMetadataListener;
use Doctrine\Tests\Models\DynamicProperties\Product;
use Doctrine\Tests\Models\DynamicProperties\ProductStatus;
use Doctrine\Tests\Models\DynamicProperties\Vendor;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

use function array_keys;
use function serialize;
use function unserialize;

use const DIRECTORY_SEPARATOR;

class DynamicPropertiesTest extends OrmTestCase
{
    use VerifyDeprecations;

    public function testAttributeMapping(): void
    {
        $class = $this->loadWithDriver(new AttributeDriver([]), Product::class);

        self::assertSame(['attributes'], array_keys($class->dynamicProperties));

        self::assertSame('attributes', $class->fieldMappings['color']->dynamicProperty);
        self::assertSame('color', $class->fieldMappings['color']->columnName);
        self::assertSame(20, $class->fieldMappings['color']->length);
        self::assertSame('size_eu', $class->fieldMappings['size']->columnName);
        self::assertSame('integer', $class->fieldMappings['size']->type);
        self::assertSame(ProductStatus::class, $class->fieldMappings['status']->enumType);
        self::assertNull($class->fieldMappings['name']->dynamicProperty);

        $vendor = $class->associationMappings['vendor'];
        self::assertSame('attributes', $vendor->dynamicProperty);
        self::assertSame(Vendor::class, $vendor->targetEntity);
        self::assertTrue($vendor->isManyToOne());
        self::assertSame('vendor_id', $vendor->joinColumns[0]->name);
        self::assertSame('SET NULL', $vendor->joinColumns[0]->onDelete);

        self::assertTrue($class->isDynamicProperty('color'));
        self::assertTrue($class->isDynamicProperty('vendor'));
        self::assertFalse($class->isDynamicProperty('name'));
    }

    public function testXmlMappingIsEquivalentToAttributeMapping(): void
    {
        $attributeClass = $this->loadWithDriver(new AttributeDriver([]), Product::class);
        $xmlClass       = $this->loadWithDriver(
            new XmlDriver(__DIR__ . DIRECTORY_SEPARATOR . 'xml', XmlDriver::DEFAULT_FILE_EXTENSION, true),
            Product::class,
        );

        self::assertEquals($attributeClass->dynamicProperties, $xmlClass->dynamicProperties);
        self::assertEquals($attributeClass->fieldMappings, $xmlClass->fieldMappings);
        self::assertEquals($attributeClass->associationMappings, $xmlClass->associationMappings);
    }

    public function testMetadataSurvivesSerialization(): void
    {
        $class = $this->getTestEntityManager()->getClassMetadata(Product::class);

        $unserialized = unserialize(serialize($class));
        self::assertInstanceOf(ClassMetadata::class, $unserialized);
        $unserialized->wakeupReflection(new RuntimeReflectionService());

        self::assertEquals($class->dynamicProperties, $unserialized->dynamicProperties);
        self::assertSame('attributes', $unserialized->fieldMappings['color']->dynamicProperty);
        self::assertSame('attributes', $unserialized->associationMappings['vendor']->dynamicProperty);
        self::assertInstanceOf(DynamicPropertyAccessor::class, $unserialized->propertyAccessors['color']);
        self::assertInstanceOf(DynamicPropertyAccessor::class, $unserialized->propertyAccessors['vendor']);
        self::assertInstanceOf(EnumPropertyAccessor::class, $unserialized->propertyAccessors['status']);
        self::assertArrayNotHasKey('attributes', $unserialized->propertyAccessors);

        $product = new Product('Shirt');
        $unserialized->propertyAccessors['color']->setValue($product, 'red');
        $unserialized->propertyAccessors['status']->setValue($product, 'active');

        self::assertSame(['color' => 'red', 'status' => ProductStatus::Active], $product->all());
    }

    public function testContainerDeclaredInMappedSuperclassAndFilledByListener(): void
    {
        $em = $this->getTestEntityManager();
        $em->getEventManager()->addEventListener(Events::loadClassMetadata, new LoyaltyMetadataListener());

        $class = $em->getClassMetadata(Customer::class);

        self::assertNotNull($class->dynamicProperties['extra']->declared);
        self::assertSame('extra', $class->fieldMappings['loyalty_points']->dynamicProperty);
        self::assertSame('extra', $class->associationMappings['loyalty_tier']->dynamicProperty);

        $customer = new Customer('Alice');
        $class->propertyAccessors['loyalty_points']->setValue($customer, 10);

        self::assertSame(['loyalty_points' => 10], $customer->all());
    }

    #[IgnoreDeprecations]
    public function testLegacyReflectionFields(): void
    {
        $class = $this->getTestEntityManager()->getClassMetadata(Product::class);

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/11659');

        $product = new Product('Shirt');
        // @phpstan-ignore property.deprecated
        $class->reflFields['color']->setValue($product, 'red');

        self::assertSame(['color' => 'red'], $product->all());
        // @phpstan-ignore property.deprecated
        self::assertSame('red', $class->reflFields['color']->getValue($product));
    }

    public function testFieldRequiresMappedContainer(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('references the dynamic properties container "unknown", which is not mapped');

        $class->mapDynamicField('unknown', ['fieldName' => 'color']);
    }

    public function testContainerCannotBeMappedTwice(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);

        $class->mapDynamicProperties(['fieldName' => 'values']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidKeys(): iterable
    {
        yield 'dot' => ['some.key', 'keys must be valid identifiers'];
        yield 'dash' => ['some-key', 'keys must be valid identifiers'];
        yield 'leading digit' => ['1key', 'keys must be valid identifiers'];
        yield 'declared property' => ['name', 'collides with a declared property'];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRejected(string $key, string $message): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        $class->mapDynamicField('values', ['fieldName' => $key]);
    }

    public function testKeyCannotBeMappedTwice(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);
        $class->mapDynamicField('values', ['fieldName' => 'color']);

        $this->expectException(MappingException::class);

        $class->mapDynamicField('values', ['fieldName' => 'color', 'columnName' => 'other_color']);
    }

    public function testIdentifierIsRejected(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('dynamic properties cannot be identifiers or version fields');

        $class->mapDynamicField('values', ['fieldName' => 'code', 'id' => true]);
    }

    public function testToManyAssociationIsRejected(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('only owning side to-one associations');

        $class->mapDynamicProperties(['fieldName' => 'untyped']);
        $class->mapManyToMany([
            'fieldName' => 'tags',
            'targetEntity' => Vendor::class,
            'dynamicProperty' => 'values',
        ]);
    }

    public function testInverseSideOneToOneIsRejected(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('only owning side to-one associations');

        $class->mapDynamicOneToOne('values', [
            'fieldName' => 'vendor',
            'targetEntity' => Vendor::class,
            'mappedBy' => 'product',
        ]);
    }

    public function testContainerMustBeAnArray(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);
        $class->mapDynamicProperties(['fieldName' => 'name']);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the property must be of type array');

        $class->validateDynamicProperties();
    }

    public function testContainerMustExist(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);
        $class->mapDynamicProperties(['fieldName' => 'doesNotExist']);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('the property does not exist');

        $class->validateDynamicProperties();
    }

    public function testDynamicFieldAttributeRequiresContainerAttribute(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('require #[Doctrine\ORM\Mapping\DynamicProperties] on the same property');

        $this->loadWithDriver(new AttributeDriver([]), DynamicFieldWithoutContainerEntity::class);
    }

    public function testContainerOnEmbeddableIsRejected(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('dynamic properties are not supported on embeddables');

        $this->loadWithDriver(new AttributeDriver([]), DynamicPropertiesEmbeddable::class);
    }

    /** @param class-string $className */
    private function loadWithDriver(AttributeDriver|XmlDriver $driver, string $className): ClassMetadata
    {
        $class = new ClassMetadata($className);
        $class->initializeReflection(new RuntimeReflectionService());
        $driver->loadMetadataForClass($className, $class);

        return $class;
    }

    /** @param class-string $className */
    private function createClassMetadata(string $className): ClassMetadata
    {
        $class = new ClassMetadata($className);
        $class->initializeReflection(new RuntimeReflectionService());
        $class->mapDynamicProperties(['fieldName' => 'values']);

        return $class;
    }
}

#[ORM\Entity]
class DynamicPropertiesValidationEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $name;

    /** @var array<string, mixed> */
    public array $values = [];

    /** @var mixed */
    public $untyped;
}

#[ORM\Entity]
class DynamicFieldWithoutContainerEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicField('color')]
    public array $values = [];
}

#[ORM\Embeddable]
class DynamicPropertiesEmbeddable
{
    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    public array $values = [];
}
