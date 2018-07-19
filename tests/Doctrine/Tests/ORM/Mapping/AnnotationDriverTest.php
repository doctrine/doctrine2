<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\DDC1872\DDC1872ExampleEntityWithoutOverride;
use Doctrine\Tests\Models\DDC1872\DDC1872ExampleEntityWithOverride;
use Doctrine\Tests\Models\DirectoryTree\Directory;
use Doctrine\Tests\Models\DirectoryTree\File;
use Doctrine\Tests\Models\ECommerce\ECommerceCart;
use function iterator_to_array;

class AnnotationDriverTest extends AbstractMappingDriverTest
{
    /**
     * @group DDC-268
     */
    public function testLoadMetadataForNonEntityThrowsException() : void
    {
        $cm = new ClassMetadata('stdClass', $this->metadataBuildingContext);

        $mappingDriver = $this->loadDriver();

        $this->expectException(MappingException::class);

        $mappingDriver->loadMetadataForClass('stdClass', $cm, $this->metadataBuildingContext);
    }

    /**
     * @expectedException \Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Entity association field "Doctrine\Tests\ORM\Mapping\AnnotationSLC#foo" not configured as part of the second-level cache.
     */
    public function testFailingSecondLevelCacheAssociation() : void
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata(AnnotationSLC::class, $this->metadataBuildingContext);
        $mappingDriver->loadMetadataForClass(AnnotationSLC::class, $class, $this->metadataBuildingContext);
    }

    /**
     * @group DDC-268
     */
    public function testColumnWithMissingTypeDefaultsToString() : void
    {
        $cm = new ClassMetadata(ColumnWithoutType::class, $this->metadataBuildingContext);

        $mappingDriver = $this->loadDriver();

        $mappingDriver->loadMetadataForClass(ColumnWithoutType::class, $cm, $this->metadataBuildingContext);

        self::assertNotNull($cm->getProperty('id'));

        $idProperty = $cm->getProperty('id');

        self::assertEquals('string', $idProperty->getTypeName());
    }

    /**
     * @group DDC-318
     */
    public function testGetAllClassNamesIsIdempotent() : void
    {
        $annotationDriver = $this->loadDriverForCMSModels();
        $original         = $annotationDriver->getAllClassNames();

        $annotationDriver = $this->loadDriverForCMSModels();
        $afterTestReset   = $annotationDriver->getAllClassNames();

        self::assertEquals($original, $afterTestReset);
    }

    /**
     * @group DDC-318
     */
    public function testGetAllClassNamesIsIdempotentEvenWithDifferentDriverInstances() : void
    {
        $annotationDriver = $this->loadDriverForCMSModels();
        $original         = $annotationDriver->getAllClassNames();

        $annotationDriver = $this->loadDriverForCMSModels();
        $afterTestReset   = $annotationDriver->getAllClassNames();

        self::assertEquals($original, $afterTestReset);
    }

    /**
     * @group DDC-318
     */
    public function testGetAllClassNamesReturnsAlreadyLoadedClassesIfAppropriate() : void
    {
        $this->ensureIsLoaded(CmsUser::class);

        $annotationDriver = $this->loadDriverForCMSModels();
        $classes          = $annotationDriver->getAllClassNames();

        self::assertContains(CmsUser::class, $classes);
    }

    /**
     * @group DDC-318
     */
    public function testGetClassNamesReturnsOnlyTheAppropriateClasses() : void
    {
        $this->ensureIsLoaded(ECommerceCart::class);

        $annotationDriver = $this->loadDriverForCMSModels();
        $classes          = $annotationDriver->getAllClassNames();

        self::assertNotContains(ECommerceCart::class, $classes);
    }

    protected function loadDriverForCMSModels()
    {
        $annotationDriver = $this->loadDriver();
        $annotationDriver->addPaths([__DIR__ . '/../../Models/CMS/']);
        return $annotationDriver;
    }

    protected function loadDriver()
    {
        return $this->createAnnotationDriver();
    }

    protected function ensureIsLoaded($entityClassName)
    {
        new $entityClassName();
    }

    /**
     * @group DDC-671
     *
     * Entities for this test are in AbstractMappingDriverTest
     */
    public function testJoinTablesWithMappedSuperclassForAnnotationDriver() : void
    {
        $annotationDriver = $this->loadDriver();
        $annotationDriver->addPaths([__DIR__ . '/../../Models/DirectoryTree/']);

        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setMetadataDriverImpl($annotationDriver);
        $factory = new ClassMetadataFactory();
        $factory->setEntityManager($em);

        $classPage = $factory->getMetadataFor(File::class);
        self::assertArrayHasKey('parentDirectory', iterator_to_array($classPage->getDeclaredPropertiesIterator()));
        self::assertEquals(File::class, $classPage->getProperty('parentDirectory')->getSourceEntity());

        $classDirectory = $factory->getMetadataFor(Directory::class);
        self::assertArrayHasKey('parentDirectory', iterator_to_array($classDirectory->getDeclaredPropertiesIterator()));
        self::assertEquals(Directory::class, $classDirectory->getProperty('parentDirectory')->getSourceEntity());
    }

    /**
     * @group DDC-945
     */
    public function testInvalidMappedSuperClassWithManyToManyAssociation() : void
    {
        $annotationDriver = $this->loadDriver();

        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setMetadataDriverImpl($annotationDriver);
        $factory = new ClassMetadataFactory();
        $factory->setEntityManager($em);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            'It is illegal to put an inverse side one-to-many or many-to-many association on ' .
            "mapped superclass 'Doctrine\Tests\ORM\Mapping\InvalidMappedSuperClass#users'"
        );

        $factory->getMetadataFor(UsingInvalidMappedSuperClass::class);
    }

    /**
     * @group DDC-1034
     */
    public function testInheritanceSkipsParentLifecycleCallbacks() : void
    {
        $annotationDriver = $this->loadDriver();

        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setMetadataDriverImpl($annotationDriver);
        $factory = new ClassMetadataFactory();
        $factory->setEntityManager($em);

        $cm = $factory->getMetadataFor(AnnotationChild::class);
        self::assertEquals(['postLoad' => ['postLoad'], 'preUpdate' => ['preUpdate']], $cm->lifecycleCallbacks);

        $cm = $factory->getMetadataFor(AnnotationParent::class);
        self::assertEquals(['postLoad' => ['postLoad'], 'preUpdate' => ['preUpdate']], $cm->lifecycleCallbacks);
    }

    /**
     * @group DDC-1156
     */
    public function testMappedSuperclassInMiddleOfInheritanceHierarchy() : void
    {
        $annotationDriver = $this->loadDriver();

        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setMetadataDriverImpl($annotationDriver);

        $factory = new ClassMetadataFactory();
        $factory->setEntityManager($em);

        self::assertInstanceOf(ClassMetadata::class, $factory->getMetadataFor(ChildEntity::class));
    }

    public function testInvalidFetchOptionThrowsException() : void
    {
        $annotationDriver = $this->loadDriver();

        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setMetadataDriverImpl($annotationDriver);
        $factory = new ClassMetadataFactory();
        $factory->setEntityManager($em);

        $this->expectException(AnnotationException::class);
        $this->expectExceptionMessage('[Enum Error] Attribute "fetch" of @Doctrine\ORM\Annotation\OneToMany declared on property Doctrine\Tests\ORM\Mapping\InvalidFetchOption::$collection accept only [LAZY, EAGER, EXTRA_LAZY], but got eager.');

        $factory->getMetadataFor(InvalidFetchOption::class);
    }

    public function testAttributeOverridesMappingWithTrait() : void
    {
        $factory = $this->createClassMetadataFactory();

        $metadataWithoutOverride = $factory->getMetadataFor(DDC1872ExampleEntityWithoutOverride::class);
        $metadataWithOverride    = $factory->getMetadataFor(DDC1872ExampleEntityWithOverride::class);

        self::assertNotNull($metadataWithoutOverride->getProperty('foo'));
        self::assertNotNull($metadataWithOverride->getProperty('foo'));

        $fooPropertyWithoutOverride = $metadataWithoutOverride->getProperty('foo');
        $fooPropertyWithOverride    = $metadataWithOverride->getProperty('foo');

        self::assertEquals('trait_foo', $fooPropertyWithoutOverride->getColumnName());
        self::assertEquals('foo_overridden', $fooPropertyWithOverride->getColumnName());

        $barPropertyWithoutOverride = $metadataWithoutOverride->getProperty('bar');
        $barPropertyWithOverride    = $metadataWithOverride->getProperty('bar');

        $barPropertyWithoutOverrideFirstJoinColumn = $barPropertyWithoutOverride->getJoinColumns()[0];
        $barPropertyWithOverrideFirstJoinColumn    = $barPropertyWithOverride->getJoinColumns()[0];

        self::assertEquals('example_trait_bar_id', $barPropertyWithoutOverrideFirstJoinColumn->getColumnName());
        self::assertEquals('example_entity_overridden_bar_id', $barPropertyWithOverrideFirstJoinColumn->getColumnName());
    }
}

