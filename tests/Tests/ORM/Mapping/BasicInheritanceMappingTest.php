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
use Doctrine\ORM\Mapping\Embeddable;
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
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\Company\CompanyFixContract;
use Doctrine\Tests\Models\DDC869\DDC869ChequePayment;
use Doctrine\Tests\Models\DDC869\DDC869CreditCardPayment;
use Doctrine\Tests\Models\DDC869\DDC869Payment;
use Doctrine\Tests\Models\DDC869\DDC869PaymentRepository;
use Doctrine\Tests\OrmTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function assert;
use function serialize;
use function sprintf;
use function unserialize;

class BasicInheritanceMappingTest extends OrmTestCase
{
    private ClassMetadataFactory $cmf;

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

        self::assertEmpty($class->subClasses);
        self::assertEmpty($class->parentClasses);
        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('name', $class->fieldMappings);
    }

    public function testGetMetadataForSubclassWithMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(EntitySubClass2::class);

        self::assertEmpty($class->subClasses);
        self::assertEmpty($class->parentClasses);

        self::assertArrayHasKey('mapped1', $class->fieldMappings);
        self::assertArrayHasKey('mapped2', $class->fieldMappings);
        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('name', $class->fieldMappings);

        self::assertNull($class->fieldMappings['mapped1']->inherited);
        self::assertNull($class->fieldMappings['mapped2']->inherited);
        self::assertArrayNotHasKey('transient', $class->fieldMappings);

        self::assertArrayHasKey('mappedRelated1', $class->associationMappings);
    }

    #[Group('DDC-869')]
    public function testGetMetadataForSubclassWithMappedSuperclassWithRepository(): void
    {
        $class = $this->cmf->getMetadataFor(DDC869CreditCardPayment::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('value', $class->fieldMappings);
        self::assertArrayHasKey('creditCardNumber', $class->fieldMappings);
        self::assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);

        $class = $this->cmf->getMetadataFor(DDC869ChequePayment::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('value', $class->fieldMappings);
        self::assertArrayHasKey('serialNumber', $class->fieldMappings);
        self::assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);

        // override repositoryClass
        $class = $this->cmf->getMetadataFor(SubclassWithRepository::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('value', $class->fieldMappings);
        self::assertEquals($class->customRepositoryClassName, EntityRepository::class);
    }

    #[Group('DDC-388')]
    public function testSerializationWithPrivateFieldsFromMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(EntitySubClass2::class);

        $class2 = unserialize(serialize($class));
        $class2->wakeupReflection(new RuntimeReflectionService());

        self::assertArrayHasKey('mapped1', $class2->propertyAccessors);
        self::assertArrayHasKey('mapped2', $class2->propertyAccessors);
        self::assertArrayHasKey('mappedRelated1', $class2->propertyAccessors);
    }

    #[Group('DDC-1203')]
    public function testUnmappedSuperclassInHierarchy(): void
    {
        $class = $this->cmf->getMetadataFor(HierarchyD::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('a', $class->fieldMappings);
        self::assertArrayHasKey('d', $class->fieldMappings);
    }

    #[Group('DDC-1204')]
    public function testUnmappedEntityInHierarchy(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            'Entity \'Doctrine\Tests\ORM\Mapping\HierarchyBEntity\' has to be part of the discriminator map'
            . ' of \'Doctrine\Tests\ORM\Mapping\HierarchyBase\' to be properly mapped in the inheritance hierarchy.'
            . ' Alternatively you can make \'Doctrine\Tests\ORM\Mapping\HierarchyBEntity\' an abstract class to'
            . ' avoid this exception from occurring.',
        );

        $this->cmf->getMetadataFor(HierarchyE::class);
    }

    #[Group('DDC-1204')]
    #[Group('DDC-1203')]
    public function testMappedSuperclassWithId(): void
    {
        $class = $this->cmf->getMetadataFor(SuperclassEntity::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
    }

    #[Group('DDC-1156')]
    #[Group('DDC-1218')]
    #[Group('GH-10927')]
    public function testSequenceDefinitionInHierarchyWithSandwichMappedSuperclass(): void
    {
        $class = $this->cmf->getMetadataFor(HierarchyD::class);
        assert($class instanceof ClassMetadata);

        self::assertInstanceOf(IdSequenceGenerator::class, $class->idGenerator);
        self::assertEquals(
            ['allocationSize' => 1, 'initialValue' => 10, 'sequenceName' => 'foo'],
            $class->sequenceGeneratorDefinition,
        );
    }

    /**
     * Ensure indexes are inherited from the mapped superclass.
     */
    #[Group('DDC-3418')]
    public function testMappedSuperclassIndex(): void
    {
        $class = $this->cmf->getMetadataFor(EntityIndexSubClass::class);
        assert($class instanceof ClassMetadata);

        self::assertArrayHasKey('mapped1', $class->fieldMappings);
        self::assertArrayHasKey('IDX_NAME_INDEX', $class->table['uniqueConstraints']);
        self::assertArrayHasKey('IDX_MAPPED1_INDEX', $class->table['uniqueConstraints']);
        self::assertArrayHasKey('IDX_MAPPED2_INDEX', $class->table['indexes']);
    }

    /**
     * Ensure unnamed indexes are inherited from the mapped superclass
     * even when the entity declares unnamed indexes of its own.
     */
    #[Group('GH-12179')]
    public function testMappedSuperclassUnnamedIndexes(): void
    {
        $class = $this->cmf->getMetadataFor(EntityUnnamedIndexSubClass::class);
        assert($class instanceof ClassMetadata);

        self::assertSame(
            [
                ['columns' => ['name']],
                ['columns' => ['mapped1']],
            ],
            $class->table['uniqueConstraints'],
        );

        // The indexes on mapped3 and mapped4 are declared by both classes: they must not be duplicated
        self::assertSame(
            [
                ['columns' => ['code']],
                ['columns' => ['mapped3']],
                ['fields' => ['mapped4']],
                ['columns' => ['mapped2']],
            ],
            $class->table['indexes'],
        );
    }

    /**
     * Ensure a named index of the entity replaces an unnamed index of the
     * mapped superclass on the same columns instead of duplicating it.
     */
    #[Group('GH-12179')]
    public function testMappedSuperclassUnnamedIndexOverriddenByNamedIndex(): void
    {
        $class = $this->cmf->getMetadataFor(EntityNamedIndexSubClass::class);
        assert($class instanceof ClassMetadata);

        self::assertSame(
            [['columns' => ['mapped1']]],
            $class->table['uniqueConstraints'],
        );

        self::assertSame(
            [
                'IDX_MAPPED2' => ['columns' => ['mapped2']],
                0 => ['columns' => ['mapped3']],
                1 => ['fields' => ['mapped4']],
            ],
            $class->table['indexes'],
        );
    }

    #[DataProvider('invalidHierarchyDeclarationClasses')]
    public function testUndeclaredHierarchyRejection(string $rootEntity, string $childClass): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(sprintf(
            "Entity class '%s' is a subclass of the root entity class '%s', but no inheritance mapping type was declared.",
            $childClass,
            $rootEntity,
        ));

        $this->cmf->getMetadataFor($childClass);
    }

    public static function invalidHierarchyDeclarationClasses(): Generator
    {
        yield 'concrete Entity root and child class, direct inheritance'
            => [InvalidEntityRoot::class, InvalidEntityRootChild::class];

        yield 'concrete Entity root and abstract child class, direct inheritance'
            => [InvalidEntityRoot::class, InvalidEntityRootAbstractChild::class];

        yield 'abstract Entity root and concrete child class, direct inheritance'
            => [InvalidAbstractEntityRoot::class, InvalidAbstractEntityRootChild::class];

        yield 'abstract Entity root and abstract child class, direct inheritance'
            => [InvalidAbstractEntityRoot::class, InvalidAbstractEntityRootAbstractChild::class];

        yield 'complex example (Entity Root -> Mapped Superclass -> transient class -> Entity)'
            => [InvalidComplexRoot::class, InvalidComplexEntity::class];
    }

    public function testEmbeddableSubclassDoesNotRequireInheritanceTypeDeclaration(): void
    {
        $class = $this->cmf->getMetadataFor(EmbeddableSubclass::class);

        self::assertTrue($class->isEmbeddedClass);
    }

    #[Group('DDC-964')]
    public function testInvalidOverrideFieldInheritedFromEntity(): void
    {
        $cm = $this->cmf->getMetadataFor(CompanyFixContract::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessageMatches('/Overrides are only allowed for fields or associations declared in mapped superclasses or traits./');

        $cm->setAttributeOverride('completed', ['name' => 'other_column_name']);
    }

    public function testInvalidOverrideAssociationInheritedFromEntity(): void
    {
        $cm = $this->cmf->getMetadataFor(CompanyFixContract::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Overrides are only allowed for fields or associations declared in mapped superclasses or traits. This is not the case for Doctrine\Tests\Models\Company\CompanyFixContract::salesPerson, which was inherited from Doctrine\Tests\Models\Company\CompanyContract.');

        $cm->setAssociationOverride('salesPerson', ['inversedBy' => 'other_inversed_by_name']);
    }
}

class TransientBaseClass
{
    /** @var mixed */
    private $transient1;

    /** @var mixed */
    private $transient2;
}

#[Entity]
class EntitySubClass extends TransientBaseClass
{
    #[Id]
    #[Column(type: 'integer')]
    private int $id;

    #[Column(type: 'string', length: 255)]
    private string $name;
}

#[MappedSuperclass]
class MappedSuperclassBase
{
    #[Column(type: 'integer')]
    private int $mapped1;

    #[Column(type: 'string', length: 255)]
    private string $mapped2;

    #[OneToOne(targetEntity: 'MappedSuperclassRelated1')]
    #[JoinColumn(name: 'related1_id', referencedColumnName: 'id')]
    private MappedSuperclassRelated1 $mappedRelated1;

    /** @var mixed */
    private $transient;
}

#[Entity]
class MappedSuperclassRelated1
{
}

#[Entity]
class EntitySubClass2 extends MappedSuperclassBase
{
    #[Id]
    #[Column(type: 'integer')]
    private int $id;

    #[Column(type: 'string', length: 255)]
    private string $name;
}

#[Table]
#[Index(name: 'IDX_MAPPED2_INDEX', columns: ['mapped2'])]
#[UniqueConstraint(name: 'IDX_MAPPED1_INDEX', columns: ['mapped1'])]
#[MappedSuperclass]
class MappedSuperclassBaseIndex
{
    #[Column(type: 'string', length: 255)]
    private string $mapped1;
    #[Column(type: 'string', length: 255)]
    private string $mapped2;
}

#[Table]
#[UniqueConstraint(name: 'IDX_NAME_INDEX', columns: ['name'])]
#[Entity]
class EntityIndexSubClass extends MappedSuperclassBaseIndex
{
    #[Id]
    #[Column(type: 'integer')]
    private int $id;

    #[Column(type: 'string', length: 255)]
    private string $name;
}

#[Table]
#[UniqueConstraint(columns: ['mapped1'])]
#[Index(columns: ['mapped2'])]
#[Index(columns: ['mapped3'])]
#[Index(fields: ['mapped4'])]
#[MappedSuperclass]
class MappedSuperclassBaseUnnamedIndex
{
    #[Column(type: 'string', length: 255)]
    private string $mapped1;
    #[Column(type: 'string', length: 255)]
    private string $mapped2;
    #[Column(type: 'string', length: 255)]
    private string $mapped3;
    #[Column(type: 'string', length: 255)]
    private string $mapped4;
}

#[Table]
#[UniqueConstraint(columns: ['name'])]
#[Index(columns: ['code'])]
#[Index(columns: ['mapped3'])]
#[Index(fields: ['mapped4'])]
#[Entity]
class EntityUnnamedIndexSubClass extends MappedSuperclassBaseUnnamedIndex
{
    #[Id]
    #[Column(type: 'integer')]
    private int $id;

    #[Column(type: 'string', length: 255)]
    private string $name;

    #[Column(type: 'string', length: 255)]
    private string $code;
}

#[Table]
#[Index(name: 'IDX_MAPPED2', columns: ['mapped2'])]
#[Entity]
class EntityNamedIndexSubClass extends MappedSuperclassBaseUnnamedIndex
{
    #[Id]
    #[Column(type: 'integer')]
    private int $id;
}

#[Entity]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'type', type: 'string', length: 20)]
#[DiscriminatorMap(['c' => 'HierarchyC', 'd' => 'HierarchyD', 'e' => 'HierarchyE'])]
abstract class HierarchyBase
{
    /** @var int */
    #[Column(type: 'integer')]
    #[Id]
    #[GeneratedValue(strategy: 'SEQUENCE')]
    #[SequenceGenerator(sequenceName: 'foo', initialValue: 10)]
    public $id;
}

#[MappedSuperclass]
abstract class HierarchyASuperclass extends HierarchyBase
{
    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $a;
}

#[Entity]
class HierarchyBEntity extends HierarchyBase
{
    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $b;
}

#[Entity]
class HierarchyC extends HierarchyBase
{
    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $c;
}

#[Entity]
class HierarchyD extends HierarchyASuperclass
{
    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $d;
}

#[Entity]
class HierarchyE extends HierarchyBEntity
{
    /** @var string */
    #[Column(type: 'string', length: 255)]
    public $e;
}

#[Entity]
class SuperclassEntity extends SuperclassBase
{
}

#[MappedSuperclass]
abstract class SuperclassBase
{
    /** @var int */
    #[Column(type: 'integer')]
    #[Id]
    #[GeneratedValue(strategy: 'SEQUENCE')]
    #[SequenceGenerator(sequenceName: 'foo', initialValue: 10)]
    public $id;
}

#[Entity(repositoryClass: 'Doctrine\ORM\EntityRepository')]
class SubclassWithRepository extends DDC869Payment
{
}

/** This class misses the DiscriminatorMap declaration */
#[Entity]
class InvalidEntityRoot
{
    #[Column]
    #[Id]
    #[GeneratedValue]
    public int|null $id = null;
}

#[Entity]
class InvalidEntityRootChild extends InvalidEntityRoot
{
}

#[Entity]
abstract class InvalidEntityRootAbstractChild extends InvalidEntityRoot
{
}

/** This class misses the DiscriminatorMap declaration */
#[Entity]
class InvalidAbstractEntityRoot
{
    #[Column]
    #[Id]
    #[GeneratedValue]
    public int|null $id = null;
}

#[Entity]
class InvalidAbstractEntityRootChild extends InvalidAbstractEntityRoot
{
}

#[Entity]
abstract class InvalidAbstractEntityRootAbstractChild extends InvalidAbstractEntityRoot
{
}

/** This class misses the DiscriminatorMap declaration */
#[Entity]
class InvalidComplexRoot
{
    #[Column]
    #[Id]
    #[GeneratedValue]
    public int|null $id = null;
}

#[MappedSuperclass]
class InvalidComplexMappedSuperclass extends InvalidComplexRoot
{
}

class InvalidComplexTransientClass extends InvalidComplexMappedSuperclass
{
}

#[Entity]
class InvalidComplexEntity extends InvalidComplexTransientClass
{
}

#[Embeddable]
class EmbeddableBase
{
    #[Column]
    public string|null $value = null;
}

#[Embeddable]
class EmbeddableSubclass extends EmbeddableBase
{
    #[Column]
    public string|null $extraValue = null;
}
