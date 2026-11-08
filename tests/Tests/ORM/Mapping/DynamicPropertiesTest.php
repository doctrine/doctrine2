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
use Doctrine\Tests\Models\DynamicProperties\Article;
use Doctrine\Tests\Models\DynamicProperties\Customer;
use Doctrine\Tests\Models\DynamicProperties\LoyaltyMetadataListener;
use Doctrine\Tests\Models\DynamicProperties\Product;
use Doctrine\Tests\Models\DynamicProperties\ProductStatus;
use Doctrine\Tests\Models\DynamicProperties\Tag;
use Doctrine\Tests\Models\DynamicProperties\Vendor;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use SortDirection;

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

    public function testToManyAttributeMapping(): void
    {
        $article = $this->loadWithDriver(new AttributeDriver([]), Article::class);

        $tags = $article->associationMappings['tags'];
        self::assertSame('attributes', $tags->dynamicProperty);
        self::assertTrue($tags->isManyToManyOwningSide());
        self::assertSame(Tag::class, $tags->targetEntity);
        self::assertSame('articles', $tags->inversedBy);
        self::assertSame('dynamic_properties_article_tag', $tags->joinTable->name);
        self::assertSame(['name' => SortDirection::Ascending], $tags->orderBy());

        $comments = $article->associationMappings['comments'];
        self::assertSame('attributes', $comments->dynamicProperty);
        self::assertTrue($comments->isOneToMany());
        self::assertSame('article', $comments->mappedBy);
        self::assertTrue($comments->orphanRemoval);
        self::assertSame(['id' => SortDirection::Ascending], $comments->orderBy());

        $tag = $this->loadWithDriver(new AttributeDriver([]), Tag::class);

        self::assertSame('attributes', $tag->associationMappings['articles']->dynamicProperty);
        self::assertTrue($tag->associationMappings['articles']->isManyToMany());
        self::assertSame('tags', $tag->associationMappings['articles']->mappedBy);
    }

    /** @return iterable<string, array{class-string}> */
    public static function mappedClasses(): iterable
    {
        yield 'fields and to-one associations' => [Product::class];
        yield 'one-to-many and owning side many-to-many' => [Article::class];
        yield 'inverse side many-to-many' => [Tag::class];
    }

    /** @param class-string $className */
    #[DataProvider('mappedClasses')]
    public function testXmlMappingIsEquivalentToAttributeMapping(string $className): void
    {
        $attributeClass = $this->loadWithDriver(new AttributeDriver([]), $className);
        $xmlClass       = $this->loadWithDriver(
            new XmlDriver(__DIR__ . DIRECTORY_SEPARATOR . 'xml', XmlDriver::DEFAULT_FILE_EXTENSION, true),
            $className,
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

        $product->set('unmapped', 'value');
        self::assertSame(['attributes' => ['unmapped']], $unserialized->getUnmappedDynamicPropertyKeys($product));
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

    public function testAssociationIdentifierIsRejected(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('dynamic properties cannot be identifiers or version fields');

        $class->mapDynamicManyToOne('values', [
            'fieldName' => 'vendor',
            'targetEntity' => Vendor::class,
            'id' => true,
        ]);
    }

    public function testInverseSideOneToOneIsRejected(): void
    {
        $class = $this->createClassMetadata(DynamicPropertiesValidationEntity::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('inverse side one-to-one associations are not supported');

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

    /** @return iterable<string, array{class-string, string}> */
    public static function invalidDynamicAssociationAttributes(): iterable
    {
        yield 'join columns on to-many' => [DynamicToManyWithJoinColumnsEntity::class, 'join columns are only supported for to-one associations'];
        yield 'join table on many-to-one' => [DynamicManyToOneWithJoinTableEntity::class, 'a join table is only supported for many-to-many associations'];
        yield 'order by on many-to-one' => [DynamicManyToOneWithOrderByEntity::class, 'order by is only supported for to-many associations'];
    }

    /** @param class-string $className */
    #[DataProvider('invalidDynamicAssociationAttributes')]
    public function testInvalidDynamicAssociationAttributeIsRejected(string $className, string $message): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage($message);

        $this->loadWithDriver(new AttributeDriver([]), $className);
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

#[ORM\Entity]
class DynamicToManyWithJoinColumnsEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    #[ORM\DynamicAssociation('vendors', new ORM\ManyToMany(targetEntity: Vendor::class), joinColumns: [new ORM\JoinColumn(name: 'vendor_id')])]
    public array $values = [];
}

#[ORM\Entity]
class DynamicManyToOneWithJoinTableEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    #[ORM\DynamicAssociation('vendor', new ORM\ManyToOne(targetEntity: Vendor::class), joinTable: new ORM\JoinTable(name: 'vendor_join'))]
    public array $values = [];
}

#[ORM\Entity]
class DynamicManyToOneWithOrderByEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public int $id;

    /** @var array<string, mixed> */
    #[ORM\DynamicProperties]
    #[ORM\DynamicAssociation('vendor', new ORM\ManyToOne(targetEntity: Vendor::class), orderBy: ['name' => SortDirection::Ascending])]
    public array $values = [];
}
