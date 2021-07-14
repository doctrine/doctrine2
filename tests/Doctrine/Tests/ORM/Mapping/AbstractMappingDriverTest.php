<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\CustomIdGenerator;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\NamedQueries;
use Doctrine\ORM\Mapping\NamedQuery;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\PostPersist;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\SequenceGenerator;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\ORM\Mapping\Version;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\Cache\City;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsAddressListener;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyContractListener;
use Doctrine\Tests\Models\Company\CompanyFixContract;
use Doctrine\Tests\Models\Company\CompanyFlexContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContract;
use Doctrine\Tests\Models\Company\CompanyFlexUltraContractListener;
use Doctrine\Tests\Models\Company\CompanyPerson;
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
use Doctrine\Tests\Models\TypedProperties\Contact;
use Doctrine\Tests\Models\TypedProperties\UserTyped;
use Doctrine\Tests\OrmTestCase;

use function assert;
use function count;
use function get_class;
use function sprintf;
use function strpos;
use function strtolower;

use const CASE_UPPER;
use const PHP_VERSION_ID;

abstract class AbstractMappingDriverTest extends OrmTestCase
{
    abstract protected function loadDriver(): MappingDriver;

    /**
     * @psalm-param class-string<object> $entityClassName
     */
    public function createClassMetadata(string $entityClassName): ClassMetadata
    {
        $mappingDriver = $this->loadDriver();

        $class = new ClassMetadata($entityClassName);
        $class->initializeReflection(new RuntimeReflectionService());
        $mappingDriver->loadMetadataForClass($entityClassName, $class);

        return $class;
    }

    protected function createClassMetadataFactory(?EntityManager $em = null): ClassMetadataFactory
    {
        $driver  = $this->loadDriver();
        $em      = $em ?: $this->getTestEntityManager();
        $factory = new ClassMetadataFactory();
        $em->getConfiguration()->setMetadataDriverImpl($driver);
        $factory->setEntityManager($em);

        return $factory;
    }

