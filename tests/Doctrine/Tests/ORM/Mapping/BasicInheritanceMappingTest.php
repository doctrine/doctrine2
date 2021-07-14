<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Id\SequenceGenerator as IdSequenceGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\SequenceGenerator;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\DDC869\DDC869ChequePayment;
use Doctrine\Tests\Models\DDC869\DDC869CreditCardPayment;
use Doctrine\Tests\Models\DDC869\DDC869Payment;
use Doctrine\Tests\Models\DDC869\DDC869PaymentRepository;
use Doctrine\Tests\OrmTestCase;

use function assert;
use function serialize;
use function unserialize;

class BasicInheritanceMappingTest extends OrmTestCase
{
    /** @var ClassMetadataFactory */
    private $cmf;

    protected function setUp(): void
    {
        $this->cmf = new ClassMetadataFactory();

        $this->cmf->setEntityManager($this->getTestEntityManager());
    }

    public function testGetMetadataForTransientClassThrowsException(): void
    {
        $this->expectException(MappingException::class);

        $this->cmf->getMetadataFor(TransientBaseClass::class);
    }

    public function testGetMetadataForSubclassWithTransientBaseClass(): void
    {
        $class = $this->cmf->getMetadataFor(EntitySubClass::class);

        $this->assertEmpty($class->subClasses);
        $this->assertEmpty($class->parentClasses);
        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('name', $class->fieldMappings);
    }

    public function testGetMetadataForSubclassWithMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(EntitySubClass2::class);

        $this->assertEmpty($class->subClasses);
        $this->assertEmpty($class->parentClasses);

        $this->assertArrayHasKey('mapped1', $class->fieldMappings);
        $this->assertArrayHasKey('mapped2', $class->fieldMappings);
        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('name', $class->fieldMappings);

        $this->assertArrayNotHasKey('inherited', $class->fieldMappings['mapped1']);
        $this->assertArrayNotHasKey('inherited', $class->fieldMappings['mapped2']);
        $this->assertArrayNotHasKey('transient', $class->fieldMappings);

        $this->assertArrayHasKey('mappedRelated1', $class->associationMappings);
    }

    /**
     * @group DDC-869
     */
    public function testGetMetadataForSubclassWithMappedSuperclassWithRepository(): void
    {
        $class = $this->cmf->getMetadataFor(DDC869CreditCardPayment::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('value', $class->fieldMappings);
        $this->assertArrayHasKey('creditCardNumber', $class->fieldMappings);
        $this->assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);

        $class = $this->cmf->getMetadataFor(DDC869ChequePayment::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('value', $class->fieldMappings);
        $this->assertArrayHasKey('serialNumber', $class->fieldMappings);
        $this->assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);

        // override repositoryClass
        $class = $this->cmf->getMetadataFor(SubclassWithRepository::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('value', $class->fieldMappings);
        $this->assertEquals($class->customRepositoryClassName, EntityRepository::class);
    }

    /**
     * @group DDC-388
     */
    public function testSerializationWithPrivateFieldsFromMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(EntitySubClass2::class);

        $class2 = unserialize(serialize($class));
        $class2->wakeupReflection(new RuntimeReflectionService());

        $this->assertArrayHasKey('mapped1', $class2->reflFields);
        $this->assertArrayHasKey('mapped2', $class2->reflFields);
        $this->assertArrayHasKey('mappedRelated1', $class2->reflFields);
    }

    /**
     * @group DDC-1203
     */
    public function testUnmappedSuperclassInHierarchy(): void
    {
        $class = $this->cmf->getMetadataFor(HierarchyD::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('a', $class->fieldMappings);
        $this->assertArrayHasKey('d', $class->fieldMappings);
    }

    /**
     * @group DDC-1204
     */
    public function testUnmappedEntityInHierarchy(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            'Entity \'Doctrine\Tests\ORM\Mapping\HierarchyBEntity\' has to be part of the discriminator map'
            . ' of \'Doctrine\Tests\ORM\Mapping\HierarchyBase\' to be properly mapped in the inheritance hierarchy.'
            . ' Alternatively you can make \'Doctrine\Tests\ORM\Mapping\HierarchyBEntity\' an abstract class to'
            . ' avoid this exception from occurring.'
        );

        $this->cmf->getMetadataFor(HierarchyE::class);
    }

    /**
     * @group DDC-1204
     * @group DDC-1203
     */
    public function testMappedSuperclassWithId(): void
    {
        $class = $this->cmf->getMetadataFor(SuperclassEntity::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
    }

    /**
     * @group DDC-1156
     * @group DDC-1218
     */
    public function testGeneratedValueFromMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(SuperclassEntity::class);
        assert($class instanceof ClassMetadata);

        $this->assertInstanceOf(IdSequenceGenerator::class, $class->idGenerator);
        $this->assertEquals(
            ['allocationSize' => 1, 'initialValue' => 10, 'sequenceName' => 'foo'],
            $class->sequenceGeneratorDefinition
        );
    }

    /**
     * @group DDC-1156
     * @group DDC-1218
     */
    public function testSequenceDefinitionInHierarchyWithSandwichMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(HierarchyD::class);
        assert($class instanceof ClassMetadata);

        $this->assertInstanceOf(IdSequenceGenerator::class, $class->idGenerator);
        $this->assertEquals(
            ['allocationSize' => 1, 'initialValue' => 10, 'sequenceName' => 'foo'],
            $class->sequenceGeneratorDefinition
        );
    }

    /**
     * @group DDC-1156
     * @group DDC-1218
     */
    public function testMultipleMappedSuperclasses(): void
    {
        $class = $this->cmf->getMetadataFor(MediumSuperclassEntity::class);
        assert($class instanceof ClassMetadata);

        $this->assertInstanceOf(IdSequenceGenerator::class, $class->idGenerator);
        $this->assertEquals(
            ['allocationSize' => 1, 'initialValue' => 10, 'sequenceName' => 'foo'],
            $class->sequenceGeneratorDefinition
        );
    }

    /**
     * Ensure indexes are inherited from the mapped superclass.
     *
     * @group DDC-3418
     */
    public function testMappedSuperclassIndex(): void
    {
        $class = $this->cmf->getMetadataFor(EntityIndexSubClass::class);
        assert($class instanceof ClassMetadata);

        $this->assertArrayHasKey('mapped1', $class->fieldMappings);
        $this->assertArrayHasKey('IDX_NAME_INDEX', $class->table['uniqueConstraints']);
        $this->assertArrayHasKey('IDX_MAPPED1_INDEX', $class->table['uniqueConstraints']);
        $this->assertArrayHasKey('IDX_MAPPED2_INDEX', $class->table['indexes']);
    }
}