/**
 * @ORM\MappedSuperclass
 */
class InvalidMappedSuperClass
{
    /** @ORM\ManyToMany(targetEntity=CmsUser::class, mappedBy="invalid") */
    private $users;
}

/**
 * @ORM\Entity
 */
class UsingInvalidMappedSuperClass extends InvalidMappedSuperClass
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue */
    private $id;
}

/**
 * @ORM\Entity
 */
class ColumnWithoutType
{
    /** @ORM\Id @ORM\Column */
    public $id;
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("JOINED")
 * @ORM\DiscriminatorMap({"parent" = AnnotationParent::class, "child" = AnnotationChild::class})
 * @ORM\HasLifecycleCallbacks
 */
class AnnotationParent
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue */
    private $id;

    /**
     * @ORM\PostLoad
     */
    public function postLoad()
    {
    }

    /**
     * @ORM\PreUpdate
     */
    public function preUpdate()
    {
    }
}

/**
 * @ORM\Entity
 * @ORM\HasLifecycleCallbacks
 */
class AnnotationChild extends AnnotationParent
{
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorMap({"s"=SuperEntity::class, "c"=ChildEntity::class})
 */
class SuperEntity
{
    /** @ORM\Id @ORM\Column(type="string") */
    private $id;
}

/**
 * @ORM\MappedSuperclass
 */
class MiddleMappedSuperclass extends SuperEntity
{
    /** @ORM\Column(type="string") */
    private $name;
}

/**
 * @ORM\Entity
 */
class ChildEntity extends MiddleMappedSuperclass
{
    /** @ORM\Column(type="string") */
    private $text;
}

/**
 * @ORM\Entity
 */
class InvalidFetchOption
{
    /** @ORM\OneToMany(targetEntity=CmsUser::class, fetch="eager") */
    private $collection;
}

/**
 * @ORM\Entity
 * @ORM\Cache
 */
class AnnotationSLC
{
    /**
     * @ORM\Id
     * @ORM\ManyToOne(targetEntity=AnnotationSLCFoo::class)
     */
    public $foo;
}
/**
 * @ORM\Entity
 */
class AnnotationSLCFoo
{
    /** @ORM\Column(type="string") */
    public $id;
}