    public function testEntityTableNameAndInheritance(): ClassMetadata
    {
        $class = $this->createClassMetadata(User::class);

        $this->assertEquals('cms_users', $class->getTableName());
        $this->assertEquals(ClassMetadata::INHERITANCE_TYPE_NONE, $class->inheritanceType);

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testEntityIndexes(ClassMetadata $class): ClassMetadata
    {
        $this->assertArrayHasKey('indexes', $class->table, 'ClassMetadata should have indexes key in table property.');
        $this->assertEquals(
            [
                'name_idx' => ['columns' => ['name']],
                0 => ['columns' => ['user_email']],
                'fields' => ['fields' => ['name', 'email']],
            ],
            $class->table['indexes']
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

        $this->assertEquals(
            [
                0 => [
                    'columns' => ['content'],
                    'flags' => ['fulltext'],
                    'options' => ['where' => 'content IS NOT NULL'],
                ],
            ],
            $class->table['indexes']
        );
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testEntityUniqueConstraints(ClassMetadata $class): ClassMetadata
    {
        $this->assertArrayHasKey(
            'uniqueConstraints',
            $class->table,
            'ClassMetadata should have uniqueConstraints key in table property when Unique Constraints are set.'
        );

        $this->assertEquals(
            [
                'search_idx' => ['columns' => ['name', 'user_email'], 'options' => ['where' => 'name IS NOT NULL']],
                'phone_idx' => ['fields' => ['name', 'phone']],
            ],
            $class->table['uniqueConstraints']
        );

        return $class;
    }

    public function testEntityIncorrectUniqueContraint(): void
    {
        $this->expectException(MappingException::class);
        $this->createClassMetadata(UserIncorrectUniqueConstraint::class);
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testEntityOptions(ClassMetadata $class): ClassMetadata
    {
        $this->assertArrayHasKey('options', $class->table, 'ClassMetadata should have options key in table property.');

        $this->assertEquals(
            [
                'foo' => 'bar',
                'baz' => ['key' => 'val'],
            ],
            $class->table['options']
        );

        return $class;
    }

    /**
     * @depends testEntityOptions
     */
    public function testEntitySequence(ClassMetadata $class): void
    {
        $this->assertIsArray($class->sequenceGeneratorDefinition, 'No Sequence Definition set on this driver.');
        $this->assertEquals(
            [
                'sequenceName' => 'tablename_seq',
                'allocationSize' => 100,
                'initialValue' => 1,
            ],
            $class->sequenceGeneratorDefinition
        );
    }

    public function testEntityCustomGenerator(): void
    {
        $class = $this->createClassMetadata(Animal::class);

        $this->assertEquals(
            ClassMetadata::GENERATOR_TYPE_CUSTOM,
            $class->generatorType,
            'Generator Type'
        );
        $this->assertEquals(
            ['class' => 'stdClass'],
            $class->customGeneratorDefinition,
            'Custom Generator Definition'
        );
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testFieldMappings(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals(4, count($class->fieldMappings));
        $this->assertTrue(isset($class->fieldMappings['id']));
        $this->assertTrue(isset($class->fieldMappings['name']));
        $this->assertTrue(isset($class->fieldMappings['email']));
        $this->assertTrue(isset($class->fieldMappings['version']));

        return $class;
    }

    /**
     * @depends testFieldMappings
     */
    public function testVersionedField(ClassMetadata $class): void
    {
        $this->assertTrue($class->isVersioned);
        $this->assertEquals('version', $class->versionField);

        $this->assertFalse(isset($class->fieldMappings['version']['version']));
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testFieldMappingsColumnNames(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals('id', $class->fieldMappings['id']['columnName']);
        $this->assertEquals('name', $class->fieldMappings['name']['columnName']);
        $this->assertEquals('user_email', $class->fieldMappings['email']['columnName']);

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testStringFieldMappings(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals('string', $class->fieldMappings['name']['type']);
        $this->assertEquals(50, $class->fieldMappings['name']['length']);
        $this->assertTrue($class->fieldMappings['name']['nullable']);
        $this->assertTrue($class->fieldMappings['name']['unique']);

        return $class;
    }

    public function testFieldTypeFromReflection(): void
    {
        if (PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('requies PHP 7.4');
        }

        $class = $this->createClassMetadata(UserTyped::class);

        $this->assertEquals('integer', $class->getTypeOfField('id'));
        $this->assertEquals('string', $class->getTypeOfField('username'));
        $this->assertEquals('dateinterval', $class->getTypeOfField('dateInterval'));
        $this->assertEquals('datetime', $class->getTypeOfField('dateTime'));
        $this->assertEquals('datetime_immutable', $class->getTypeOfField('dateTimeImmutable'));
        $this->assertEquals('json', $class->getTypeOfField('array'));
        $this->assertEquals('boolean', $class->getTypeOfField('boolean'));
        $this->assertEquals('float', $class->getTypeOfField('float'));

        $this->assertEquals(CmsEmail::class, $class->getAssociationMapping('email')['targetEntity']);
        $this->assertEquals(CmsEmail::class, $class->getAssociationMapping('mainEmail')['targetEntity']);
        $this->assertEquals(Contact::class, $class->embeddedClasses['contact']['class']);
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testFieldOptions(ClassMetadata $class): ClassMetadata
    {
        $expected = ['foo' => 'bar', 'baz' => ['key' => 'val'], 'fixed' => false];
        $this->assertEquals($expected, $class->fieldMappings['name']['options']);

        return $class;
    }

    /**
     * @depends testEntityTableNameAndInheritance
     */
    public function testIdFieldOptions(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals(['foo' => 'bar', 'unsigned' => false], $class->fieldMappings['id']['options']);

        return $class;
    }

    /**
     * @depends testFieldMappings
     */
    public function testIdentifier(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals(['id'], $class->identifier);
        $this->assertEquals('integer', $class->fieldMappings['id']['type']);
        $this->assertEquals(ClassMetadata::GENERATOR_TYPE_AUTO, $class->generatorType, 'ID-Generator is not ClassMetadata::GENERATOR_TYPE_AUTO');

        return $class;
    }

    /**
     * @group #6129
     */
    public function testBooleanValuesForOptionIsSetCorrectly(): ClassMetadata
    {
        $class = $this->createClassMetadata(User::class);

        $this->assertIsBool($class->fieldMappings['id']['options']['unsigned']);
        $this->assertFalse($class->fieldMappings['id']['options']['unsigned']);

        $this->assertIsBool($class->fieldMappings['name']['options']['fixed']);
        $this->assertFalse($class->fieldMappings['name']['options']['fixed']);

        return $class;
    }

    /**
     * @depends testIdentifier
     */
    public function testAssociations(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals(3, count($class->associationMappings));

        return $class;
    }

    /**
     * @depends testAssociations
     */
    public function testOwningOneToOneAssociation(ClassMetadata $class): ClassMetadata
    {
        $this->assertTrue(isset($class->associationMappings['address']));
        $this->assertTrue($class->associationMappings['address']['isOwningSide']);
        $this->assertEquals('user', $class->associationMappings['address']['inversedBy']);
        // Check cascading
        $this->assertTrue($class->associationMappings['address']['isCascadeRemove']);
        $this->assertFalse($class->associationMappings['address']['isCascadePersist']);
        $this->assertFalse($class->associationMappings['address']['isCascadeRefresh']);
        $this->assertFalse($class->associationMappings['address']['isCascadeDetach']);
        $this->assertFalse($class->associationMappings['address']['isCascadeMerge']);

        return $class;
    }

    /**
     * @depends testOwningOneToOneAssociation
     */
    public function testInverseOneToManyAssociation(ClassMetadata $class): ClassMetadata
    {
        $this->assertTrue(isset($class->associationMappings['phonenumbers']));
        $this->assertFalse($class->associationMappings['phonenumbers']['isOwningSide']);
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadePersist']);
        $this->assertTrue($class->associationMappings['phonenumbers']['isCascadeRemove']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeRefresh']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeDetach']);
        $this->assertFalse($class->associationMappings['phonenumbers']['isCascadeMerge']);
        $this->assertTrue($class->associationMappings['phonenumbers']['orphanRemoval']);

        // Test Order By
        $this->assertEquals(['number' => 'ASC'], $class->associationMappings['phonenumbers']['orderBy']);

        return $class;
    }

    /**
     * @depends testInverseOneToManyAssociation
     */
    public function testManyToManyAssociationWithCascadeAll(ClassMetadata $class): ClassMetadata
    {
        $this->assertTrue(isset($class->associationMappings['groups']));
        $this->assertTrue($class->associationMappings['groups']['isOwningSide']);
        // Make sure that cascade-all works as expected
        $this->assertTrue($class->associationMappings['groups']['isCascadeRemove']);
        $this->assertTrue($class->associationMappings['groups']['isCascadePersist']);
        $this->assertTrue($class->associationMappings['groups']['isCascadeRefresh']);
        $this->assertTrue($class->associationMappings['groups']['isCascadeDetach']);
        $this->assertTrue($class->associationMappings['groups']['isCascadeMerge']);

        $this->assertFalse(isset($class->associationMappings['groups']['orderBy']));

        return $class;
    }

    /**
     * @depends testManyToManyAssociationWithCascadeAll
     */
    public function testLifecycleCallbacks(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals(count($class->lifecycleCallbacks), 2);
        $this->assertEquals($class->lifecycleCallbacks['prePersist'][0], 'doStuffOnPrePersist');
        $this->assertEquals($class->lifecycleCallbacks['postPersist'][0], 'doStuffOnPostPersist');

        return $class;
    }

    /**
     * @depends testManyToManyAssociationWithCascadeAll
     */
    public function testLifecycleCallbacksSupportMultipleMethodNames(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals(count($class->lifecycleCallbacks['prePersist']), 2);
        $this->assertEquals($class->lifecycleCallbacks['prePersist'][1], 'doOtherStuffOnPrePersistToo');

        return $class;
    }

    /**
     * @depends testLifecycleCallbacksSupportMultipleMethodNames
     */
    public function testJoinColumnUniqueAndNullable(ClassMetadata $class): ClassMetadata
    {
        // Non-Nullability of Join Column
        $this->assertFalse($class->associationMappings['groups']['joinTable']['joinColumns'][0]['nullable']);
        $this->assertFalse($class->associationMappings['groups']['joinTable']['joinColumns'][0]['unique']);

        return $class;
    }

    /**
     * @depends testJoinColumnUniqueAndNullable
     */
    public function testColumnDefinition(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals('CHAR(32) NOT NULL', $class->fieldMappings['email']['columnDefinition']);
        $this->assertEquals('INT NULL', $class->associationMappings['groups']['joinTable']['inverseJoinColumns'][0]['columnDefinition']);

        return $class;
    }

    /**
     * @depends testColumnDefinition
     */
    public function testJoinColumnOnDelete(ClassMetadata $class): ClassMetadata
    {
        $this->assertEquals('CASCADE', $class->associationMappings['address']['joinColumns'][0]['onDelete']);

        return $class;
    }

    /**
     * @group DDC-514
     */
    public function testDiscriminatorColumnDefaults(): void
    {
        if (strpos(static::class, 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(Animal::class);

        $this->assertEquals(
            ['name' => 'discr', 'type' => 'string', 'length' => '32', 'fieldName' => 'discr', 'columnDefinition' => null],
            $class->discriminatorColumn
        );
    }

    /**
     * @group DDC-869
     */
    public function testMappedSuperclassWithRepository(): void
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);

        $class = $factory->getMetadataFor(DDC869CreditCardPayment::class);

        $this->assertTrue(isset($class->fieldMappings['id']));
        $this->assertTrue(isset($class->fieldMappings['value']));
        $this->assertTrue(isset($class->fieldMappings['creditCardNumber']));
        $this->assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);
        $this->assertInstanceOf(DDC869PaymentRepository::class, $em->getRepository(DDC869CreditCardPayment::class));
        $this->assertTrue($em->getRepository(DDC869ChequePayment::class)->isTrue());

        $class = $factory->getMetadataFor(DDC869ChequePayment::class);

        $this->assertTrue(isset($class->fieldMappings['id']));
        $this->assertTrue(isset($class->fieldMappings['value']));
        $this->assertTrue(isset($class->fieldMappings['serialNumber']));
        $this->assertEquals($class->customRepositoryClassName, DDC869PaymentRepository::class);
        $this->assertInstanceOf(DDC869PaymentRepository::class, $em->getRepository(DDC869ChequePayment::class));
        $this->assertTrue($em->getRepository(DDC869ChequePayment::class)->isTrue());
    }

    /**
     * @group DDC-1476
     */
    public function testDefaultFieldType(): void
    {
        $factory = $this->createClassMetadataFactory();
        $class   = $factory->getMetadataFor(DDC1476EntityWithDefaultFieldType::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('name', $class->fieldMappings);

        $this->assertArrayHasKey('type', $class->fieldMappings['id']);
        $this->assertArrayHasKey('type', $class->fieldMappings['name']);

        $this->assertEquals('string', $class->fieldMappings['id']['type']);
        $this->assertEquals('string', $class->fieldMappings['name']['type']);

        $this->assertArrayHasKey('fieldName', $class->fieldMappings['id']);
        $this->assertArrayHasKey('fieldName', $class->fieldMappings['name']);

        $this->assertEquals('id', $class->fieldMappings['id']['fieldName']);
        $this->assertEquals('name', $class->fieldMappings['name']['fieldName']);

        $this->assertArrayHasKey('columnName', $class->fieldMappings['id']);
        $this->assertArrayHasKey('columnName', $class->fieldMappings['name']);

        $this->assertEquals('id', $class->fieldMappings['id']['columnName']);
        $this->assertEquals('name', $class->fieldMappings['name']['columnName']);

        $this->assertEquals(ClassMetadataInfo::GENERATOR_TYPE_NONE, $class->generatorType);
    }

    /**
     * @group DDC-1170
     */
    public function testIdentifierColumnDefinition(): void
    {
        $class = $this->createClassMetadata(DDC1170Entity::class);

        $this->assertArrayHasKey('id', $class->fieldMappings);
        $this->assertArrayHasKey('value', $class->fieldMappings);

        $this->assertArrayHasKey('columnDefinition', $class->fieldMappings['id']);
        $this->assertArrayHasKey('columnDefinition', $class->fieldMappings['value']);

        $this->assertEquals('int unsigned not null', strtolower($class->fieldMappings['id']['columnDefinition']));
        $this->assertEquals('varchar(255) not null', strtolower($class->fieldMappings['value']['columnDefinition']));
    }

    /**
     * @group DDC-559
     */
    public function testNamingStrategy(): void
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);

        $this->assertInstanceOf(DefaultNamingStrategy::class, $em->getConfiguration()->getNamingStrategy());
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy(CASE_UPPER));
        $this->assertInstanceOf(UnderscoreNamingStrategy::class, $em->getConfiguration()->getNamingStrategy());

        $class = $factory->getMetadataFor(DDC1476EntityWithDefaultFieldType::class);

        $this->assertEquals('ID', $class->getColumnName('id'));
        $this->assertEquals('NAME', $class->getColumnName('name'));
        $this->assertEquals('DDC1476ENTITY_WITH_DEFAULT_FIELD_TYPE', $class->table['name']);
    }

    /**
     * @group DDC-807
     * @group DDC-553
     */
    public function testDiscriminatorColumnDefinition(): void
    {
        $class = $this->createClassMetadata(DDC807Entity::class);

        $this->assertArrayHasKey('columnDefinition', $class->discriminatorColumn);
        $this->assertArrayHasKey('name', $class->discriminatorColumn);

        $this->assertEquals("ENUM('ONE','TWO')", $class->discriminatorColumn['columnDefinition']);
        $this->assertEquals('dtype', $class->discriminatorColumn['name']);
    }

    /**
     * @group DDC-889
     */
    public function testInvalidEntityOrMappedSuperClassShouldMentionParentClasses(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Class "Doctrine\Tests\Models\DDC889\DDC889Class" sub class of "Doctrine\Tests\Models\DDC889\DDC889SuperClass" is not a valid entity or mapped super class.');

        $this->createClassMetadata(DDC889Class::class);
    }

    /**
     * @group DDC-889
     */
    public function testIdentifierRequiredShouldMentionParentClasses(): void
    {
        $factory = $this->createClassMetadataFactory();

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('No identifier/primary key specified for Entity "Doctrine\Tests\Models\DDC889\DDC889Entity" sub class of "Doctrine\Tests\Models\DDC889\DDC889SuperClass". Every Entity must have an identifier/primary key.');

        $factory->getMetadataFor(DDC889Entity::class);
    }

    public function testNamedQuery(): void
    {
        $driver = $this->loadDriver();
        $class  = $this->createClassMetadata(User::class);

        $this->assertCount(1, $class->getNamedQueries(), sprintf('Named queries not processed correctly by driver %s', get_class($driver)));
    }

    /**
     * @group DDC-1663
     */
    public function testNamedNativeQuery(): void
    {
        $class = $this->createClassMetadata(CmsAddress::class);

        //named native query
        $this->assertCount(3, $class->namedNativeQueries);
        $this->assertArrayHasKey('find-all', $class->namedNativeQueries);
        $this->assertArrayHasKey('find-by-id', $class->namedNativeQueries);

        $findAllQuery = $class->getNamedNativeQuery('find-all');
        $this->assertEquals('find-all', $findAllQuery['name']);
        $this->assertEquals('mapping-find-all', $findAllQuery['resultSetMapping']);
        $this->assertEquals('SELECT id, country, city FROM cms_addresses', $findAllQuery['query']);

        $findByIdQuery = $class->getNamedNativeQuery('find-by-id');
        $this->assertEquals('find-by-id', $findByIdQuery['name']);
        $this->assertEquals(CmsAddress::class, $findByIdQuery['resultClass']);
        $this->assertEquals('SELECT * FROM cms_addresses WHERE id = ?', $findByIdQuery['query']);

        $countQuery = $class->getNamedNativeQuery('count');
        $this->assertEquals('count', $countQuery['name']);
        $this->assertEquals('mapping-count', $countQuery['resultSetMapping']);
        $this->assertEquals('SELECT COUNT(*) AS count FROM cms_addresses', $countQuery['query']);

        // result set mapping
        $this->assertCount(3, $class->sqlResultSetMappings);
        $this->assertArrayHasKey('mapping-count', $class->sqlResultSetMappings);
        $this->assertArrayHasKey('mapping-find-all', $class->sqlResultSetMappings);
        $this->assertArrayHasKey('mapping-without-fields', $class->sqlResultSetMappings);

        $findAllMapping = $class->getSqlResultSetMapping('mapping-find-all');
        $this->assertEquals('mapping-find-all', $findAllMapping['name']);
        $this->assertEquals(CmsAddress::class, $findAllMapping['entities'][0]['entityClass']);
        $this->assertEquals(['name' => 'id', 'column' => 'id'], $findAllMapping['entities'][0]['fields'][0]);
        $this->assertEquals(['name' => 'city', 'column' => 'city'], $findAllMapping['entities'][0]['fields'][1]);
        $this->assertEquals(['name' => 'country', 'column' => 'country'], $findAllMapping['entities'][0]['fields'][2]);

        $withoutFieldsMapping = $class->getSqlResultSetMapping('mapping-without-fields');
        $this->assertEquals('mapping-without-fields', $withoutFieldsMapping['name']);
        $this->assertEquals(CmsAddress::class, $withoutFieldsMapping['entities'][0]['entityClass']);
        $this->assertEquals([], $withoutFieldsMapping['entities'][0]['fields']);

        $countMapping = $class->getSqlResultSetMapping('mapping-count');
        $this->assertEquals('mapping-count', $countMapping['name']);
        $this->assertEquals(['name' => 'count'], $countMapping['columns'][0]);
    }

    /**
     * @group DDC-1663
     */
    public function testSqlResultSetMapping(): void
    {
        $userMetadata   = $this->createClassMetadata(CmsUser::class);
        $personMetadata = $this->createClassMetadata(CompanyPerson::class);

        // user asserts
        $this->assertCount(4, $userMetadata->getSqlResultSetMappings());

        $mapping = $userMetadata->getSqlResultSetMapping('mappingJoinedAddress');
        $this->assertEquals([], $mapping['columns']);
        $this->assertEquals('mappingJoinedAddress', $mapping['name']);
        $this->assertNull($mapping['entities'][0]['discriminatorColumn']);
        $this->assertEquals(['name' => 'id', 'column' => 'id'], $mapping['entities'][0]['fields'][0]);
        $this->assertEquals(['name' => 'name', 'column' => 'name'], $mapping['entities'][0]['fields'][1]);
        $this->assertEquals(['name' => 'status', 'column' => 'status'], $mapping['entities'][0]['fields'][2]);
        $this->assertEquals(['name' => 'address.zip', 'column' => 'zip'], $mapping['entities'][0]['fields'][3]);
        $this->assertEquals(['name' => 'address.city', 'column' => 'city'], $mapping['entities'][0]['fields'][4]);
        $this->assertEquals(['name' => 'address.country', 'column' => 'country'], $mapping['entities'][0]['fields'][5]);
        $this->assertEquals(['name' => 'address.id', 'column' => 'a_id'], $mapping['entities'][0]['fields'][6]);
        $this->assertEquals($userMetadata->name, $mapping['entities'][0]['entityClass']);

        $mapping = $userMetadata->getSqlResultSetMapping('mappingJoinedPhonenumber');
        $this->assertEquals([], $mapping['columns']);
        $this->assertEquals('mappingJoinedPhonenumber', $mapping['name']);
        $this->assertNull($mapping['entities'][0]['discriminatorColumn']);
        $this->assertEquals(['name' => 'id', 'column' => 'id'], $mapping['entities'][0]['fields'][0]);
        $this->assertEquals(['name' => 'name', 'column' => 'name'], $mapping['entities'][0]['fields'][1]);
        $this->assertEquals(['name' => 'status', 'column' => 'status'], $mapping['entities'][0]['fields'][2]);
        $this->assertEquals(['name' => 'phonenumbers.phonenumber', 'column' => 'number'], $mapping['entities'][0]['fields'][3]);
        $this->assertEquals($userMetadata->name, $mapping['entities'][0]['entityClass']);

        $mapping = $userMetadata->getSqlResultSetMapping('mappingUserPhonenumberCount');
        $this->assertEquals(['name' => 'numphones'], $mapping['columns'][0]);
        $this->assertEquals('mappingUserPhonenumberCount', $mapping['name']);
        $this->assertNull($mapping['entities'][0]['discriminatorColumn']);
        $this->assertEquals(['name' => 'id', 'column' => 'id'], $mapping['entities'][0]['fields'][0]);
        $this->assertEquals(['name' => 'name', 'column' => 'name'], $mapping['entities'][0]['fields'][1]);
        $this->assertEquals(['name' => 'status', 'column' => 'status'], $mapping['entities'][0]['fields'][2]);
        $this->assertEquals($userMetadata->name, $mapping['entities'][0]['entityClass']);

        $mapping = $userMetadata->getSqlResultSetMapping('mappingMultipleJoinsEntityResults');
        $this->assertEquals(['name' => 'numphones'], $mapping['columns'][0]);
        $this->assertEquals('mappingMultipleJoinsEntityResults', $mapping['name']);
        $this->assertNull($mapping['entities'][0]['discriminatorColumn']);
        $this->assertEquals(['name' => 'id', 'column' => 'u_id'], $mapping['entities'][0]['fields'][0]);
        $this->assertEquals(['name' => 'name', 'column' => 'u_name'], $mapping['entities'][0]['fields'][1]);
        $this->assertEquals(['name' => 'status', 'column' => 'u_status'], $mapping['entities'][0]['fields'][2]);
        $this->assertEquals($userMetadata->name, $mapping['entities'][0]['entityClass']);
        $this->assertNull($mapping['entities'][1]['discriminatorColumn']);
        $this->assertEquals(['name' => 'id', 'column' => 'a_id'], $mapping['entities'][1]['fields'][0]);
        $this->assertEquals(['name' => 'zip', 'column' => 'a_zip'], $mapping['entities'][1]['fields'][1]);
        $this->assertEquals(['name' => 'country', 'column' => 'a_country'], $mapping['entities'][1]['fields'][2]);
        $this->assertEquals(CmsAddress::class, $mapping['entities'][1]['entityClass']);

        //person asserts
        $this->assertCount(1, $personMetadata->getSqlResultSetMappings());

        $mapping = $personMetadata->getSqlResultSetMapping('mappingFetchAll');
        $this->assertEquals([], $mapping['columns']);
        $this->assertEquals('mappingFetchAll', $mapping['name']);
        $this->assertEquals('discriminator', $mapping['entities'][0]['discriminatorColumn']);
        $this->assertEquals(['name' => 'id', 'column' => 'id'], $mapping['entities'][0]['fields'][0]);
        $this->assertEquals(['name' => 'name', 'column' => 'name'], $mapping['entities'][0]['fields'][1]);
        $this->assertEquals($personMetadata->name, $mapping['entities'][0]['entityClass']);
    }

    /**
     * @group DDC-964
     */
    public function testAssociationOverridesMapping(): void
    {
        $factory       = $this->createClassMetadataFactory();
        $adminMetadata = $factory->getMetadataFor(DDC964Admin::class);
        $guestMetadata = $factory->getMetadataFor(DDC964Guest::class);

        // assert groups association mappings
        $this->assertArrayHasKey('groups', $guestMetadata->associationMappings);
        $this->assertArrayHasKey('groups', $adminMetadata->associationMappings);

        $guestGroups = $guestMetadata->associationMappings['groups'];
        $adminGroups = $adminMetadata->associationMappings['groups'];

        // assert not override attributes
        $this->assertEquals($guestGroups['fieldName'], $adminGroups['fieldName']);
        $this->assertEquals($guestGroups['type'], $adminGroups['type']);
        $this->assertEquals($guestGroups['mappedBy'], $adminGroups['mappedBy']);
        $this->assertEquals($guestGroups['inversedBy'], $adminGroups['inversedBy']);
        $this->assertEquals($guestGroups['isOwningSide'], $adminGroups['isOwningSide']);
        $this->assertEquals($guestGroups['fetch'], $adminGroups['fetch']);
        $this->assertEquals($guestGroups['isCascadeRemove'], $adminGroups['isCascadeRemove']);
        $this->assertEquals($guestGroups['isCascadePersist'], $adminGroups['isCascadePersist']);
        $this->assertEquals($guestGroups['isCascadeRefresh'], $adminGroups['isCascadeRefresh']);
        $this->assertEquals($guestGroups['isCascadeMerge'], $adminGroups['isCascadeMerge']);
        $this->assertEquals($guestGroups['isCascadeDetach'], $adminGroups['isCascadeDetach']);

         // assert not override attributes
        $this->assertEquals('ddc964_users_groups', $guestGroups['joinTable']['name']);
        $this->assertEquals('user_id', $guestGroups['joinTable']['joinColumns'][0]['name']);
        $this->assertEquals('group_id', $guestGroups['joinTable']['inverseJoinColumns'][0]['name']);

        $this->assertEquals(['user_id' => 'id'], $guestGroups['relationToSourceKeyColumns']);
        $this->assertEquals(['group_id' => 'id'], $guestGroups['relationToTargetKeyColumns']);
        $this->assertEquals(['user_id', 'group_id'], $guestGroups['joinTableColumns']);

        $this->assertEquals('ddc964_users_admingroups', $adminGroups['joinTable']['name']);
        $this->assertEquals('adminuser_id', $adminGroups['joinTable']['joinColumns'][0]['name']);
        $this->assertEquals('admingroup_id', $adminGroups['joinTable']['inverseJoinColumns'][0]['name']);

        $this->assertEquals(['adminuser_id' => 'id'], $adminGroups['relationToSourceKeyColumns']);
        $this->assertEquals(['admingroup_id' => 'id'], $adminGroups['relationToTargetKeyColumns']);
        $this->assertEquals(['adminuser_id', 'admingroup_id'], $adminGroups['joinTableColumns']);

        // assert address association mappings
        $this->assertArrayHasKey('address', $guestMetadata->associationMappings);
        $this->assertArrayHasKey('address', $adminMetadata->associationMappings);

        $guestAddress = $guestMetadata->associationMappings['address'];
        $adminAddress = $adminMetadata->associationMappings['address'];

        // assert not override attributes
        $this->assertEquals($guestAddress['fieldName'], $adminAddress['fieldName']);
        $this->assertEquals($guestAddress['type'], $adminAddress['type']);
        $this->assertEquals($guestAddress['mappedBy'], $adminAddress['mappedBy']);
        $this->assertEquals($guestAddress['inversedBy'], $adminAddress['inversedBy']);
        $this->assertEquals($guestAddress['isOwningSide'], $adminAddress['isOwningSide']);
        $this->assertEquals($guestAddress['fetch'], $adminAddress['fetch']);
        $this->assertEquals($guestAddress['isCascadeRemove'], $adminAddress['isCascadeRemove']);
        $this->assertEquals($guestAddress['isCascadePersist'], $adminAddress['isCascadePersist']);
        $this->assertEquals($guestAddress['isCascadeRefresh'], $adminAddress['isCascadeRefresh']);
        $this->assertEquals($guestAddress['isCascadeMerge'], $adminAddress['isCascadeMerge']);
        $this->assertEquals($guestAddress['isCascadeDetach'], $adminAddress['isCascadeDetach']);

        // assert override
        $this->assertEquals('address_id', $guestAddress['joinColumns'][0]['name']);
        $this->assertEquals(['address_id' => 'id'], $guestAddress['sourceToTargetKeyColumns']);
        $this->assertEquals(['address_id' => 'address_id'], $guestAddress['joinColumnFieldNames']);
        $this->assertEquals(['id' => 'address_id'], $guestAddress['targetToSourceKeyColumns']);

        $this->assertEquals('adminaddress_id', $adminAddress['joinColumns'][0]['name']);
        $this->assertEquals(['adminaddress_id' => 'id'], $adminAddress['sourceToTargetKeyColumns']);
        $this->assertEquals(['adminaddress_id' => 'adminaddress_id'], $adminAddress['joinColumnFieldNames']);
        $this->assertEquals(['id' => 'adminaddress_id'], $adminAddress['targetToSourceKeyColumns']);
    }

    /**
     * @group DDC-3579
     */
    public function testInversedByOverrideMapping(): void
    {
        $factory       = $this->createClassMetadataFactory();
        $adminMetadata = $factory->getMetadataFor(DDC3579Admin::class);

        // assert groups association mappings
        $this->assertArrayHasKey('groups', $adminMetadata->associationMappings);
        $adminGroups = $adminMetadata->associationMappings['groups'];

        // assert override
        $this->assertEquals('admins', $adminGroups['inversedBy']);
    }

    /**
     * @group DDC-5934
     */
    public function testFetchOverrideMapping(): void
    {
        // check override metadata
        $contractMetadata = $this->createClassMetadataFactory()->getMetadataFor(DDC5934Contract::class);

        $this->assertArrayHasKey('members', $contractMetadata->associationMappings);
        $this->assertSame(ClassMetadata::FETCH_EXTRA_LAZY, $contractMetadata->associationMappings['members']['fetch']);
    }

    /**
     * @group DDC-964
     */
    public function testAttributeOverridesMapping(): void
    {
        $factory       = $this->createClassMetadataFactory();
        $guestMetadata = $factory->getMetadataFor(DDC964Guest::class);
        $adminMetadata = $factory->getMetadataFor(DDC964Admin::class);

        $this->assertTrue($adminMetadata->fieldMappings['id']['id']);
        $this->assertEquals('id', $adminMetadata->fieldMappings['id']['fieldName']);
        $this->assertEquals('user_id', $adminMetadata->fieldMappings['id']['columnName']);
        $this->assertEquals(['user_id' => 'id', 'user_name' => 'name'], $adminMetadata->fieldNames);
        $this->assertEquals(['id' => 'user_id', 'name' => 'user_name'], $adminMetadata->columnNames);
        $this->assertEquals(150, $adminMetadata->fieldMappings['id']['length']);

        $this->assertEquals('name', $adminMetadata->fieldMappings['name']['fieldName']);
        $this->assertEquals('user_name', $adminMetadata->fieldMappings['name']['columnName']);
        $this->assertEquals(250, $adminMetadata->fieldMappings['name']['length']);
        $this->assertTrue($adminMetadata->fieldMappings['name']['nullable']);
        $this->assertFalse($adminMetadata->fieldMappings['name']['unique']);

        $this->assertTrue($guestMetadata->fieldMappings['id']['id']);
        $this->assertEquals('guest_id', $guestMetadata->fieldMappings['id']['columnName']);
        $this->assertEquals('id', $guestMetadata->fieldMappings['id']['fieldName']);
        $this->assertEquals(['guest_id' => 'id', 'guest_name' => 'name'], $guestMetadata->fieldNames);
        $this->assertEquals(['id' => 'guest_id', 'name' => 'guest_name'], $guestMetadata->columnNames);
        $this->assertEquals(140, $guestMetadata->fieldMappings['id']['length']);

        $this->assertEquals('name', $guestMetadata->fieldMappings['name']['fieldName']);
        $this->assertEquals('guest_name', $guestMetadata->fieldMappings['name']['columnName']);
        $this->assertEquals(240, $guestMetadata->fieldMappings['name']['length']);
        $this->assertFalse($guestMetadata->fieldMappings['name']['nullable']);
        $this->assertTrue($guestMetadata->fieldMappings['name']['unique']);
    }

    /**
     * @group DDC-1955
     */
    public function testEntityListeners(): void
    {
        $em         = $this->getTestEntityManager();
        $factory    = $this->createClassMetadataFactory($em);
        $superClass = $factory->getMetadataFor(CompanyContract::class);
        $flexClass  = $factory->getMetadataFor(CompanyFixContract::class);
        $fixClass   = $factory->getMetadataFor(CompanyFlexContract::class);
        $ultraClass = $factory->getMetadataFor(CompanyFlexUltraContract::class);

        $this->assertArrayHasKey(Events::prePersist, $superClass->entityListeners);
        $this->assertArrayHasKey(Events::postPersist, $superClass->entityListeners);

        $this->assertCount(1, $superClass->entityListeners[Events::prePersist]);
        $this->assertCount(1, $superClass->entityListeners[Events::postPersist]);

        $postPersist = $superClass->entityListeners[Events::postPersist][0];
        $prePersist  = $superClass->entityListeners[Events::prePersist][0];

        $this->assertEquals(CompanyContractListener::class, $postPersist['class']);
        $this->assertEquals(CompanyContractListener::class, $prePersist['class']);
        $this->assertEquals('postPersistHandler', $postPersist['method']);
        $this->assertEquals('prePersistHandler', $prePersist['method']);

        //Inherited listeners
        $this->assertEquals($fixClass->entityListeners, $superClass->entityListeners);
        $this->assertEquals($flexClass->entityListeners, $superClass->entityListeners);
    }

    /**
     * @group DDC-1955
     */
    public function testEntityListenersOverride(): void
    {
        $em         = $this->getTestEntityManager();
        $factory    = $this->createClassMetadataFactory($em);
        $ultraClass = $factory->getMetadataFor(CompanyFlexUltraContract::class);

        //overridden listeners
        $this->assertArrayHasKey(Events::postPersist, $ultraClass->entityListeners);
        $this->assertArrayHasKey(Events::prePersist, $ultraClass->entityListeners);

        $this->assertCount(1, $ultraClass->entityListeners[Events::postPersist]);
        $this->assertCount(3, $ultraClass->entityListeners[Events::prePersist]);

        $postPersist = $ultraClass->entityListeners[Events::postPersist][0];
        $prePersist  = $ultraClass->entityListeners[Events::prePersist][0];

        $this->assertEquals(CompanyContractListener::class, $postPersist['class']);
        $this->assertEquals(CompanyContractListener::class, $prePersist['class']);
        $this->assertEquals('postPersistHandler', $postPersist['method']);
        $this->assertEquals('prePersistHandler', $prePersist['method']);

        $prePersist = $ultraClass->entityListeners[Events::prePersist][1];
        $this->assertEquals(CompanyFlexUltraContractListener::class, $prePersist['class']);
        $this->assertEquals('prePersistHandler1', $prePersist['method']);

        $prePersist = $ultraClass->entityListeners[Events::prePersist][2];
        $this->assertEquals(CompanyFlexUltraContractListener::class, $prePersist['class']);
        $this->assertEquals('prePersistHandler2', $prePersist['method']);
    }

    /**
     * @group DDC-1955
     */
    public function testEntityListenersNamingConvention(): void
    {
        $em       = $this->getTestEntityManager();
        $factory  = $this->createClassMetadataFactory($em);
        $metadata = $factory->getMetadataFor(CmsAddress::class);

        $this->assertArrayHasKey(Events::postPersist, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::prePersist, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::postUpdate, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::preUpdate, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::postRemove, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::preRemove, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::postLoad, $metadata->entityListeners);
        $this->assertArrayHasKey(Events::preFlush, $metadata->entityListeners);

        $this->assertCount(1, $metadata->entityListeners[Events::postPersist]);
        $this->assertCount(1, $metadata->entityListeners[Events::prePersist]);
        $this->assertCount(1, $metadata->entityListeners[Events::postUpdate]);
        $this->assertCount(1, $metadata->entityListeners[Events::preUpdate]);
        $this->assertCount(1, $metadata->entityListeners[Events::postRemove]);
        $this->assertCount(1, $metadata->entityListeners[Events::preRemove]);
        $this->assertCount(1, $metadata->entityListeners[Events::postLoad]);
        $this->assertCount(1, $metadata->entityListeners[Events::preFlush]);

        $postPersist = $metadata->entityListeners[Events::postPersist][0];
        $prePersist  = $metadata->entityListeners[Events::prePersist][0];
        $postUpdate  = $metadata->entityListeners[Events::postUpdate][0];
        $preUpdate   = $metadata->entityListeners[Events::preUpdate][0];
        $postRemove  = $metadata->entityListeners[Events::postRemove][0];
        $preRemove   = $metadata->entityListeners[Events::preRemove][0];
        $postLoad    = $metadata->entityListeners[Events::postLoad][0];
        $preFlush    = $metadata->entityListeners[Events::preFlush][0];

        $this->assertEquals(CmsAddressListener::class, $postPersist['class']);
        $this->assertEquals(CmsAddressListener::class, $prePersist['class']);
        $this->assertEquals(CmsAddressListener::class, $postUpdate['class']);
        $this->assertEquals(CmsAddressListener::class, $preUpdate['class']);
        $this->assertEquals(CmsAddressListener::class, $postRemove['class']);
        $this->assertEquals(CmsAddressListener::class, $preRemove['class']);
        $this->assertEquals(CmsAddressListener::class, $postLoad['class']);
        $this->assertEquals(CmsAddressListener::class, $preFlush['class']);

        $this->assertEquals(Events::postPersist, $postPersist['method']);
        $this->assertEquals(Events::prePersist, $prePersist['method']);
        $this->assertEquals(Events::postUpdate, $postUpdate['method']);
        $this->assertEquals(Events::preUpdate, $preUpdate['method']);
        $this->assertEquals(Events::postRemove, $postRemove['method']);
        $this->assertEquals(Events::preRemove, $preRemove['method']);
        $this->assertEquals(Events::postLoad, $postLoad['method']);
        $this->assertEquals(Events::preFlush, $preFlush['method']);
    }

    /**
     * @group DDC-2183
     */
    public function testSecondLevelCacheMapping(): void
    {
        $em      = $this->getTestEntityManager();
        $factory = $this->createClassMetadataFactory($em);
        $class   = $factory->getMetadataFor(City::class);
        $this->assertArrayHasKey('usage', $class->cache);
        $this->assertArrayHasKey('region', $class->cache);
        $this->assertEquals(ClassMetadata::CACHE_USAGE_READ_ONLY, $class->cache['usage']);
        $this->assertEquals('doctrine_tests_models_cache_city', $class->cache['region']);

        $this->assertArrayHasKey('state', $class->associationMappings);
        $this->assertArrayHasKey('cache', $class->associationMappings['state']);
        $this->assertArrayHasKey('usage', $class->associationMappings['state']['cache']);
        $this->assertArrayHasKey('region', $class->associationMappings['state']['cache']);
        $this->assertEquals(ClassMetadata::CACHE_USAGE_READ_ONLY, $class->associationMappings['state']['cache']['usage']);
        $this->assertEquals('doctrine_tests_models_cache_city__state', $class->associationMappings['state']['cache']['region']);

        $this->assertArrayHasKey('attractions', $class->associationMappings);
        $this->assertArrayHasKey('cache', $class->associationMappings['attractions']);
        $this->assertArrayHasKey('usage', $class->associationMappings['attractions']['cache']);
        $this->assertArrayHasKey('region', $class->associationMappings['attractions']['cache']);
        $this->assertEquals(ClassMetadata::CACHE_USAGE_READ_ONLY, $class->associationMappings['attractions']['cache']['usage']);
        $this->assertEquals('doctrine_tests_models_cache_city__attractions', $class->associationMappings['attractions']['cache']['region']);
    }

    /**
     * @group DDC-2825
     * @group 881
     */
    public function testSchemaDefinitionViaExplicitTableSchemaAnnotationProperty(): void
    {
        $metadata = $this->createClassMetadataFactory()->getMetadataFor(ExplicitSchemaAndTable::class);
        assert($metadata instanceof ClassMetadata);

        $this->assertSame('explicit_schema', $metadata->getSchemaName());
        $this->assertSame('explicit_table', $metadata->getTableName());
    }

    /**
     * @group DDC-2825
     * @group 881
     */
    public function testSchemaDefinitionViaSchemaDefinedInTableNameInTableAnnotationProperty(): void
    {
        $metadata = $this->createClassMetadataFactory()->getMetadataFor(SchemaAndTableInTableName::class);
        assert($metadata instanceof ClassMetadata);

        $this->assertSame('implicit_schema', $metadata->getSchemaName());
        $this->assertSame('implicit_table', $metadata->getTableName());
    }

    /**
     * @group DDC-514
     * @group DDC-1015
     */
    public function testDiscriminatorColumnDefaultLength(): void
    {
        if (strpos(static::class, 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);
        $this->assertEquals(255, $class->discriminatorColumn['length']);
        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);
        $this->assertEquals(255, $class->discriminatorColumn['length']);
    }

    /**
     * @group DDC-514
     * @group DDC-1015
     */
    public function testDiscriminatorColumnDefaultType(): void
    {
        if (strpos(static::class, 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);
        $this->assertEquals('string', $class->discriminatorColumn['type']);
        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);
        $this->assertEquals('string', $class->discriminatorColumn['type']);
    }

    /**
     * @group DDC-514
     * @group DDC-1015
     */
    public function testDiscriminatorColumnDefaultName(): void
    {
        if (strpos(static::class, 'PHPMappingDriver') !== false) {
            $this->markTestSkipped('PHP Mapping Drivers have no defaults.');
        }

        $class = $this->createClassMetadata(SingleTableEntityNoDiscriminatorColumnMapping::class);
        $this->assertEquals('dtype', $class->discriminatorColumn['name']);
        $class = $this->createClassMetadata(SingleTableEntityIncompleteDiscriminatorColumnMapping::class);
        $this->assertEquals('dtype', $class->discriminatorColumn['name']);
    }
}

/**
 * @Entity
 * @HasLifecycleCallbacks
 * @Table(
 *  name="cms_users",
 *  uniqueConstraints={@UniqueConstraint(name="search_idx", columns={"name", "user_email"}, options={"where": "name IS NOT NULL"}), @UniqueConstraint(name="phone_idx", fields={"name", "phone"})},
 *  indexes={@Index(name="name_idx", columns={"name"}), @Index(name="0", columns={"user_email"}), @index(name="fields", fields={"name", "email"})},
 *  options={"foo": "bar", "baz": {"key": "val"}}
 * )
 * @NamedQueries({@NamedQuery(name="all", query="SELECT u FROM __CLASS__ u")})
 */
#[ORM\Entity(), ORM\HasLifecycleCallbacks()]
#[ORM\Table(name: 'cms_users', options: ['foo' => 'bar', 'baz' => ['key' => 'val']])]
#[ORM\Index(name: 'name_idx', columns: ['name']), ORM\Index(name: '0', columns: ['user_email']), ORM\Index(name: 'fields', fields: ['name', 'email'])]
#[ORM\UniqueConstraint(name: 'search_idx', columns: ['name', 'user_email'], options: ['where' => 'name IS NOT NULL']), ORM\UniqueConstraint(name: 'phone_idx', fields: ['name', 'phone'])]
class User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer", options={"foo": "bar", "unsigned": false})
     * @GeneratedValue(strategy="AUTO")
     * @SequenceGenerator(sequenceName="tablename_seq", initialValue=1, allocationSize=100)
     **/
    #[ORM\Id, ORM\Column(type: 'integer', options: ['foo' => 'bar', 'unsigned' => false])]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\SequenceGenerator(sequenceName: 'tablename_seq', initialValue: 1, allocationSize: 100)]
    public $id;

    /**
     * @var string
     * @Column(length=50, nullable=true, unique=true, options={"foo": "bar", "baz": {"key": "val"}, "fixed": false})
     */
    #[ORM\Column(length: 50, nullable: true, unique: true, options: ['foo' => 'bar', 'baz' => ['key' => 'val'], 'fixed' => false])]
    public $name;

    /**
     * @var string
     * @Column(name="user_email", columnDefinition="CHAR(32) NOT NULL")
     */
    #[ORM\Column(name: 'user_email', columnDefinition: 'CHAR(32) NOT NULL')]
    public $email;

    /**
     * @var Address
     * @OneToOne(targetEntity="Address", cascade={"remove"}, inversedBy="user")
     * @JoinColumn(onDelete="CASCADE")
     */
    #[ORM\OneToOne(targetEntity: 'Address', cascade: ['remove'], inversedBy: 'user')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public $address;

    /**
     * @var Collection<int, Phonenumber>
     * @OneToMany(targetEntity="Phonenumber", mappedBy="user", cascade={"persist"}, orphanRemoval=true)
     * @OrderBy({"number"="ASC"})
     */
    #[ORM\OneToMany(targetEntity: 'Phonenumber', mappedBy: 'user', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    public $phonenumbers;

    /**
     * @var Collection<int, Group>
     * @ManyToMany(targetEntity="Group", cascade={"all"})
     * @JoinTable(name="cms_user_groups",
     *    joinColumns={@JoinColumn(name="user_id", referencedColumnName="id", nullable=false, unique=false)},
     *    inverseJoinColumns={@JoinColumn(name="group_id", referencedColumnName="id", columnDefinition="INT NULL")}
     * )
     */
    #[ORM\ManyToMany(targetEntity: 'Group', cascade: ['all'])]
    #[ORM\JoinTable(name: 'cms_user_groups')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, unique: false)]
    #[ORM\InverseJoinColumn(name: 'group_id', referencedColumnName: 'id', columnDefinition: 'INT NULL')]
    public $groups;

    /**
     * @var int
     * @Column(type="integer")
     * @Version
     */
    #[ORM\Column(type: 'integer'), ORM\Version]
    public $version;

    /**
     * @PrePersist
     */
    #[ORM\PrePersist]
    public function doStuffOnPrePersist(): void
    {
    }

    /**
     * @PrePersist
     */
    #[ORM\PrePersist]
    public function doOtherStuffOnPrePersistToo(): void
    {
    }

    /**
     * @PostPersist
     */
    #[ORM\PostPersist]
    public function doStuffOnPostPersist(): void
    {
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadataInfo::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable(
            [
                'name' => 'cms_users',
                'options' => ['foo' => 'bar', 'baz' => ['key' => 'val']],
            ]
        );
        $metadata->setChangeTrackingPolicy(ClassMetadataInfo::CHANGETRACKING_DEFERRED_IMPLICIT);
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
            ]
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
            ]
        );
        $metadata->mapField(
            [
                'fieldName' => 'email',
                'type' => 'string',
                'columnName' => 'user_email',
                'columnDefinition' => 'CHAR(32) NOT NULL',
            ]
        );
        $mapping = ['fieldName' => 'version', 'type' => 'integer'];
        $metadata->setVersionMapping($mapping);
        $metadata->mapField($mapping);
        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_AUTO);
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
            ]
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
                ['number' => 'ASC'],
            ]
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
                    3 => 'merge',
                    4 => 'detach',
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
                            'nullable' => false,
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
                'orderBy' => null,
            ]
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
            ]
        );
        $metadata->addNamedQuery(
            [
                'name' => 'all',
                'query' => 'SELECT u FROM __CLASS__ u',
            ]
        );
    }
}