class TransientBaseClass
{
    /** @var mixed */
    private $transient1;

    /** @var mixed */
    private $transient2;
}

/** @Entity */
class EntitySubClass extends TransientBaseClass
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $name;
}

/** @MappedSuperclass */
class MappedSuperclassBase
{
    /**
     * @var int
     * @Column(type="integer")
     */
    private $mapped1;

    /**
     * @var string
     * @Column(type="string")
     */
    private $mapped2;

    /**
     * @var MappedSuperclassRelated1
     * @OneToOne(targetEntity="MappedSuperclassRelated1")
     * @JoinColumn(name="related1_id", referencedColumnName="id")
     */
    private $mappedRelated1;

    /** @var mixed */
    private $transient;
}

class MappedSuperclassRelated1
{
}

/** @Entity */
class EntitySubClass2 extends MappedSuperclassBase
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $name;
}

/**
 * @MappedSuperclass
 * @Table(
 *  uniqueConstraints={@UniqueConstraint(name="IDX_MAPPED1_INDEX",columns={"mapped1"})},
 *  indexes={@Index(name="IDX_MAPPED2_INDEX", columns={"mapped2"})}
 * )
 */
class MappedSuperclassBaseIndex
{
    /**
     * @var string
     * @Column(type="string")
     */
    private $mapped1;
    /**
     * @var string
     * @Column(type="string")
     */
    private $mapped2;
}

/** @Entity @Table(uniqueConstraints={@UniqueConstraint(name="IDX_NAME_INDEX",columns={"name"})}) */
class EntityIndexSubClass extends MappedSuperclassBaseIndex
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $name;
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="type", type="string", length=20)
 * @DiscriminatorMap({
 *     "c"   = "HierarchyC",
 *     "d"   = "HierarchyD",
 *     "e"   = "HierarchyE"
 * })
 */
abstract class HierarchyBase
{
    /**
     * @Column(type="integer") @Id @GeneratedValue(strategy="SEQUENCE")
     * @SequenceGenerator(sequenceName="foo", initialValue=10)
     * @var int
     */
    public $id;
}

/** @MappedSuperclass */
abstract class HierarchyASuperclass extends HierarchyBase
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $a;
}

/** @Entity */
class HierarchyBEntity extends HierarchyBase
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $b;
}

/** @Entity */
class HierarchyC extends HierarchyBase
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $c;
}

/** @Entity */
class HierarchyD extends HierarchyASuperclass
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $d;
}

/** @Entity */
class HierarchyE extends HierarchyBEntity
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $e;
}

/** @Entity */
class SuperclassEntity extends SuperclassBase
{
}

/** @MappedSuperclass */
abstract class SuperclassBase
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="SEQUENCE")
     * @SequenceGenerator(sequenceName="foo", initialValue=10)
     */
    public $id;
}

/** @MappedSuperclass */
abstract class MediumSuperclassBase extends SuperclassBase
{
}

/** @Entity */
class MediumSuperclassEntity extends MediumSuperclassBase
{
}

/** @Entity(repositoryClass = "Doctrine\ORM\EntityRepository") */
class SubclassWithRepository extends DDC869Payment
{
}
