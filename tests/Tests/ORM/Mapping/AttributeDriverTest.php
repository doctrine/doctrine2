<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Attribute;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\MappingAttribute;
use Doctrine\Persistence\Mapping\Driver\ClassNames;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Tests\Mocks\AttributeDriverFactory;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\ORM\Mapping\Fixtures\AttributeEntityWithNestedJoinColumns;
use Doctrine\Tests\ORM\Mapping\Fixtures\CompositeIdWithPosition;
use stdClass;

class AttributeDriverTest extends MappingDriverTestCase
{
    protected function loadDriver(): MappingDriver
    {
        return AttributeDriverFactory::createAttributeDriver();
    }

    public function testDriverCanAcceptClassLocator(): void
    {
        if (! AttributeDriverFactory::isClassLocatorSupported()) {
            self::markTestSkipped('This test is only relevant for versions of doctrine/persistence >= 4.1');
        }

        $classLocator = new ClassNames([City::class]);

        $driver = new AttributeDriver($classLocator);

        self::assertSame([], $driver->getPaths(), 'Directory paths must be empty, since file paths are used');
        self::assertSame([City::class], $driver->getAllClassNames());
    }

    public function testOriginallyNestedAttributesDeclaredWithoutOriginalParent(): void
    {
        $factory = $this->createClassMetadataFactory();

        $metadata = $factory->getMetadataFor(AttributeEntityWithoutOriginalParents::class);

        self::assertEquals(
            [
                'name' => 'AttributeEntityWithoutOriginalParents',
                'uniqueConstraints' => ['foo' => ['columns' => ['id']]],
                'indexes' => ['bar' => ['columns' => ['id']]],
            ],
            $metadata->table,
        );
        self::assertEquals(['assoz_id', 'assoz_id'], $metadata->associationMappings['assoc']->joinTableColumns);
    }

    public function testIsTransient(): void
    {
        $driver = $this->loadDriver();

        self::assertTrue($driver->isTransient(stdClass::class));

        self::assertTrue($driver->isTransient(AttributeTransientClass::class));

        self::assertFalse($driver->isTransient(AttributeEntityWithoutOriginalParents::class));

        self::assertFalse($driver->isTransient(AttributeEntityStartingWithRepeatableAttributes::class));
    }

    public function testManyToManyAssociationWithNestedJoinColumns(): void
    {
        $factory = $this->createClassMetadataFactory();

        $metadata = $factory->getMetadataFor(AttributeEntityWithNestedJoinColumns::class);

        self::assertEquals(
            [
                JoinColumnMapping::fromMappingArray([
                    'name' => 'assoz_id',
                    'referencedColumnName' => 'assoz_id',
                    'unique' => false,
                    'nullable' => null,
                    'onDelete' => null,
                    'columnDefinition' => null,
                ]),
            ],
            $metadata->associationMappings['assoc']->joinTable->joinColumns,
        );

        self::assertEquals(
            [
                JoinColumnMapping::fromMappingArray([
                    'name' => 'inverse_assoz_id',
                    'referencedColumnName' => 'inverse_assoz_id',
                    'unique' => false,
                    'nullable' => null,
                    'onDelete' => null,
                    'columnDefinition' => null,
                ]),
            ],
            $metadata->associationMappings['assoc']->joinTable->inverseJoinColumns,
        );
    }

    public function testCompositeIdPositionOrdering(): void
    {
        $factory = $this->createClassMetadataFactory();

        $metadata = $factory->getMetadataFor(CompositeIdWithPosition::class);

        self::assertSame(['first', 'second'], $metadata->identifier);
    }
}

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'foo', columns: ['id'])]
#[ORM\Index(name: 'bar', columns: ['id'])]
class AttributeEntityWithoutOriginalParents
{
    /** @var int */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public $id;

    /** @var Collection<AttributeEntityWithoutOriginalParents> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'assoz_id', referencedColumnName: 'assoz_id')]
    #[ORM\InverseJoinColumn(name: 'assoz_id', referencedColumnName: 'assoz_id')]
    public $assoc;
}

#[ORM\Index(name: 'bar', columns: ['id'])]
#[ORM\Index(name: 'baz', columns: ['id'])]
#[ORM\Entity]
class AttributeEntityStartingWithRepeatableAttributes
{
}

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_ALL)]
class AttributeTransientAttribute implements MappingAttribute
{
}

#[AttributeTransientAttribute]
class AttributeTransientClass
{
}