/**
 * @Entity
 * @Table(
 *  indexes={@Index(name="name_idx", columns={"name"}, fields={"email"})},
 * )
 */
class UserIncorrectIndex
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     **/
    public $id;

    /**
     * @var string
     * @Column
     */
    public $name;

    /**
     * @var string
     * @Column(name="user_email")
     */
    public $email;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadataInfo::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable([]);
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
                'type' => 'integer',
                'columnName' => 'id',
            ]
        );
        $metadata->mapField(
            [
                'fieldName' => 'name',
                'type' => 'string',
            ]
        );
        $metadata->mapField(
            [
                'fieldName' => 'email',
                'type' => 'string',
                'columnName' => 'user_email',
            ]
        );
        $metadata->table['indexes'] = [
            'name_idx' => ['columns' => ['name'], 'fields' => ['email']],
        ];
    }
}

/**
 * @Entity
 * @Table(
 *  uniqueConstraints={@UniqueConstraint(name="name_idx", columns={"name"}, fields={"email"})},
 * )
 */
class UserIncorrectUniqueConstraint
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     **/
    public $id;

    /**
     * @var string
     * @Column
     */
    public $name;

    /**
     * @var string
     * @Column(name="user_email")
     */
    public $email;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadataInfo::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable([]);
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
                'type' => 'integer',
                'columnName' => 'id',
            ]
        );
        $metadata->mapField(
            [
                'fieldName' => 'name',
                'type' => 'string',
            ]
        );
        $metadata->mapField(
            [
                'fieldName' => 'email',
                'type' => 'string',
                'columnName' => 'user_email',
            ]
        );
        $metadata->table['uniqueConstraints'] = [
            'name_idx' => ['columns' => ['name'], 'fields' => ['email']],
        ];
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({"cat" = "Cat", "dog" = "Dog"})
 * @DiscriminatorColumn(name="discr", length=32, type="string")
 */
