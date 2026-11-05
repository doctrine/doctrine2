<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Common\Collections\Collection;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DefaultTypedFieldMapper;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorColumnMapping;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\TypedFieldMapper;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\DbalTypes\CustomIdObjectType;
use Doctrine\Tests\DbalTypes\CustomIntType;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsAddressListener;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyContractListener;
use Doctrine\Tests\Models\Company\CompanyFixContract;
use Doctrine\Tests\Models\Company\CompanyFlexContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContractListener;
use Doctrine\Tests\Models\DDC1476\DDC1476EntityWithDefaultFieldType;
use Doctrine\Tests\Models\DDC2825\ExplicitSchemaAndTable;
use Doctrine\Tests\Models\DDC2825\SchemaAndTableInTableName;
use Doctrine\Tests\Models\DDC3579\DDC3579Admin;
use Doctrine\Tests\Models\DDC5934\DDC5934Contract;
use Doctrine\Tests\Models\DDC869\DDC869ChequePayment;
use Doctrine\Tests\Models\DDC869\DDC869CreditCardPayment;
use Doctrine\Tests\Models\DDC869\DDC869PaymentRepository;
use Doctrine\Tests\Models\DDC889\DDC889Class;
use Doctrine\Tests\Models\DDC889\DDC889Entity;
use Doctrine\Tests\Models\DDC964\DDC964Admin;
use Doctrine\Tests\Models\DDC964\DDC964Guest;
use Doctrine\Tests\Models\Enums\Card;
use Doctrine\Tests\Models\Enums\Suit;
use Doctrine\Tests\Models\GH10288\GH10288People;
use Doctrine\Tests\Models\TypedProperties\Contact;
use Doctrine\Tests\Models\TypedProperties\UserTyped;
use Doctrine\Tests\Models\TypedProperties\UserTypedWithCustomTypedField;
use Doctrine\Tests\Models\Upsertable\Insertable;
use Doctrine\Tests\Models\Upsertable\Updatable;
use Doctrine\Tests\ORM\Mapping\NamingStrategy\CustomPascalNamingStrategy;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\TestWith;
use SortDirection;
use stdClass;

use function assert;
use function count;
use function str_contains;
use function strtolower;

use const CASE_UPPER;

abstract class MappingDriverTestCase extends OrmTestCase
{
    use VerifyDeprecations;

    abstract protected function loadDriver(): MappingDriver;

    /** @param class-string<object> $entityClassName */
    public function createClassMetadata(
        string $entityClassName,
        NamingStrategy|null $namingStrategy = null,
        TypedFieldMapper|null $typedFieldMapper = null,
    ): ClassMetadata {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata($entityClassName, $namingStrategy, $typedFieldMapper);
        $class->initializeReflection(new RuntimeReflectionService());
        $mappingDriver->loadMetadataForClass($entityClassName, $class);

        return $class;
    }

    protected function createClassMetadataFactory(EntityManagerInterface|null $em = null): ClassMetadataFactory
    {
        $driver  = $this->loadDriver();
        $em    ??= $this->getTestEntityManager();
        $factory = new ClassMetadataFactory();
        $em->getConfiguration()->setMetadataDriverImpl($driver);
        $factory->setEntityManager($em);

        return $factory;
    }

    public function testEntityTableNameAndInheritance(): ClassMetadata
    {
        $class = $this->createClassMetadata(User::class);

        self::assertEquals('cms_users', $class->getTableName());
        self::assertEquals(ClassMetadata::INHERITANCE_TYPE_NONE, $class->inheritanceType);

        return $class;
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testEntityIndexes(ClassMetadata $class): ClassMetadata
    {
        self::assertArrayHasKey('indexes', $class->table, 'ClassMetadata should have indexes key in table property.');
        self::assertEquals(
            [
                'name_idx' => ['columns' => ['name']],
                0 => ['columns' => ['user_email']],
                'fields' => ['fields' => ['name', 'email']],
            ],
            $class->table['indexes'],
        );

        return $class;
    }

    public function testEntityIncorrectIndexes(): void
    {
        $this->expectException(MappingException::class);
        $this->createClassMetadata(UserIncorrectIndex::class);
    }

    public function testEntityIndexFlagsAndPartialIndexes(): void
    {
        $class = $this->createClassMetadata(Comment::class);

        self::assertEquals(
            [
                0 => [
                    'columns' => ['content'],
                    'flags' => ['fulltext'],
                    'options' => ['where' => 'content IS NOT NULL'],
                ],
            ],
            $class->table['indexes'],
        );
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testEntityUniqueConstraints(ClassMetadata $class): ClassMetadata
    {
        self::assertArrayHasKey(
            'uniqueConstraints',
            $class->table,
            'ClassMetadata should have uniqueConstraints key in table property when Unique Constraints are set.',
        );

        self::assertEquals(
            [
                'search_idx' => ['columns' => ['name', 'user_email'], 'options' => ['where' => 'name IS NOT NULL']],
                'phone_idx' => ['fields' => ['name', 'phone']],
            ],
            $class->table['uniqueConstraints'],
        );

        return $class;
    }

    public function testEntityIncorrectUniqueContraint(): void
    {
        $this->expectException(MappingException::class);
        $this->createClassMetadata(UserIncorrectUniqueConstraint::class);
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testEntityOptions(ClassMetadata $class): ClassMetadata
    {
        self::assertArrayHasKey('options', $class->table, 'ClassMetadata should have options key in table property.');

        self::assertEquals(
            [
                'foo' => 'bar',
                'baz' => ['key' => 'val'],
            ],
            $class->table['options'],
        );

        return $class;
    }

    #[Depends('testEntityOptions')]
    public function testEntitySequence(ClassMetadata $class): void
    {
        self::assertIsArray($class->sequenceGeneratorDefinition, 'No Sequence Definition set on this driver.');
        self::assertEquals(
            [
                'sequenceName' => 'tablename_seq',
                'allocationSize' => 100,
                'initialValue' => 1,
            ],
            $class->sequenceGeneratorDefinition,
        );
    }

    public function testEntityCustomGenerator(): void
    {
        $class = $this->createClassMetadata(Animal::class);

        self::assertEquals(
            ClassMetadata::GENERATOR_TYPE_CUSTOM,
            $class->generatorType,
            'Generator Type',
        );
        self::assertEquals(
            ['class' => stdClass::class],
            $class->customGeneratorDefinition,
            'Custom Generator Definition',
        );
    }

    #[TestWith([CustomGeneratorWithoutStrategy::class, ClassMetadata::GENERATOR_TYPE_CUSTOM])]
    #[TestWith([CustomGeneratorWithAssignedStrategy::class, ClassMetadata::GENERATOR_TYPE_NONE])]
    #[TestWith([CustomGeneratorWithAutomaticStrategy::class, ClassMetadata::GENERATOR_TYPE_AUTO])]
    public function testCustomGeneratorStrategy(string $entityClass, int $expectedStrategy): void
    {
        $class = $this->createClassMetadata($entityClass);

        self::assertSame($expectedStrategy, $class->generatorType);
        self::assertSame(['class' => stdClass::class], $class->customGeneratorDefinition);
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testFieldMappings(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals(5, count($class->fieldMappings));
        self::assertTrue(isset($class->fieldMappings['id']));
        self::assertTrue(isset($class->fieldMappings['name']));
        self::assertTrue(isset($class->fieldMappings['email']));
        self::assertTrue(isset($class->fieldMappings['version']));
        self::assertTrue(isset($class->fieldMappings['indexed']));

        return $class;
    }

    #[Depends('testFieldMappings')]
    public function testVersionedField(ClassMetadata $class): void
    {
        self::assertTrue($class->isVersioned);
        self::assertEquals('version', $class->versionField);

        self::assertFalse(isset($class->fieldMappings['version']->version));
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testFieldMappingsColumnNames(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals('id', $class->fieldMappings['id']->columnName);
        self::assertEquals('name', $class->fieldMappings['name']->columnName);
        self::assertEquals('user_email', $class->fieldMappings['email']->columnName);

        return $class;
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testStringFieldMappings(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals('string', $class->fieldMappings['name']->type);
        self::assertEquals(50, $class->fieldMappings['name']->length);
        self::assertTrue($class->fieldMappings['name']->nullable);
        self::assertTrue($class->fieldMappings['name']->unique);
        self::assertTrue($class->fieldMappings['indexed']->index);

        return $class;
    }

    public function testFieldTypeFromReflection(): void
    {
        $class = $this->createClassMetadata(UserTyped::class);

        self::assertEquals('integer', $class->getTypeOfField('id'));
        self::assertEquals('string', $class->getTypeOfField('username'));
        self::assertEquals('dateinterval', $class->getTypeOfField('dateInterval'));
        self::assertEquals('datetime', $class->getTypeOfField('dateTime'));
        self::assertEquals('datetime_immutable', $class->getTypeOfField('dateTimeImmutable'));
        self::assertEquals('json', $class->getTypeOfField('array'));
        self::assertEquals('boolean', $class->getTypeOfField('boolean'));
        self::assertEquals('float', $class->getTypeOfField('float'));

        self::assertEquals(CmsEmail::class, $class->getAssociationMapping('email')->targetEntity);
        self::assertEquals(CmsEmail::class, $class->getAssociationMapping('mainEmail')->targetEntity);
        self::assertEquals(Contact::class, $class->embeddedClasses['contact']->class);
    }

    #[\PHPUnit\Framework\Attributes\Group('GH10313')]
    public function testCustomFieldTypeFromReflection(): void
    {
        $class = $this->createClassMetadata(
            UserTypedWithCustomTypedField::class,
            null,
            new DefaultTypedFieldMapper(
                [
                    CustomIdObject::class => CustomIdObjectType::class,
                    'int' => CustomIntType::class,
                ],
            ),
        );

        self::assertEquals(CustomIdObjectType::class, $class->getTypeOfField('customId'));
        self::assertEquals(CustomIntType::class, $class->getTypeOfField('customIntTypedField'));
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testFieldOptions(ClassMetadata $class): ClassMetadata
    {
        $expected = ['foo' => 'bar', 'baz' => ['key' => 'val'], 'fixed' => false];
        self::assertEquals($expected, $class->fieldMappings['name']->options);

        return $class;
    }

    #[Depends('testEntityTableNameAndInheritance')]
    public function testIdFieldOptions(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals(['foo' => 'bar', 'unsigned' => false], $class->fieldMappings['id']->options);

        return $class;
    }

    #[Depends('testFieldMappings')]
    public function testIdentifier(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals(['id'], $class->identifier);
        self::assertEquals('integer', $class->fieldMappings['id']->type);
        self::assertEquals(ClassMetadata::GENERATOR_TYPE_AUTO, $class->generatorType, 'ID-Generator is not ClassMetadata::GENERATOR_TYPE_AUTO');

        return $class;
    }

    #[\PHPUnit\Framework\Attributes\Group('#6129')]
    public function testBooleanValuesForOptionIsSetCorrectly(): ClassMetadata
    {
        $class = $this->createClassMetadata(User::class);

        self::assertIsBool($class->fieldMappings['id']->options['unsigned']);
        self::assertFalse($class->fieldMappings['id']->options['unsigned']);

        self::assertIsBool($class->fieldMappings['name']->options['fixed']);
        self::assertFalse($class->fieldMappings['name']->options['fixed']);

        return $class;
    }

    #[Depends('testIdentifier')]
    public function testAssociations(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals(3, count($class->associationMappings));

        return $class;
    }

    #[Depends('testAssociations')]
    public function testOwningOneToOneAssociation(ClassMetadata $class): ClassMetadata
    {
        self::assertTrue(isset($class->associationMappings['address']));
        self::assertTrue($class->associationMappings['address']->isOwningSide());
        self::assertEquals('user', $class->associationMappings['address']->inversedBy);
        // Check cascading
        self::assertTrue($class->associationMappings['address']->isCascadeRemove());
        self::assertFalse($class->associationMappings['address']->isCascadePersist());
        self::assertFalse($class->associationMappings['address']->isCascadeRefresh());
        self::assertFalse($class->associationMappings['address']->isCascadeDetach());

        return $class;
    }

    #[Depends('testOwningOneToOneAssociation')]
    public function testInverseOneToManyAssociation(ClassMetadata $class): ClassMetadata
    {
        self::assertTrue(isset($class->associationMappings['phonenumbers']));
        self::assertFalse($class->associationMappings['phonenumbers']->isOwningSide());
        self::assertTrue($class->associationMappings['phonenumbers']->isCascadePersist());
        self::assertTrue($class->associationMappings['phonenumbers']->isCascadeRemove());
        self::assertFalse($class->associationMappings['phonenumbers']->isCascadeRefresh());
        self::assertFalse($class->associationMappings['phonenumbers']->isCascadeDetach());
        self::assertTrue($class->associationMappings['phonenumbers']->orphanRemoval);

        // Test Order By
        self::assertEquals(['number' => SortDirection::Ascending], $class->associationMappings['phonenumbers']->orderBy);

        return $class;
    }

    #[IgnoreDeprecations]
    public function testOrderByWithStringValues(): void
    {
        if ($this->loadDriver() instanceof XmlDriver) {
            $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/11313');
        } else {
            // there are 3 associations using the string order value
            $this->doctrineDeprecationsExpectations['https://github.com/doctrine/orm/issues/11313'] = 3;
        }

        $class = $this->createClassMetadata(UserWithStringOrderBy::class);

        self::assertTrue(isset($class->associationMappings['phonenumbersAsc']));
        self::assertEquals(
            ['number' => SortDirection::Ascending],
            $class->associationMappings['phonenumbersAsc']->orderBy,
        );

        self::assertTrue(isset($class->associationMappings['phonenumbersDesc']));
        self::assertEquals(
            ['number' => SortDirection::Descending],
            $class->associationMappings['phonenumbersDesc']->orderBy,
        );

        self::assertTrue(isset($class->associationMappings['groupsMixed']));
        self::assertEquals(
            ['name' => SortDirection::Ascending, 'id' => SortDirection::Descending],
            $class->associationMappings['groupsMixed']->orderBy,
        );
    }

    #[IgnoreDeprecations]
    public function testOrderByWithInvalidDirection(): void
    {
        $driver = $this->loadDriver();

        $this->expectException(MappingException::class);

        // XML driver validates against XSD first, so error message is different but still contains "INVALID"
        $this->expectExceptionMessageMatches('/INVALID/');

        // This should trigger the exception during metadata creation
        $this->createClassMetadata(UserWithInvalidOrderBy::class);
    }

    #[Depends('testInverseOneToManyAssociation')]
    public function testManyToManyAssociationWithCascadeAll(ClassMetadata $class): ClassMetadata
    {
        self::assertTrue(isset($class->associationMappings['groups']));
        self::assertTrue($class->associationMappings['groups']->isOwningSide());
        // Make sure that cascade-all works as expected
        self::assertTrue($class->associationMappings['groups']->isCascadeRemove());
        self::assertTrue($class->associationMappings['groups']->isCascadePersist());
        self::assertTrue($class->associationMappings['groups']->isCascadeRefresh());
        self::assertTrue($class->associationMappings['groups']->isCascadeDetach());

        self::assertFalse($class->associationMappings['groups']->isOrdered());

        return $class;
    }

    #[Depends('testManyToManyAssociationWithCascadeAll')]
    public function testLifecycleCallbacks(ClassMetadata $class): ClassMetadata
    {
        self::assertCount(2, $class->lifecycleCallbacks);
        self::assertEquals($class->lifecycleCallbacks['prePersist'][0], 'doStuffOnPrePersist');
        self::assertEquals($class->lifecycleCallbacks['postPersist'][0], 'doStuffOnPostPersist');

        return $class;
    }

    #[Depends('testManyToManyAssociationWithCascadeAll')]
    public function testLifecycleCallbacksSupportMultipleMethodNames(ClassMetadata $class): ClassMetadata
    {
        self::assertCount(2, $class->lifecycleCallbacks['prePersist']);
        self::assertEquals($class->lifecycleCallbacks['prePersist'][1], 'doOtherStuffOnPrePersistToo');

        return $class;
    }

    #[Depends('testLifecycleCallbacksSupportMultipleMethodNames')]
    public function testJoinColumnUniqueAndNullable(ClassMetadata $class): ClassMetadata
    {
        // Non-Nullability of Join Column
        self::assertFalse($class->associationMappings['groups']->joinTable->joinColumns[0]->nullable);
        self::assertFalse($class->associationMappings['groups']->joinTable->joinColumns[0]->unique);

        return $class;
    }

    #[Depends('testJoinColumnUniqueAndNullable')]
    public function testColumnDefinition(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals('CHAR(32) NOT NULL', $class->fieldMappings['email']->columnDefinition);
        self::assertEquals('INT NULL', $class->associationMappings['groups']->joinTable->inverseJoinColumns[0]->columnDefinition);

        return $class;
    }

    #[Depends('testColumnDefinition')]
    public function testJoinColumnOnDelete(ClassMetadata $class): ClassMetadata
    {
        self::assertEquals('CASCADE', $class->associationMappings['address']->joinColumns[0]->onDelete);

        return $class;
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-514')]
    public function testDiscriminatorColumnDefaults(): void
    {
        if (str_contains(static::class, 'PHPMappingDriver')) {
            self::markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(Animal::class);

        self::assertEquals(
            DiscriminatorColumnMapping::fromMappingArray([
                'name' => 'discr',
                'type' => 'string',
                'length' => 32,
                'fieldName' => 'discr',
                'columnDefinition' => null,
                'enumType' => null,
            ]),
            $class->discriminatorColumn,
        );
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-869')]
    public function testMappedSuperclassWithRepository(): void
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);

        $class = $factory->getMetadataFor(DDC869CreditCardPayment::class);

        self::assertTrue(isset($class->fieldMappings['id']));
        self::assertTrue(isset($class->fieldMappings['value']));
        self::assertTrue(isset($class->fieldMappings['creditCardNumber']));
        self::assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);
        self::assertInstanceOf(DDC869PaymentRepository::class, $em->getRepository(DDC869CreditCardPayment::class));
        self::assertTrue($em->getRepository(DDC869ChequePayment::class)->isTrue());

        $class = $factory->getMetadataFor(DDC869ChequePayment::class);

        self::assertTrue(isset($class->fieldMappings['id']));
        self::assertTrue(isset($class->fieldMappings['value']));
        self::assertTrue(isset($class->fieldMappings['serialNumber']));
        self::assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);
        self::assertInstanceOf(DDC869PaymentRepository::class, $em->getRepository(DDC869ChequePayment::class));
        self::assertTrue($em->getRepository(DDC869ChequePayment::class)->isTrue());
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-1476')]
    public function testDefaultFieldType(): void
    {
        $factory = $this->createClassMetadataFactory();
        $class   = $factory->getMetadataFor(DDC1476EntityWithDefaultFieldType::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('name', $class->fieldMappings);

        self::assertEquals('string', $class->fieldMappings['id']->type);
        self::assertEquals('string', $class->fieldMappings['name']->type);

        self::assertEquals('id', $class->fieldMappings['id']->fieldName);
        self::assertEquals('name', $class->fieldMappings['name']->fieldName);

        self::assertEquals('id', $class->fieldMappings['id']->columnName);
        self::assertEquals('name', $class->fieldMappings['name']->columnName);

        self::assertEquals(ClassMetadata::GENERATOR_TYPE_NONE, $class->generatorType);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-1170')]
    public function testIdentifierColumnDefinition(): void
    {
        $class = $this->createClassMetadata(DDC1170Entity::class);

        self::assertArrayHasKey('id', $class->fieldMappings);
        self::assertArrayHasKey('value', $class->fieldMappings);

        self::assertEquals('int unsigned not null', strtolower($class->fieldMappings['id']->columnDefinition));
        self::assertEquals('varchar(255) not null', strtolower($class->fieldMappings['value']->columnDefinition));
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-559')]
    public function testNamingStrategy(): void
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);

        self::assertInstanceOf(DefaultNamingStrategy::class, $em->getConfiguration()->getNamingStrategy());
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy(CASE_UPPER));
        self::assertInstanceOf(UnderscoreNamingStrategy::class, $em->getConfiguration()->getNamingStrategy());

        $class = $factory->getMetadataFor(DDC1476EntityWithDefaultFieldType::class);

        self::assertEquals('ID', $class->getColumnName('id'));
        self::assertEquals('NAME', $class->getColumnName('name'));
        self::assertEquals('DDC1476_ENTITY_WITH_DEFAULT_FIELD_TYPE', $class->table['name']);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-807')]
    #[\PHPUnit\Framework\Attributes\Group('DDC-553')]
    public function testDiscriminatorColumnDefinition(): void
    {
        $class = $this->createClassMetadata(DDC807Entity::class);

        self::assertEquals("ENUM('ONE','TWO')", $class->discriminatorColumn->columnDefinition);
        self::assertEquals('dtype', $class->discriminatorColumn->name);
    }

    #[\PHPUnit\Framework\Attributes\Group('GH10288')]
    public function testDiscriminatorColumnEnumTypeDefinition(): void
    {
        $class = $this->createClassMetadata(GH10288EnumTypePerson::class);

        self::assertEquals(GH10288People::class, $class->discriminatorColumn->enumType);
        self::assertEquals('discr', $class->discriminatorColumn->name);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-889')]
    public function testInvalidEntityOrMappedSuperClassShouldMentionParentClasses(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Class "Doctrine\Tests\Models\DDC889\DDC889Class" sub class of "Doctrine\Tests\Models\DDC889\DDC889SuperClass" is not a valid entity or mapped super class.');

        $this->createClassMetadata(DDC889Class::class);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-889')]
    public function testIdentifierRequiredShouldMentionParentClasses(): void
    {
        $factory = $this->createClassMetadataFactory();

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('No identifier/primary key specified for Entity "Doctrine\Tests\Models\DDC889\DDC889Entity" sub class of "Doctrine\Tests\Models\DDC889\DDC889SuperClass". Every Entity must have an identifier/primary key.');

        $factory->getMetadataFor(DDC889Entity::class);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-3579')]
    public function testInversedByOverrideMapping(): void
    {
        $factory       = $this->createClassMetadataFactory();
        $adminMetadata = $factory->getMetadataFor(DDC3579Admin::class);

        // assert groups association mappings
        self::assertArrayHasKey('groups', $adminMetadata->associationMappings);
        $adminGroups = $adminMetadata->associationMappings['groups'];

        // assert override
        self::assertEquals('admins', $adminGroups->inversedBy);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-5934')]
    public function testFetchOverrideMapping(): void
    {
        // check override metadata
        $contractMetadata = $this->createClassMetadataFactory()->getMetadataFor(DDC5934Contract::class);

        self::assertArrayHasKey('members', $contractMetadata->associationMappings);
        self::assertSame(ClassMetadata::FETCH_EXTRA_LAZY, $contractMetadata->associationMappings['members']->fetch);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-964')]
    public function testAssociationOverridesMapping(): void
    {
        $factory       = $this->createClassMetadataFactory();
        $adminMetadata = $factory->getMetadataFor(DDC964Admin::class);
        $guestMetadata = $factory->getMetadataFor(DDC964Guest::class);

        // assert groups association mappings
        self::assertArrayHasKey('groups', $guestMetadata->associationMappings);
        self::assertArrayHasKey('groups', $adminMetadata->associationMappings);

        $guestGroups = $guestMetadata->associationMappings['groups'];
        $adminGroups = $adminMetadata->associationMappings['groups'];

        // assert not override attributes
        self::assertEquals($guestGroups->fieldName, $adminGroups->fieldName);
        self::assertEquals($guestGroups->type(), $adminGroups->type());
        self::assertEquals($guestGroups->inversedBy, $adminGroups->inversedBy);
        self::assertEquals($guestGroups->isOwningSide(), $adminGroups->isOwningSide());
        self::assertEquals($guestGroups->fetch, $adminGroups->fetch);
        self::assertEquals($guestGroups->isCascadeRemove(), $adminGroups->isCascadeRemove());
        self::assertEquals($guestGroups->isCascadePersist(), $adminGroups->isCascadePersist());
        self::assertEquals($guestGroups->isCascadeRefresh(), $adminGroups->isCascadeRefresh());
        self::assertEquals($guestGroups->isCascadeDetach(), $adminGroups->isCascadeDetach());

        // assert not override attributes
        self::assertEquals('ddc964_users_groups', $guestGroups->joinTable->name);
        self::assertEquals('user_id', $guestGroups->joinTable->joinColumns[0]->name);
        self::assertEquals('group_id', $guestGroups->joinTable->inverseJoinColumns[0]->name);

        self::assertEquals(['user_id' => 'id'], $guestGroups->relationToSourceKeyColumns);
        self::assertEquals(['group_id' => 'id'], $guestGroups->relationToTargetKeyColumns);
        self::assertEquals(['user_id', 'group_id'], $guestGroups->joinTableColumns);

        self::assertEquals('ddc964_users_admingroups', $adminGroups->joinTable->name);
        self::assertEquals('adminuser_id', $adminGroups->joinTable->joinColumns[0]->name);
        self::assertEquals('admingroup_id', $adminGroups->joinTable->inverseJoinColumns[0]->name);

        self::assertEquals(['adminuser_id' => 'id'], $adminGroups->relationToSourceKeyColumns);
        self::assertEquals(['admingroup_id' => 'id'], $adminGroups->relationToTargetKeyColumns);
        self::assertEquals(['adminuser_id', 'admingroup_id'], $adminGroups->joinTableColumns);

        // assert address association mappings
        self::assertArrayHasKey('address', $guestMetadata->associationMappings);
        self::assertArrayHasKey('address', $adminMetadata->associationMappings);

        $guestAddress = $guestMetadata->associationMappings['address'];
        $adminAddress = $adminMetadata->associationMappings['address'];

        // assert not override attributes
        self::assertEquals($guestAddress->fieldName, $adminAddress->fieldName);
        self::assertEquals($guestAddress->type(), $adminAddress->type());
        self::assertEquals($guestAddress->inversedBy, $adminAddress->inversedBy);
        self::assertEquals($guestAddress->isOwningSide(), $adminAddress->isOwningSide());
        self::assertEquals($guestAddress->fetch, $adminAddress->fetch);
        self::assertEquals($guestAddress->isCascadeRemove(), $adminAddress->isCascadeRemove());
        self::assertEquals($guestAddress->isCascadePersist(), $adminAddress->isCascadePersist());
        self::assertEquals($guestAddress->isCascadeRefresh(), $adminAddress->isCascadeRefresh());
        self::assertEquals($guestAddress->isCascadeDetach(), $adminAddress->isCascadeDetach());

        // assert override
        self::assertEquals('address_id', $guestAddress->joinColumns[0]->name);
        self::assertEquals(['address_id' => 'id'], $guestAddress->sourceToTargetKeyColumns);
        self::assertEquals(['address_id' => 'address_id'], $guestAddress->joinColumnFieldNames);
        self::assertEquals(['id' => 'address_id'], $guestAddress->targetToSourceKeyColumns);

        self::assertEquals('adminaddress_id', $adminAddress->joinColumns[0]->name);
        self::assertEquals(['adminaddress_id' => 'id'], $adminAddress->sourceToTargetKeyColumns);
        self::assertEquals(['adminaddress_id' => 'adminaddress_id'], $adminAddress->joinColumnFieldNames);
        self::assertEquals(['id' => 'adminaddress_id'], $adminAddress->targetToSourceKeyColumns);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-964')]
    public function testAttributeOverridesMapping(): void
    {
        $factory       = $this->createClassMetadataFactory();
        $guestMetadata = $factory->getMetadataFor(DDC964Guest::class);
        $adminMetadata = $factory->getMetadataFor(DDC964Admin::class);

        self::assertTrue($adminMetadata->fieldMappings['id']->id);
        self::assertEquals('id', $adminMetadata->fieldMappings['id']->fieldName);
        self::assertEquals('user_id', $adminMetadata->fieldMappings['id']->columnName);
        self::assertEquals(['user_id' => 'id', 'user_name' => 'name'], $adminMetadata->fieldNames);
        self::assertEquals(['id' => 'user_id', 'name' => 'user_name'], $adminMetadata->columnNames);
        self::assertEquals(150, $adminMetadata->fieldMappings['id']->length);

        self::assertEquals('name', $adminMetadata->fieldMappings['name']->fieldName);
        self::assertEquals('user_name', $adminMetadata->fieldMappings['name']->columnName);
        self::assertEquals(250, $adminMetadata->fieldMappings['name']->length);
        self::assertTrue($adminMetadata->fieldMappings['name']->nullable);
        self::assertFalse($adminMetadata->fieldMappings['name']->unique);

        self::assertTrue($guestMetadata->fieldMappings['id']->id);
        self::assertEquals('guest_id', $guestMetadata->fieldMappings['id']->columnName);
        self::assertEquals('id', $guestMetadata->fieldMappings['id']->fieldName);
        self::assertEquals(['guest_id' => 'id', 'guest_name' => 'name'], $guestMetadata->fieldNames);
        self::assertEquals(['id' => 'guest_id', 'name' => 'guest_name'], $guestMetadata->columnNames);
        self::assertEquals(140, $guestMetadata->fieldMappings['id']->length);

        self::assertEquals('name', $guestMetadata->fieldMappings['name']->fieldName);
        self::assertEquals('guest_name', $guestMetadata->fieldMappings['name']->columnName);
        self::assertEquals(240, $guestMetadata->fieldMappings['name']->length);
        self::assertFalse($guestMetadata->fieldMappings['name']->nullable);
        self::assertTrue($guestMetadata->fieldMappings['name']->unique);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-1955')]
    public function testEntityListeners(): void
    {
        $em         = $this->getTestEntityManager();
        $factory    = $this->createClassMetadataFactory($em);
        $superClass = $factory->getMetadataFor(CompanyContract::class);
        $flexClass  = $factory->getMetadataFor(CompanyFixContract::class);
        $fixClass   = $factory->getMetadataFor(CompanyFlexContract::class);
        $ultraClass = $factory->getMetadataFor(CompanyFlexUltraContract::class);

        self::assertArrayHasKey(Events::prePersist, $superClass->entityListeners);
        self::assertArrayHasKey(Events::postPersist, $superClass->entityListeners);

        self::assertCount(1, $superClass->entityListeners[Events::prePersist]);
        self::assertCount(1, $superClass->entityListeners[Events::postPersist]);

        $postPersist = $superClass->entityListeners[Events::postPersist][0];
        $prePersist  = $superClass->entityListeners[Events::prePersist][0];

        self::assertEquals(CompanyContractListener::class, $postPersist['class']);
        self::assertEquals(CompanyContractListener::class, $prePersist['class']);
        self::assertEquals('postPersistHandler', $postPersist['method']);
        self::assertEquals('prePersistHandler', $prePersist['method']);

        //Inherited listeners
        self::assertEquals($fixClass->entityListeners, $superClass->entityListeners);
        self::assertEquals($flexClass->entityListeners, $superClass->entityListeners);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-1955')]
    public function testEntityListenersOverride(): void
    {
        $em         = $this->getTestEntityManager();
        $factory    = $this->createClassMetadataFactory($em);
        $ultraClass = $factory->getMetadataFor(CompanyFlexUltraContract::class);

        //overridden listeners
        self::assertArrayHasKey(Events::postPersist, $ultraClass->entityListeners);
        self::assertArrayHasKey(Events::prePersist, $ultraClass->entityListeners);

        self::assertCount(1, $ultraClass->entityListeners[Events::postPersist]);
        self::assertCount(3, $ultraClass->entityListeners[Events::prePersist]);

        $postPersist = $ultraClass->entityListeners[Events::postPersist][0];
        $prePersist  = $ultraClass->entityListeners[Events::prePersist][0];

        self::assertEquals(CompanyContractListener::class, $postPersist['class']);
        self::assertEquals(CompanyContractListener::class, $prePersist['class']);
        self::assertEquals('postPersistHandler', $postPersist['method']);
        self::assertEquals('prePersistHandler', $prePersist['method']);

        $prePersist = $ultraClass->entityListeners[Events::prePersist][1];
        self::assertEquals(CompanyFlexUltraContractListener::class, $prePersist['class']);
        self::assertEquals('prePersistHandler1', $prePersist['method']);

        $prePersist = $ultraClass->entityListeners[Events::prePersist][2];
        self::assertEquals(CompanyFlexUltraContractListener::class, $prePersist['class']);
        self::assertEquals('prePersistHandler2', $prePersist['method']);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-1955')]
    public function testEntityListenersNamingConvention(): void
    {
        $em       = $this->getTestEntityManager();
        $factory  = $this->createClassMetadataFactory($em);
        $metadata = $factory->getMetadataFor(CmsAddress::class);

        self::assertArrayHasKey(Events::postPersist, $metadata->entityListeners);
        self::assertArrayHasKey(Events::prePersist, $metadata->entityListeners);
        self::assertArrayHasKey(Events::postUpdate, $metadata->entityListeners);
        self::assertArrayHasKey(Events::preUpdate, $metadata->entityListeners);
        self::assertArrayHasKey(Events::postRemove, $metadata->entityListeners);
        self::assertArrayHasKey(Events::preRemove, $metadata->entityListeners);
        self::assertArrayHasKey(Events::postLoad, $metadata->entityListeners);
        self::assertArrayHasKey(Events::preFlush, $metadata->entityListeners);

        self::assertCount(1, $metadata->entityListeners[Events::postPersist]);
        self::assertCount(1, $metadata->entityListeners[Events::prePersist]);
        self::assertCount(1, $metadata->entityListeners[Events::postUpdate]);
        self::assertCount(1, $metadata->entityListeners[Events::preUpdate]);
        self::assertCount(1, $metadata->entityListeners[Events::postRemove]);
        self::assertCount(1, $metadata->entityListeners[Events::preRemove]);
        self::assertCount(1, $metadata->entityListeners[Events::postLoad]);
        self::assertCount(1, $metadata->entityListeners[Events::preFlush]);

        $postPersist = $metadata->entityListeners[Events::postPersist][0];
        $prePersist  = $metadata->entityListeners[Events::prePersist][0];
        $postUpdate  = $metadata->entityListeners[Events::postUpdate][0];
        $preUpdate   = $metadata->entityListeners[Events::preUpdate][0];
        $postRemove  = $metadata->entityListeners[Events::postRemove][0];
        $preRemove   = $metadata->entityListeners[Events::preRemove][0];
        $postLoad    = $metadata->entityListeners[Events::postLoad][0];
        $preFlush    = $metadata->entityListeners[Events::preFlush][0];

        self::assertEquals(CmsAddressListener::class, $postPersist['class']);
        self::assertEquals(CmsAddressListener::class, $prePersist['class']);
        self::assertEquals(CmsAddressListener::class, $postUpdate['class']);
        self::assertEquals(CmsAddressListener::class, $preUpdate['class']);
        self::assertEquals(CmsAddressListener::class, $postRemove['class']);
        self::assertEquals(CmsAddressListener::class, $preRemove['class']);
        self::assertEquals(CmsAddressListener::class, $postLoad['class']);
        self::assertEquals(CmsAddressListener::class, $preFlush['class']);

        self::assertEquals(Events::postPersist, $postPersist['method']);
        self::assertEquals(Events::prePersist, $prePersist['method']);
        self::assertEquals(Events::postUpdate, $postUpdate['method']);
        self::assertEquals(Events::preUpdate, $preUpdate['method']);
        self::assertEquals(Events::postRemove, $postRemove['method']);
        self::assertEquals(Events::preRemove, $preRemove['method']);
        self::assertEquals(Events::postLoad, $postLoad['method']);
        self::assertEquals(Events::preFlush, $preFlush['method']);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-2183')]
    public function testSecondLevelCacheMapping(): void
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);
        $class   = $factory->getMetadataFor(City::class);
        self::assertArrayHasKey('usage', $class->cache);
        self::assertArrayHasKey('region', $class->cache);
        self::assertEquals(ClassMetadata::CACHE_USAGE_READ_ONLY, $class->cache['usage']);
        self::assertEquals('doctrine_tests_models_cache_city', $class->cache['region']);

        self::assertArrayHasKey('state', $class->associationMappings);
        self::assertNotNull($class->associationMappings['state']->cache);
        self::assertArrayHasKey('usage', $class->associationMappings['state']->cache);
        self::assertArrayHasKey('region', $class->associationMappings['state']->cache);
        self::assertEquals(ClassMetadata::CACHE_USAGE_READ_ONLY, $class->associationMappings['state']->cache['usage']);
        self::assertEquals('doctrine_tests_models_cache_city__state', $class->associationMappings['state']->cache['region']);

        self::assertArrayHasKey('attractions', $class->associationMappings);
        self::assertNotNull($class->associationMappings['attractions']->cache);
        self::assertArrayHasKey('usage', $class->associationMappings['attractions']->cache);
        self::assertArrayHasKey('region', $class->associationMappings['attractions']->cache);
        self::assertEquals(ClassMetadata::CACHE_USAGE_READ_ONLY, $class->associationMappings['attractions']->cache['usage']);
        self::assertEquals('doctrine_tests_models_cache_city__attractions', $class->associationMappings['attractions']->cache['region']);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-2825')]
    #[\PHPUnit\Framework\Attributes\Group('881')]
    public function testSchemaDefinitionViaExplicitTableSchemaAttributeProperty(): void
    {
        $metadata = $this->createClassMetadataFactory()->getMetadataFor(ExplicitSchemaAndTable::class);
        assert($metadata instanceof ClassMetadata);

        self::assertSame('explicit_schema', $metadata->getSchemaName());
        self::assertSame('explicit_table', $metadata->getTableName());
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-2825')]
    #[\PHPUnit\Framework\Attributes\Group('881')]
    public function testSchemaDefinitionViaSchemaDefinedInTableNameInTableAttributeProperty(): void
    {
        $metadata = $this->createClassMetadataFactory()->getMetadataFor(SchemaAndTableInTableName::class);
        assert($metadata instanceof ClassMetadata);

        self::assertSame('implicit_schema', $metadata->getSchemaName());
        self::assertSame('implicit_table', $metadata->getTableName());
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-514')]
    #[\PHPUnit\Framework\Attributes\Group('DDC-1015')]
    public function testDiscriminatorColumnDefaultLength(): void
    {
        if (str_contains(static::class, 'PHPMappingDriver')) {
            self::markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);
        self::assertEquals(255, $class->discriminatorColumn->length);
        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);
        self::assertEquals(255, $class->discriminatorColumn->length);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-514')]
    #[\PHPUnit\Framework\Attributes\Group('DDC-1015')]
    public function testDiscriminatorColumnDefaultType(): void
    {
        if (str_contains(static::class, 'PHPMappingDriver')) {
            self::markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);
        self::assertEquals('string', $class->discriminatorColumn->type);
        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);
        self::assertEquals('string', $class->discriminatorColumn->type);
    }

    #[\PHPUnit\Framework\Attributes\Group('DDC-514')]
    #[\PHPUnit\Framework\Attributes\Group('DDC-1015')]
    public function testDiscriminatorColumnDefaultName(): void
    {
        if (str_contains(static::class, 'PHPMappingDriver')) {
            self::markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);
        self::assertEquals('dtype', $class->discriminatorColumn->name);
        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);
        self::assertEquals('dtype', $class->discriminatorColumn->name);
    }

    public function testReservedWordInTableColumn(): void
    {
        $metadata = $this->createClassMetadata(ReservedWordInTableColumn::class);

        self::assertSame('count', $metadata->getFieldMapping('count')->columnName);
    }

    public function testInsertableColumn(): void
    {
        $metadata = $this->createClassMetadata(Insertable::class);

        $mapping = $metadata->getFieldMapping('nonInsertableContent');

        self::assertSame(ClassMetadata::GENERATED_INSERT, $mapping->generated);
        self::assertNull($metadata->getFieldMapping('insertableContent')->notInsertable);
    }

    public function testUpdatableColumn(): void
    {
        $metadata = $this->createClassMetadata(Updatable::class);

        $mapping = $metadata->getFieldMapping('nonUpdatableContent');

        self::assertSame(ClassMetadata::GENERATED_ALWAYS, $mapping->generated);
        self::assertNull($metadata->getFieldMapping('updatableContent')->notUpdatable);
    }

    public function testEnumType(): void
    {
        $metadata = $this->createClassMetadata(Card::class);

        self::assertEquals(Suit::class, $metadata->fieldMappings['suit']->enumType);
    }

    public function testCustomNamingStrategyIsRespected(): void
    {
        $ns       = new CustomPascalNamingStrategy();
        $metadata = $this->createClassMetadata(BlogPostComment::class, $ns);

        self::assertEquals('id', $metadata->fieldNames['Id']);
        self::assertEquals('Id', $metadata->associationMappings['blogPost']->joinColumns[0]->referencedColumnName);
        self::assertFalse($metadata->associationMappings['blogPost']->joinColumns[0]->nullable);
    }
}

#[ORM\Entity()]
#[ORM\HasLifecycleCallbacks()]
#[ORM\Table(name: 'cms_users', options: ['foo' => 'bar', 'baz' => ['key' => 'val']])]
#[ORM\Index(name: 'name_idx', columns: ['name'])]
#[ORM\Index(name: '0', columns: ['user_email'])]
#[ORM\Index(name: 'fields', fields: ['name', 'email'])]
#[ORM\UniqueConstraint(name: 'search_idx', columns: ['name', 'user_email'], options: ['where' => 'name IS NOT NULL'])]
#[ORM\UniqueConstraint(name: 'phone_idx', fields: ['name', 'phone'])]
class User
{
    /** @var int **/
    #[ORM\Id]
    #[ORM\Column(type: 'integer', options: ['foo' => 'bar', 'unsigned' => false])]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\SequenceGenerator(sequenceName: 'tablename_seq', initialValue: 1, allocationSize: 100)]
    public $id;

    /** @var string */
    #[ORM\Column(length: 50, nullable: true, unique: true, options: ['foo' => 'bar', 'baz' => ['key' => 'val'], 'fixed' => false])]
    public $name;

    /** @var string */
    #[ORM\Column(name: 'user_email', columnDefinition: 'CHAR(32) NOT NULL')]
    public $email;

    /** @var Address */
    #[ORM\OneToOne(targetEntity: 'Address', cascade: ['remove'], inversedBy: 'user')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public $address;

    /** @var Collection<int, Phonenumber> */
    #[ORM\OneToMany(targetEntity: 'Phonenumber', mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => SortDirection::Ascending])]
    public $phonenumbers;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: 'Group', cascade: ['all'])]
    #[ORM\JoinTable(name: 'cms_user_groups')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', unique: false)]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id', columnDefinition: 'INT NULL')]
    public $groups;

    /** @var int */
    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    public $version;

    /** @var string */
    #[ORM\Column(index: true)]
    public $indexed;

    #[ORM\PrePersist]
    public function doStuffOnPrePersist(): void
    {
    }

    #[ORM\PrePersist]
    public function doOtherStuffOnPrePersistToo(): void
    {
    }

    #[ORM\PostPersist]
    public function doStuffOnPostPersist(): void
    {
    }

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable(
            [
                'name' => 'cms_users',
                'options' => ['foo' => 'bar', 'baz' => ['key' => 'val']],
            ],
        );
        $metadata->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_DEFERRED_IMPLICIT);
        $metadata->addLifecycleCallback('doStuffOnPrePersist', 'prePersist');
        $metadata->addLifecycleCallback('doOtherStuffOnPrePersistToo', 'prePersist');
        $metadata->addLifecycleCallback('doStuffOnPostPersist', 'postPersist');
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
                'type' => 'integer',
                'columnName' => 'id',
                'options' => ['foo' => 'bar', 'unsigned' => false],
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'name',
                'type' => 'string',
                'length' => 50,
                'unique' => true,
                'nullable' => true,
                'columnName' => 'name',
                'options' => ['foo' => 'bar', 'baz' => ['key' => 'val'], 'fixed' => false],
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'email',
                'type' => 'string',
                'columnName' => 'user_email',
                'columnDefinition' => 'CHAR(32) NOT NULL',
            ],
        );
        $mapping = ['fieldName' => 'version', 'type' => 'integer'];
        $metadata->setVersionMapping($mapping);
        $metadata->mapField($mapping);
        $metadata->mapField([
            'fieldName' => 'indexed',
            'type' => 'string',
            'columnName' => 'indexed',
            'index' => true,
        ]);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);
        $metadata->mapOneToOne(
            [
                'fieldName' => 'address',
                'targetEntity' => Address::class,
                'cascade' =>
                [0 => 'remove'],
                'mappedBy' => null,
                'inversedBy' => 'user',
                'joinColumns' =>
                [
                    0 =>
                    [
                        'name' => 'address_id',
                        'referencedColumnName' => 'id',
                        'onDelete' => 'CASCADE',
                    ],
                ],
                'orphanRemoval' => false,
            ],
        );
        $metadata->mapOneToMany(
            [
                'fieldName' => 'phonenumbers',
                'targetEntity' => Phonenumber::class,
                'cascade' =>
                [1 => 'persist'],
                'mappedBy' => 'user',
                'orphanRemoval' => true,
                'orderBy' =>
                ['number' => SortDirection::Ascending],
            ],
        );
        $metadata->mapManyToMany(
            [
                'fieldName' => 'groups',
                'targetEntity' => Group::class,
                'cascade' =>
                [
                    0 => 'remove',
                    1 => 'persist',
                    2 => 'refresh',
                    3 => 'detach',
                ],
                'mappedBy' => null,
                'joinTable' =>
                [
                    'name' => 'cms_users_groups',
                    'joinColumns' =>
                    [
                        0 =>
                        [
                            'name' => 'user_id',
                            'referencedColumnName' => 'id',
                            'unique' => false,
                        ],
                    ],
                    'inverseJoinColumns' =>
                    [
                        0 =>
                        [
                            'name' => 'group_id',
                            'referencedColumnName' => 'id',
                            'columnDefinition' => 'INT NULL',
                        ],
                    ],
                ],
                'orderBy' => [],
            ],
        );
        $metadata->table['uniqueConstraints'] = [
            'search_idx' => ['columns' => ['name', 'user_email'], 'options' => ['where' => 'name IS NOT NULL']],
            'phone_idx' => ['fields' => ['name', 'phone']],
        ];
        $metadata->table['indexes']           = [
            'name_idx' => ['columns' => ['name']],
            0 => ['columns' => ['user_email']],
            'fields' => ['fields' => ['name', 'email']],
        ];
        $metadata->setSequenceGeneratorDefinition(
            [
                'sequenceName' => 'tablename_seq',
                'allocationSize' => 100,
                'initialValue' => 1,
            ],
        );
    }
}

#[Table]
#[Index(name: 'name_idx', columns: ['name'], fields: ['email'])]
#[Entity]
class UserIncorrectIndex
{
    /** @var int **/
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;

    /** @var string */
    #[Column]
    public $name;

    /** @var string */
    #[Column(name: 'user_email')]
    public $email;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable([]);
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
                'type' => 'integer',
                'columnName' => 'id',
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'name',
                'type' => 'string',
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'email',
                'type' => 'string',
                'columnName' => 'user_email',
            ],
        );
        $metadata->table['indexes'] = [
            'name_idx' => ['columns' => ['name'], 'fields' => ['email']],
        ];
    }
}

#[Table]
#[UniqueConstraint(name: 'name_idx', columns: ['name'], fields: ['email'])]
#[Entity]
class UserIncorrectUniqueConstraint
{
    /** @var int **/
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public $id;

    /** @var string */
    #[Column]
    public $name;

    /** @var string */
    #[Column(name: 'user_email')]
    public $email;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable([]);
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
                'type' => 'integer',
                'columnName' => 'id',
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'name',
                'type' => 'string',
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'email',
                'type' => 'string',
                'columnName' => 'user_email',
            ],
        );
        $metadata->table['uniqueConstraints'] = [
            'name_idx' => ['columns' => ['name'], 'fields' => ['email']],
        ];
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'discr', length: 32, type: 'string')]
#[ORM\DiscriminatorMap(['cat' => 'Cat', 'dog' => 'Dog'])]
abstract class Animal
{
    /** @var string */
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: stdClass::class)]
    public $id;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_CUSTOM);
        $metadata->setCustomGeneratorDefinition(['class' => stdClass::class]);
    }
}