#[ORM\Entity, ORM\InheritanceType('SINGLE_TABLE'), ORM\DiscriminatorColumn(name: 'discr', length: 32, type: 'string')]
#[ORM\DiscriminatorMap(['cat' => 'Cat', 'dog' => 'Dog'])]
abstract class Animal
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="CUSTOM")
     * @CustomIdGenerator(class="stdClass")
     */
    #[ORM\Id, ORM\Column(type: 'string'), ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'stdClass')]
    public $id;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_CUSTOM);
        $metadata->setCustomGeneratorDefinition(['class' => 'stdClass']);
    }
}

/** @Entity */
#[ORM\Entity]
class Cat extends Animal
{
    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
    }
}

/** @Entity */
#[ORM\Entity]
class Dog extends Animal
{
    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
    }
}

/**
 * @Entity
 */
#[ORM\Entity]
class DDC1170Entity
{
    public function __construct(?string $value = null)
    {
        $this->value = $value;
    }

    /**
     * @var int
     * @Id
     * @GeneratedValue(strategy="NONE")
     * @Column(type="integer", columnDefinition = "INT unsigned NOT NULL")
     **/
    #[ORM\Id, ORM\GeneratedValue(strategy: 'NONE'), ORM\Column(type: 'integer', columnDefinition: 'INT UNSIGNED NOT NULL')]
    private $id;