#[ORM\Entity]
class CustomGeneratorWithoutStrategy
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\CustomIdGenerator(stdClass::class)]
    public int|null $id = null;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        (new ClassMetadataBuilder($metadata))
            ->createField('id', 'integer')
            ->makePrimaryKey()
            ->setCustomIdGenerator(stdClass::class)
            ->build();
    }
}

#[ORM\Entity]
class CustomGeneratorWithAssignedStrategy
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\CustomIdGenerator(stdClass::class)]
    public int|null $id = null;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        (new ClassMetadataBuilder($metadata))
            ->createField('id', 'integer')
            ->makePrimaryKey()
            ->generatedValue('NONE')
            ->setCustomIdGenerator(stdClass::class)
            ->build();
    }
}

#[ORM\Entity]
class CustomGeneratorWithAutomaticStrategy
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    #[ORM\CustomIdGenerator(stdClass::class)]
    public int|null $id = null;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        (new ClassMetadataBuilder($metadata))
            ->createField('id', 'integer')
            ->makePrimaryKey()
            ->generatedValue('AUTO')
            ->setCustomIdGenerator(stdClass::class)
            ->build();
    }
}

#[ORM\Entity]
class Cat extends Animal
{
    public static function loadMetadata(ClassMetadata $metadata): void
    {
    }
}

#[ORM\Entity]
class Dog extends Animal
{
    public static function loadMetadata(ClassMetadata $metadata): void
    {
    }
}

#[ORM\Entity]
class DDC1170Entity
{
    public function __construct(
        #[ORM\Column(columnDefinition: 'VARCHAR(255) NOT NULL')]
        private string|null $value = null,
    ) {
    }

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'integer', columnDefinition: 'INT UNSIGNED NOT NULL')]
    private int $id;

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string|null
    {
        return $this->value;
    }

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
                'columnDefinition'   => 'INT unsigned NOT NULL',
            ],
        );

        $metadata->mapField(
            [
                'fieldName'         => 'value',
                'columnDefinition'  => 'VARCHAR(255) NOT NULL',
            ],
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'dtype', columnDefinition: "ENUM('ONE','TWO')")]
#[ORM\DiscriminatorMap(['ONE' => 'DDC807SubClasse1', 'TWO' => 'DDC807SubClasse2'])]
class DDC807Entity
{
    /** @var int **/
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
         $metadata->mapField(
             [
                 'id'                 => true,
                 'fieldName'          => 'id',
             ],
         );

        $metadata->setDiscriminatorColumn(
            [
                'name'              => 'dtype',
                'type'              => 'string',
                'columnDefinition'  => "ENUM('ONE','TWO')",
            ],
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    }
}