    /**
     * @var string|null
     * @Column(columnDefinition = "VARCHAR(255) NOT NULL")
     */
    #[ORM\Column(columnDefinition: 'VARCHAR(255) NOT NULL')]
    private $value;

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
                'columnDefinition'   => 'INT unsigned NOT NULL',
            ]
        );

        $metadata->mapField(
            [
                'fieldName'         => 'value',
                'columnDefinition'  => 'VARCHAR(255) NOT NULL',
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({"ONE" = "DDC807SubClasse1", "TWO" = "DDC807SubClasse2"})
 * @DiscriminatorColumn(name = "dtype", columnDefinition="ENUM('ONE','TWO')")
 */
#[ORM\Entity, ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'dtype', columnDefinition: "ENUM('ONE','TWO')")]
#[ORM\DiscriminatorMap(['ONE' => 'DDC807SubClasse1', 'TWO' => 'DDC807SubClasse2'])]
class DDC807Entity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="NONE")
     **/
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
         $metadata->mapField(
             [
                 'id'                 => true,
                 'fieldName'          => 'id',
             ]
         );

        $metadata->setDiscriminatorColumn(
            [
                'name'              => 'dtype',
                'type'              => 'string',
                'columnDefinition'  => "ENUM('ONE','TWO')",
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
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

/**
 * @Entity
 * @Table(indexes={@Index(columns={"content"}, flags={"fulltext"}, options={"where": "content IS NOT NULL"})})
 */
#[ORM\Entity, ORM\Table(name: 'Comment')]
#[ORM\Index(columns: ['content'], flags: ['fulltext'], options: ['where' => 'content IS NOT NULL'])]
class Comment
{
    /**
     * @var string
     * @Column(type="text")
     */
    #[ORM\Column(type: 'text')]
    private $content;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->setInheritanceType(ClassMetadataInfo::INHERITANCE_TYPE_NONE);
        $metadata->setPrimaryTable(
            [
                'indexes' => [
                    ['columns' => ['content'], 'flags' => ['fulltext'], 'options' => ['where' => 'content IS NOT NULL']],
                ],
            ]
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
            ]
        );
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({
 *     "ONE" = "SingleTableEntityNoDiscriminatorColumnMappingSub1",
 *     "TWO" = "SingleTableEntityNoDiscriminatorColumnMappingSub2"
 * })
 */
#[ORM\Entity, ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorMap(['ONE' => 'SingleTableEntityNoDiscriminatorColumnMappingSub1', 'TWO' => 'SingleTableEntityNoDiscriminatorColumnMappingSub2'])]
class SingleTableEntityNoDiscriminatorColumnMapping
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="NONE")
     */
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
    }
}