class DDC807SubClasse1
{
}
class DDC807SubClasse2
{
}

class Address
{
}
class Phonenumber
{
}
class Group
{
}

#[ORM\Entity]
#[ORM\Table(name: 'Comment')]
#[ORM\Index(columns: ['content'], flags: ['fulltext'], options: ['where' => 'content IS NOT NULL'])]
class Comment
{
    #[ORM\Column(type: 'text')]
    private string $content;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable(
            [
                'indexes' => [
                    ['columns' => ['content'], 'flags' => ['fulltext'], 'options' => ['where' => 'content IS NOT NULL']],
                ],
            ],
        );

        $metadata->mapField(
            [
                'fieldName' => 'content',
                'type' => 'text',
                'scale' => 0,
                'length' => null,
                'unique' => false,
                'nullable' => false,
                'precision' => 0,
                'columnName' => 'content',
            ],
        );
    }
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorMap(['ONE' => 'SingleTableEntityNoDiscriminatorColumnMappingSub1', 'TWO' => 'SingleTableEntityNoDiscriminatorColumnMappingSub2'])]
class SingleTableEntityNoDiscriminatorColumnMapping
{
    /** @var int */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
            ],
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    }
}

class SingleTableEntityNoDiscriminatorColumnMappingSub1 extends SingleTableEntityNoDiscriminatorColumnMapping
{
}
class SingleTableEntityNoDiscriminatorColumnMappingSub2 extends SingleTableEntityNoDiscriminatorColumnMapping
{
}

#[ORM\Entity]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorMap(['ONE' => 'SingleTableEntityNoDiscriminatorColumnMappingSub1', 'TWO' => 'SingleTableEntityNoDiscriminatorColumnMappingSub2'])]
#[ORM\DiscriminatorColumn(name: 'dtype')]
class SingleTableEntityIncompleteDiscriminatorColumnMapping
{
    /** @var int */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
            ],
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
    }
}

class SingleTableEntityIncompleteDiscriminatorColumnMappingSub1 extends SingleTableEntityIncompleteDiscriminatorColumnMapping
{
}
class SingleTableEntityIncompleteDiscriminatorColumnMappingSub2 extends SingleTableEntityIncompleteDiscriminatorColumnMapping
{
}

#[ORM\Entity]
class ReservedWordInTableColumn
{
    /** @var int */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    /** @var string|null */
    #[ORM\Column(name: '`count`', type: 'integer')]
    public $count;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
                'type' => 'integer',
            ],
        );
        $metadata->mapField(
            [
                'fieldName' => 'count',
                'type' => 'integer',
                'columnName' => '`count`',
            ],
        );
    }
}

class UserIncorrectAttributes extends User
{
}

class UserMissingAttributes extends User
{
}

#[Entity]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'discr', enumType: GH10288People::class)]
#[DiscriminatorMap(['boss' => GH10288EnumTypeBoss::class])]
abstract class GH10288EnumTypePerson
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
            ],
        );

        $metadata->setDiscriminatorColumn(
            [
                'name'     => 'discr',
                'enumType' =>  GH10288People::class,
            ],
        );

        $metadata->setIdGeneratorType(ORM\ClassMetadata::GENERATOR_TYPE_NONE);
    }
}