class SingleTableEntityNoDiscriminatorColumnMappingSub1 extends SingleTableEntityNoDiscriminatorColumnMapping
{
}
class SingleTableEntityNoDiscriminatorColumnMappingSub2 extends SingleTableEntityNoDiscriminatorColumnMapping
{
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({
 *     "ONE" = "SingleTableEntityIncompleteDiscriminatorColumnMappingSub1",
 *     "TWO" = "SingleTableEntityIncompleteDiscriminatorColumnMappingSub2"
 * })
 * @DiscriminatorColumn(name="dtype")
 */
#[ORM\Entity, ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorMap(['ONE' => 'SingleTableEntityNoDiscriminatorColumnMappingSub1', 'TWO' => 'SingleTableEntityNoDiscriminatorColumnMappingSub2'])]
#[ORM\DiscriminatorColumn(name: 'dtype')]
class SingleTableEntityIncompleteDiscriminatorColumnMapping
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="NONE")
     */
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'NONE')]
    public $id;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id' => true,
                'fieldName' => 'id',
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
    }
}

class SingleTableEntityIncompleteDiscriminatorColumnMappingSub1 extends SingleTableEntityIncompleteDiscriminatorColumnMapping
{
}
class SingleTableEntityIncompleteDiscriminatorColumnMappingSub2 extends SingleTableEntityIncompleteDiscriminatorColumnMapping
{
}