#[Entity]
class GH10288EnumTypeBoss extends GH10288EnumTypePerson
{
}

/**
 * Two small related entities to test default namings with barebone attributes
 */
#[Entity]
class BlogPost
{
    #[Id]
    #[Column]
    #[GeneratedValue(strategy: 'NONE')]
    public int $id;
}

#[Entity]
class BlogPostComment
{
    #[Id]
    #[Column]
    #[GeneratedValue(strategy: 'AUTO')]
    public int $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public BlogPost $blogPost;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                => true,
                'fieldName'         => 'id',
                'type'              => 'integer',
            ],
        );
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);

        $metadata->mapManyToOne(
            [
                'fieldName' => 'blogPost',
                'targetEntity' => BlogPost::class,
                'joinColumns' =>
                    [
                        0 => ['nullable' => false],
                    ],
            ],
        );
    }
}

/**
 * Entity to test backward compatibility of string-based OrderBy values
 */
#[ORM\Entity]
class UserWithStringOrderBy
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, Phonenumber> */
    #[ORM\OneToMany(targetEntity: Phonenumber::class, mappedBy: 'user')]
    #[ORM\OrderBy(['number' => 'ASC'])]
    public $phonenumbersAsc;

    /** @var Collection<int, Phonenumber> */
    #[ORM\OneToMany(targetEntity: Phonenumber::class, mappedBy: 'user')]
    #[ORM\OrderBy(['number' => 'DESC'])]
    public $phonenumbersDesc;

    /** @var Collection<int, Group> */
    #[ORM\ManyToMany(targetEntity: Group::class)]
    #[ORM\OrderBy(['name' => 'ASC', 'id' => 'DESC'])]
    public $groupsMixed;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
                'type'               => 'integer',
            ],
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);

        $metadata->mapOneToMany(
            [
                'fieldName' => 'phonenumbersAsc',
                'mappedBy' => 'user',
                'targetEntity' => Phonenumber::class,
                'orderBy' => ['number' => 'ASC'],
            ],
        );

        $metadata->mapOneToMany(
            [
                'fieldName' => 'phonenumbersDesc',
                'mappedBy' => 'user',
                'targetEntity' => Phonenumber::class,
                'orderBy' => ['number' => 'DESC'],
            ],
        );

        $metadata->mapManyToMany(
            [
                'fieldName' => 'groupsMixed',
                'targetEntity' => Group::class,
                'orderBy' => ['name' => 'ASC', 'id' => 'DESC'],
            ],
        );
    }
}

/**
 * Entity to test invalid order direction handling
 */
#[ORM\Entity]
class UserWithInvalidOrderBy
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    public int|null $id = null;

    /** @var Collection<int, Phonenumber> */
    #[ORM\OneToMany(targetEntity: Phonenumber::class, mappedBy: 'user')]
    #[ORM\OrderBy(['name' => 'INVALID'])]
    public $items;

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
                'type'               => 'integer',
            ],
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_AUTO);

        $metadata->mapOneToMany(
            [
                'fieldName' => 'items',
                'mappedBy' => 'user',
                'targetEntity' => Phonenumber::class,
                'orderBy' => ['name' => 'INVALID'],
            ],
        );
    }
}
