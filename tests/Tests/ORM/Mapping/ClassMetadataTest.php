<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use ArrayObject;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ChainTypedFieldMapper;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DefaultTypedFieldMapper;
use Doctrine\ORM\Mapping\DiscriminatorColumnMapping;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\JoinTableMapping;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\DbalTypes\CustomIdObject;
use Doctrine\Tests\DbalTypes\CustomIdObjectType;
use Doctrine\Tests\DbalTypes\CustomIntType;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CMS\One;
use Doctrine\Tests\Models\CMS\Three;
use Doctrine\Tests\Models\CMS\Two;
use Doctrine\Tests\Models\CMS\UserRepository;
use Doctrine\Tests\Models\Company\CompanyContract;
use Doctrine\Tests\Models\Company\CompanyContractListener;
use Doctrine\Tests\Models\Customer\CustomerType;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\DDC117\DDC117Article;
use Doctrine\Tests\Models\DDC117\DDC117ArticleDetails;
use Doctrine\Tests\Models\DDC6412\DDC6412File;
use Doctrine\Tests\Models\DDC964\DDC964Admin;
use Doctrine\Tests\Models\DDC964\DDC964Guest;
use Doctrine\Tests\Models\DirectoryTree\AbstractContentItem;
use Doctrine\Tests\Models\DirectoryTree\Directory;
use Doctrine\Tests\Models\Routing\RoutingLeg;
use Doctrine\Tests\Models\TypedProperties\Contact;
use Doctrine\Tests\Models\TypedProperties\UserTyped;
use Doctrine\Tests\Models\TypedProperties\UserTypedWithCustomTypedField;
use Doctrine\Tests\ORM\Mapping\TypedFieldMapper\CustomIntAsStringTypedFieldMapper;
use Doctrine\Tests\OrmTestCase;
use DoctrineGlobalArticle;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use ReflectionClass;
use stdClass;

use function assert;
use function count;
use function serialize;
use function str_contains;
use function str_replace;
use function strtolower;
use function strtoupper;
use function unserialize;

use const CASE_UPPER;

require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

class ClassMetadataTest extends OrmTestCase
{
    use VerifyDeprecations;

    public function testClassMetadataInstanceSerialization(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // Test initial state
        self::assertTrue(count($cm->getPropertyAccessors()) === 0);
        self::assertInstanceOf(ReflectionClass::class, $cm->reflClass);
        self::assertEquals(CmsUser::class, $cm->name);
        self::assertEquals(CmsUser::class, $cm->rootEntityName);
        self::assertEquals([], $cm->subClasses);
        self::assertEquals([], $cm->parentClasses);
        self::assertEquals(ClassMetadata::INHERITANCE_TYPE_NONE, $cm->inheritanceType);

        // Customize state
        $cm->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $cm->setSubclasses(['One', 'Two', 'Three']);
        $cm->setParentClasses(['UserParent']);
        $cm->setCustomRepositoryClass('UserRepository');
        $cm->setDiscriminatorColumn(['name' => 'disc', 'type' => 'integer']);
        $cm->mapOneToOne(['fieldName' => 'phonenumbers', 'targetEntity' => 'CmsAddress', 'mappedBy' => 'foo']);
        $cm->markReadOnly();
        $cm->mapField(['fieldName' => 'status', 'notInsertable' => true, 'generated' => ClassMetadata::GENERATED_ALWAYS]);

        self::assertTrue($cm->requiresFetchAfterChange);
        self::assertEquals(1, count($cm->associationMappings));

        $serialized = serialize($cm);
        $cm         = unserialize($serialized);
        $cm->wakeupReflection(new RuntimeReflectionService());

        // Check state
        self::assertTrue(count($cm->getPropertyAccessors()) > 0);
        self::assertEquals('Doctrine\Tests\Models\CMS', $cm->namespace);
        self::assertInstanceOf(ReflectionClass::class, $cm->reflClass);
        self::assertEquals(CmsUser::class, $cm->name);
        self::assertEquals('UserParent', $cm->rootEntityName);
        self::assertEquals([One::class, Two::class, Three::class], $cm->subClasses);
        self::assertEquals(['UserParent'], $cm->parentClasses);
        self::assertEquals(UserRepository::class, $cm->customRepositoryClassName);
        self::assertEquals(DiscriminatorColumnMapping::fromMappingArray([
            'name' => 'disc',
            'type' => 'integer',
            'fieldName' => 'disc',
        ]), $cm->discriminatorColumn);
        self::assertTrue($cm->associationMappings['phonenumbers']->isOneToOne());
        self::assertEquals(1, count($cm->associationMappings));
        $oneOneMapping = $cm->getAssociationMapping('phonenumbers');
        self::assertTrue($oneOneMapping->fetch === ClassMetadata::FETCH_LAZY);
        self::assertEquals('phonenumbers', $oneOneMapping->fieldName);
        self::assertEquals(CmsAddress::class, $oneOneMapping->targetEntity);
        self::assertTrue($cm->isReadOnly);
        self::assertTrue($cm->requiresFetchAfterChange);
    }

    public function testFieldIsNullable(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // Explicit Nullable
        $cm->mapField(['fieldName' => 'status', 'nullable' => true, 'type' => 'string', 'length' => 50]);
        self::assertTrue($cm->isNullable('status'));

        // Explicit Not Nullable
        $cm->mapField(['fieldName' => 'username', 'nullable' => false, 'type' => 'string', 'length' => 50]);
        self::assertFalse($cm->isNullable('username'));

        // Implicit Not Nullable
        $cm->mapField(['fieldName' => 'name', 'type' => 'string', 'length' => 50]);
        self::assertFalse($cm->isNullable('name'), 'By default a field should not be nullable.');
    }

    public function testFieldIsNullableByType(): void
    {
        $cm = new ClassMetadata(UserTyped::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapOneToOne(['fieldName' => 'email', 'joinColumns' => [['name' => 'email_id', 'referencedColumnName' => 'id']]]);
        self::assertEquals(CmsEmail::class, $cm->getAssociationMapping('email')->targetEntity);

        $cm->mapManyToOne(['fieldName' => 'mainEmail']);
        self::assertEquals(CmsEmail::class, $cm->getAssociationMapping('mainEmail')->targetEntity);

        $cm->mapEmbedded(['fieldName' => 'contact']);
        self::assertEquals(Contact::class, $cm->embeddedClasses['contact']->class);
    }

    public function testFieldTypeFromReflection(): void
    {
        $cm = new ClassMetadata(UserTyped::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // Integer
        $cm->mapField(['fieldName' => 'id']);
        self::assertEquals('integer', $cm->getTypeOfField('id'));

        // String
        $cm->mapField(['fieldName' => 'username', 'length' => 50]);
        self::assertEquals('string', $cm->getTypeOfField('username'));

        // DateInterval object
        $cm->mapField(['fieldName' => 'dateInterval']);
        self::assertEquals('dateinterval', $cm->getTypeOfField('dateInterval'));

        // DateTime object
        $cm->mapField(['fieldName' => 'dateTime']);
        self::assertEquals('datetime', $cm->getTypeOfField('dateTime'));

        // DateTimeImmutable object
        $cm->mapField(['fieldName' => 'dateTimeImmutable']);
        self::assertEquals('datetime_immutable', $cm->getTypeOfField('dateTimeImmutable'));

        // array as JSON
        $cm->mapField(['fieldName' => 'array']);
        self::assertEquals('json', $cm->getTypeOfField('array'));

        // bool
        $cm->mapField(['fieldName' => 'boolean']);
        self::assertEquals('boolean', $cm->getTypeOfField('boolean'));

        // float
        $cm->mapField(['fieldName' => 'float']);
        self::assertEquals('float', $cm->getTypeOfField('float'));

        $cm->mapField(['fieldName' => 'bodyHeight']);
        self::assertEquals('number', $cm->getTypeOfField('bodyHeight'));
    }

    #[TestGroup('GH10313')]
    public function testFieldTypeFromReflectionDefaultTypedFieldMapper(): void
    {
        $cm = new ClassMetadata(
            UserTypedWithCustomTypedField::class,
            null,
            new DefaultTypedFieldMapper(
                [
                    CustomIdObject::class => CustomIdObjectType::class,
                    'int' => CustomIntType::class,
                ],
            ),
        );
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'customId']);
        $cm->mapField(['fieldName' => 'customIntTypedField']);
        self::assertEquals(CustomIdObjectType::class, $cm->getTypeOfField('customId'));
        self::assertEquals(CustomIntType::class, $cm->getTypeOfField('customIntTypedField'));
    }

    #[TestGroup('GH10313')]
    public function testFieldTypeFromReflectionChainTypedFieldMapper(): void
    {
        $cm = new ClassMetadata(
            UserTyped::class,
            null,
            new ChainTypedFieldMapper(
                new CustomIntAsStringTypedFieldMapper(),
                new DefaultTypedFieldMapper(),
            ),
        );
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'id']);
        $cm->mapField(['fieldName' => 'username']);
        self::assertEquals(Types::STRING, $cm->getTypeOfField('id'));
        self::assertEquals(Types::STRING, $cm->getTypeOfField('username'));
    }

    #[TestGroup('DDC-115')]
    public function testMapAssociationInGlobalNamespace(): void
    {
        require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

        $cm = new ClassMetadata('DoctrineGlobalArticle');
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToMany(
            [
                'fieldName' => 'author',
                'targetEntity' => 'DoctrineGlobalUser',
                'joinTable' => [
                    'name' => 'bar',
                    'joinColumns' => [['name' => 'bar_id', 'referencedColumnName' => 'id']],
                    'inverseJoinColumns' => [['name' => 'baz_id', 'referencedColumnName' => 'id']],
                ],
            ],
        );

        self::assertEquals('DoctrineGlobalUser', $cm->associationMappings['author']->targetEntity);
    }

    public function testMapManyToManyJoinTableDefaults(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToMany(
            [
                'fieldName' => 'groups',
                'targetEntity' => 'CmsGroup',
            ],
        );

        $assoc = $cm->associationMappings['groups'];
        self::assertEquals(
            JoinTableMapping::fromMappingArray([
                'name' => 'cmsuser_cmsgroup',
                'joinColumns' => [['name' => 'cmsuser_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
                'inverseJoinColumns' => [['name' => 'cmsgroup_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
            ]),
            $assoc->joinTable,
        );
        self::assertTrue($assoc->isOnDeleteCascade);
    }

    public function testSerializeManyToManyJoinTableCascade(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToMany(
            [
                'fieldName' => 'groups',
                'targetEntity' => 'CmsGroup',
            ],
        );

        $assoc = $cm->associationMappings['groups'];
        $assoc = unserialize(serialize($assoc));

        self::assertTrue($assoc->isOnDeleteCascade);
    }

    #[TestGroup('DDC-115')]
    public function testSetDiscriminatorMapInGlobalNamespace(): void
    {
        require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

        $cm = new ClassMetadata('DoctrineGlobalUser');
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->setDiscriminatorMap(['descr' => 'DoctrineGlobalArticle', 'foo' => 'DoctrineGlobalUser']);

        self::assertEquals('DoctrineGlobalArticle', $cm->discriminatorMap['descr']);
        self::assertEquals('DoctrineGlobalUser', $cm->discriminatorMap['foo']);
    }

    #[TestGroup('DDC-115')]
    public function testSetSubClassesInGlobalNamespace(): void
    {
        require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

        $cm = new ClassMetadata('DoctrineGlobalUser');
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->setSubclasses(['DoctrineGlobalArticle']);

        self::assertEquals('DoctrineGlobalArticle', $cm->subClasses[0]);
    }

    #[TestGroup('DDC-268')]
    public function testSetInvalidVersionMappingThrowsException(): void
    {
        $field              = [];
        $field['fieldName'] = 'foo';
        $field['type']      = 'string';

        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $cm->setVersionMapping($field);
    }

    public function testItThrowsOnJoinColumnForInverseOneToOne(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            'Doctrine\Tests\Models\CMS\CmsUser#address is a OneToOne inverse side, which does not allow join columns.',
        );
        $cm->mapOneToOne([
            'fieldName' => 'address',
            'targetEntity' => CmsAddress::class,
            'mappedBy' => 'user',
            'joinColumns' => ['non', 'empty', 'list'],
        ]);
    }

    public function testGetSingleIdentifierFieldNameMultipleIdentifierEntityThrowsException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->isIdentifierComposite = true;

        $this->expectException(MappingException::class);
        $cm->getSingleIdentifierFieldName();
    }

    public function testGetSingleIdentifierFieldNameNoIdEntityThrowsException(): void
    {
        $cm = new ClassMetadata(DDC6412File::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $cm->getSingleIdentifierFieldName();
    }

    public function testDuplicateAssociationMappingException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $a1 = OneToManyAssociationMapping::fromMappingArray([
            'fieldName' => 'foo',
            'sourceEntity' => stdClass::class,
            'targetEntity' => stdClass::class,
            'mappedBy' => 'foo',
        ]);
        $a2 = OneToManyAssociationMapping::fromMappingArray([
            'fieldName' => 'foo',
            'sourceEntity' => stdClass::class,
            'targetEntity' => stdClass::class,
            'mappedBy' => 'foo',
        ]);

        $cm->addInheritedAssociationMapping($a1);
        $this->expectException(MappingException::class);
        $cm->addInheritedAssociationMapping($a2);
    }

    public function testDuplicateColumnNameThrowsMappingException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'name', 'columnName' => 'name']);

        $this->expectException(MappingException::class);
        $cm->mapField(['fieldName' => 'username', 'columnName' => 'name']);
    }

    public function testDuplicateColumnNameDiscriminatorColumnThrowsMappingException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'name', 'columnName' => 'name']);

        $this->expectException(MappingException::class);
        $cm->setDiscriminatorColumn(['name' => 'name']);
    }

    public function testDuplicateColumnNameDiscriminatorColumn2ThrowsMappingException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->setDiscriminatorColumn(['name' => 'name']);

        $this->expectException(MappingException::class);
        $cm->mapField(['fieldName' => 'name', 'columnName' => 'name']);
    }

    public function testDuplicateFieldAndAssociationMapping1ThrowsException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'name', 'columnName' => 'name']);

        $this->expectException(MappingException::class);
        $cm->mapOneToOne(['fieldName' => 'name', 'targetEntity' => 'CmsUser']);
    }

    public function testDuplicateFieldAndAssociationMapping2ThrowsException(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapOneToOne(['fieldName' => 'name', 'targetEntity' => 'CmsUser']);

        $this->expectException(MappingException::class);
        $cm->mapField(['fieldName' => 'name', 'columnName' => 'name']);
    }

    #[TestGroup('DDC-1224')]
    public function testGetTemporaryTableNameSchema(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->setTableName('foo.bar');

        self::assertEquals('foo_bar_id_tmp', $cm->getTemporaryIdTableName());
    }

    public function testDefaultTableName(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // When table's name is not given
        $primaryTable = [];
        $cm->setPrimaryTable($primaryTable);

        self::assertEquals('CmsUser', $cm->getTableName());
        self::assertEquals('CmsUser', $cm->table['name']);

        $cm = new ClassMetadata(CmsAddress::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        // When joinTable's name is not given
        $cm->mapManyToMany(
            [
                'fieldName' => 'user',
                'targetEntity' => 'CmsUser',
                'inversedBy' => 'users',
                'joinTable' => [
                    'joinColumns' => [['referencedColumnName' => 'id']],
                    'inverseJoinColumns' => [['referencedColumnName' => 'id']],
                ],
            ],
        );
        self::assertEquals('cmsaddress_cmsuser', $cm->associationMappings['user']->joinTable->name);
    }

    public function testDefaultJoinColumnName(): void
    {
        $cm = new ClassMetadata(CmsAddress::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        // this is really dirty, but it's the simplest way to test whether
        // joinColumn's name will be automatically set to user_id
        $cm->mapOneToOne(
            [
                'fieldName' => 'user',
                'targetEntity' => 'CmsUser',
                'joinColumns' => [['referencedColumnName' => 'id']],
            ],
        );
        self::assertEquals('user_id', $cm->associationMappings['user']->joinColumns[0]->name);

        $cm = new ClassMetadata(CmsAddress::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToMany(
            [
                'fieldName' => 'user',
                'targetEntity' => 'CmsUser',
                'inversedBy' => 'users',
                'joinTable' => [
                    'name' => 'user_CmsUser',
                    'joinColumns' => [['referencedColumnName' => 'id']],
                    'inverseJoinColumns' => [['referencedColumnName' => 'id']],
                ],
            ],
        );
        self::assertEquals('cmsaddress_id', $cm->associationMappings['user']->joinTable->joinColumns[0]->name);
        self::assertEquals('cmsuser_id', $cm->associationMappings['user']->joinTable->inverseJoinColumns[0]->name);
    }

    #[TestGroup('DDC-559')]
    public function testUnderscoreNamingStrategyDefaults(): void
    {
        $reflService        = new RuntimeReflectionService();
        $namingStrategy     = new UnderscoreNamingStrategy(CASE_UPPER);
        $oneToOneMetadata   = new ClassMetadata(CmsAddress::class, $namingStrategy);
        $manyToManyMetadata = new ClassMetadata(CmsAddress::class, $namingStrategy);
        $oneToOneMetadata->initializeReflection($reflService);
        $manyToManyMetadata->initializeReflection($reflService);

        $oneToOneMetadata->mapOneToOne(
            [
                'fieldName'     => 'user',
                'targetEntity'  => 'CmsUser',
            ],
        );

        $manyToManyMetadata->mapManyToMany(
            [
                'fieldName'     => 'user',
                'targetEntity'  => 'CmsUser',
            ],
        );

        self::assertEquals(['USER_ID' => 'ID'], $oneToOneMetadata->associationMappings['user']->sourceToTargetKeyColumns);
        self::assertEquals(['USER_ID' => 'USER_ID'], $oneToOneMetadata->associationMappings['user']->joinColumnFieldNames);
        self::assertEquals(['ID' => 'USER_ID'], $oneToOneMetadata->associationMappings['user']->targetToSourceKeyColumns);

        self::assertEquals('USER_ID', $oneToOneMetadata->associationMappings['user']->joinColumns[0]->name);
        self::assertEquals('ID', $oneToOneMetadata->associationMappings['user']->joinColumns[0]->referencedColumnName);

        self::assertEquals('CMS_ADDRESS_CMS_USER', $manyToManyMetadata->associationMappings['user']->joinTable->name);

        self::assertEquals(['CMS_ADDRESS_ID', 'CMS_USER_ID'], $manyToManyMetadata->associationMappings['user']->joinTableColumns);
        self::assertEquals(['CMS_ADDRESS_ID' => 'ID'], $manyToManyMetadata->associationMappings['user']->relationToSourceKeyColumns);
        self::assertEquals(['CMS_USER_ID' => 'ID'], $manyToManyMetadata->associationMappings['user']->relationToTargetKeyColumns);

        self::assertEquals('CMS_ADDRESS_ID', $manyToManyMetadata->associationMappings['user']->joinTable->joinColumns[0]->name);
        self::assertEquals('CMS_USER_ID', $manyToManyMetadata->associationMappings['user']->joinTable->inverseJoinColumns[0]->name);

        self::assertEquals('ID', $manyToManyMetadata->associationMappings['user']->joinTable->joinColumns[0]->referencedColumnName);
        self::assertEquals('ID', $manyToManyMetadata->associationMappings['user']->joinTable->inverseJoinColumns[0]->referencedColumnName);

        $cm = new ClassMetadata('DoctrineGlobalArticle', $namingStrategy);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToMany(['fieldName' => 'author', 'targetEntity' => CmsUser::class]);
        self::assertEquals('DOCTRINE_GLOBAL_ARTICLE_CMS_USER', $cm->associationMappings['author']->joinTable->name);
    }

    #[TestGroup('DDC-886')]
    public function testSetMultipleIdentifierSetsComposite(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'name']);
        $cm->mapField(['fieldName' => 'username']);

        $cm->setIdentifier(['name', 'username']);
        self::assertTrue($cm->isIdentifierComposite);
    }

    #[TestGroup('DDC-944')]
    public function testMappingNotFound(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("No mapping found for field 'foo' on class '" . CmsUser::class . "'.");

        $cm->getFieldMapping('foo');
    }

    #[TestGroup('DDC-961')]
    public function testJoinTableMappingDefaults(): void
    {
        $cm = new ClassMetadata('DoctrineGlobalArticle');
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapManyToMany(['fieldName' => 'author', 'targetEntity' => CmsUser::class]);

        self::assertEquals('doctrineglobalarticle_cmsuser', $cm->associationMappings['author']->joinTable->name);
    }

    #[TestGroup('DDC-117')]
    public function testMapIdentifierAssociation(): void
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapOneToOne(
            [
                'fieldName' => 'article',
                'id' => true,
                'targetEntity' => DDC117Article::class,
                'joinColumns' => [],
            ],
        );

        self::assertTrue($cm->containsForeignIdentifier, "Identifier Association should set 'containsForeignIdentifier' boolean flag.");
        self::assertEquals(['article'], $cm->identifier);
    }

    #[TestGroup('DDC-117')]
    public function testOrphanRemovalIdentifierAssociation(): void
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('The orphan removal option is not allowed on an association that');

        $cm->mapOneToOne(
            [
                'fieldName' => 'article',
                'id' => true,
                'targetEntity' => DDC117Article::class,
                'orphanRemoval' => true,
                'joinColumns' => [],
            ],
        );
    }

    #[TestGroup('DDC-117')]
    public function testInverseIdentifierAssociation(): void
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('An inverse association is not allowed to be identifier in');

        $cm->mapOneToOne(
            [
                'fieldName' => 'article',
                'id' => true,
                'mappedBy' => 'details', // INVERSE!
                'targetEntity' => DDC117Article::class,
                'joinColumns' => [],
            ],
        );
    }

    #[TestGroup('DDC-117')]
    public function testIdentifierAssociationManyToMany(): void
    {
        $cm = new ClassMetadata(DDC117ArticleDetails::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Many-to-many or one-to-many associations are not allowed to be identifier in');

        $cm->mapManyToMany(
            [
                'fieldName' => 'article',
                'id' => true,
                'targetEntity' => DDC117Article::class,
                'joinColumns' => [],
            ],
        );
    }

    #[TestGroup('DDC-996')]
    public function testEmptyFieldNameThrowsException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The field or association mapping misses the 'fieldName' attribute in entity '" . CmsUser::class . "'.");

        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => '']);
    }

    #[TestGroup('DDC-2451')]
    public function testSerializeEntityListeners(): void
    {
        $metadata = new ClassMetadata(CompanyContract::class);

        $metadata->initializeReflection(new RuntimeReflectionService());
        $metadata->addEntityListener(Events::prePersist, 'CompanyContractListener', 'prePersistHandler');
        $metadata->addEntityListener(Events::postPersist, 'CompanyContractListener', 'postPersistHandler');

        $serialize   = serialize($metadata);
        $unserialize = unserialize($serialize);

        self::assertEquals($metadata->entityListeners, $unserialize->entityListeners);
    }

    #[TestGroup('DDC-1068')]
    public function testClassCaseSensitivity(): void
    {
        $user = new CmsUser();
        $cm   = new ClassMetadata(strtoupper(CmsUser::class));
        $cm->initializeReflection(new RuntimeReflectionService());

        self::assertEquals(CmsUser::class, $cm->name);
    }

    #[TestGroup('DDC-659')]
    public function testLifecycleCallbackNotFound(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->addLifecycleCallback('notfound', 'postLoad');

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("Entity '" . CmsUser::class . "' has no method 'notfound' to be registered as lifecycle callback.");

        $cm->validateLifecycleCallbacks(new RuntimeReflectionService());
    }

    #[TestGroup('ImproveErrorMessages')]
    public function testTargetEntityNotFound(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToOne(['fieldName' => 'address', 'targetEntity' => 'UnknownClass']);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The target-entity Doctrine\\Tests\\Models\\CMS\\UnknownClass cannot be found in '" . CmsUser::class . "#address'.");

        $cm->validateAssociations();
    }

    public function testNameIsMandatoryForDiscriminatorColumnsMappingException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Discriminator column name on entity class \'Doctrine\Tests\Models\CMS\CmsUser\' is not defined.');
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->setDiscriminatorColumn([]);
    }

    #[TestGroup('DDC-984')]
    #[TestGroup('DDC-559')]
    #[TestGroup('DDC-1575')]
    public function testFullyQualifiedClassNameShouldBeGivenToNamingStrategy(): void
    {
        $namingStrategy  = new MyNamespacedNamingStrategy();
        $addressMetadata = new ClassMetadata(CmsAddress::class, $namingStrategy);
        $articleMetadata = new ClassMetadata(DoctrineGlobalArticle::class, $namingStrategy);
        $routingMetadata = new ClassMetadata(RoutingLeg::class, $namingStrategy);

        $addressMetadata->initializeReflection(new RuntimeReflectionService());
        $articleMetadata->initializeReflection(new RuntimeReflectionService());
        $routingMetadata->initializeReflection(new RuntimeReflectionService());

        $addressMetadata->mapManyToMany(
            [
                'fieldName'     => 'user',
                'targetEntity'  => 'CmsUser',
            ],
        );

        $articleMetadata->mapManyToMany(
            [
                'fieldName'     => 'author',
                'targetEntity'  => CmsUser::class,
            ],
        );

        self::assertEquals('routing_routingleg', $routingMetadata->table['name']);
        self::assertEquals('cms_cmsaddress_cms_cmsuser', $addressMetadata->associationMappings['user']->joinTable->name);
        self::assertEquals('doctrineglobalarticle_cms_cmsuser', $articleMetadata->associationMappings['author']->joinTable->name);
    }

    #[TestGroup('DDC-984')]
    #[TestGroup('DDC-559')]
    public function testFullyQualifiedClassNameShouldBeGivenToNamingStrategyPropertyToColumnName(): void
    {
        $namingStrategy = new MyPrefixNamingStrategy();
        $metadata       = new ClassMetadata(CmsAddress::class, $namingStrategy);

        $metadata->initializeReflection(new RuntimeReflectionService());

        $metadata->mapField(['fieldName' => 'country']);
        $metadata->mapField(['fieldName' => 'city']);

        self::assertEquals($metadata->fieldNames, [
            'cmsaddress_country'   => 'country',
            'cmsaddress_city'      => 'city',
        ]);
    }

    #[TestGroup('DDC-1746')]
    public function testInvalidCascade(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('You have specified invalid cascade options for ' . CmsUser::class . "::\$address: 'invalid'; available options: 'remove', 'persist', 'refresh', and 'detach'");

        $cm->mapManyToOne(['fieldName' => 'address', 'targetEntity' => 'UnknownClass', 'cascade' => ['invalid']]);
    }

    #[TestGroup('DDC-964')]
    public function testInvalidPropertyAssociationOverrideNameException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Invalid field override named \'invalidPropertyName\' for class \'Doctrine\Tests\Models\DDC964\DDC964Admin');
        $cm = new ClassMetadata(DDC964Admin::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToOne(['fieldName' => 'address', 'targetEntity' => 'DDC964Address']);

        $cm->setAssociationOverride('invalidPropertyName', []);
    }

    #[TestGroup('DDC-964')]
    public function testInvalidPropertyAttributeOverrideNameException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Invalid field override named \'invalidPropertyName\' for class \'Doctrine\Tests\Models\DDC964\DDC964Guest\'.');
        $cm = new ClassMetadata(DDC964Guest::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapField(['fieldName' => 'name']);

        $cm->setAttributeOverride('invalidPropertyName', []);
    }

    #[TestGroup('DDC-964')]
    public function testInvalidOverrideAttributeFieldTypeException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('The column type of attribute \'name\' on class \'Doctrine\Tests\Models\DDC964\DDC964Guest\' could not be changed.');
        $cm = new ClassMetadata(DDC964Guest::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapField(['fieldName' => 'name', 'type' => 'string']);

        $cm->setAttributeOverride('name', ['type' => 'date']);
    }

    public function testAttributeOverrideKeepsDeclaringClass(): void
    {
        $cm = new ClassMetadata(Directory::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapField(['fieldName' => 'id', 'type' => 'integer', 'declared' => AbstractContentItem::class]);
        $cm->setAttributeOverride('id', ['columnName' => 'new_id']);

        $mapping = $cm->getFieldMapping('id');

        self::assertSame(AbstractContentItem::class, $mapping->declared);
    }

    public function testAssociationOverrideKeepsDeclaringClass(): void
    {
        $cm = new ClassMetadata(Directory::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToOne(['fieldName' => 'parentDirectory', 'targetEntity' => Directory::class, 'cascade' => ['remove'], 'declared' => Directory::class]);
        $cm->setAssociationOverride('parentDirectory', ['cascade' => ['remove']]);

        $mapping = $cm->getAssociationMapping('parentDirectory');

        self::assertSame(Directory::class, $mapping->declared);
    }

    public function testAssociationOverrideCanOverrideCascade(): void
    {
        $cm = new ClassMetadata(Directory::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToOne(['fieldName' => 'parentDirectory', 'targetEntity' => Directory::class, 'cascade' => ['remove'], 'declared' => Directory::class]);
        $cm->setAssociationOverride('parentDirectory', ['cascade' => ['all']]);

        $mapping = $cm->getAssociationMapping('parentDirectory');
        self::assertSame(['remove', 'persist', 'refresh', 'detach'], $mapping->cascade);
    }

    #[TestGroup('DDC-1955')]
    public function testInvalidEntityListenerClassException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Entity Listener "\InvalidClassName" declared on "Doctrine\Tests\Models\CMS\CmsUser" not found.');
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addEntityListener(Events::postLoad, '\InvalidClassName', 'postLoadHandler');
    }

    #[TestGroup('DDC-1955')]
    public function testInvalidEntityListenerMethodException(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('Entity Listener "Doctrine\Tests\Models\Company\CompanyContractListener" declared on "Doctrine\Tests\Models\CMS\CmsUser" has no method "invalidMethod".');
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->addEntityListener(Events::postLoad, CompanyContractListener::class, 'invalidMethod');
    }

    public function testManyToManySelfReferencingNamingStrategyDefaults(): void
    {
        $cm = new ClassMetadata(CustomTypeParent::class);
        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->mapManyToMany(
            [
                'fieldName' => 'friendsWithMe',
                'targetEntity' => 'CustomTypeParent',
            ],
        );

        self::assertEquals(
            JoinTableMapping::fromMappingArray([
                'name' => 'customtypeparent_customtypeparent',
                'joinColumns' => [['name' => 'customtypeparent_source', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
                'inverseJoinColumns' => [['name' => 'customtypeparent_target', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE']],
            ]),
            $cm->associationMappings['friendsWithMe']->joinTable,
        );
        self::assertEquals(['customtypeparent_source', 'customtypeparent_target'], $cm->associationMappings['friendsWithMe']->joinTableColumns);
        self::assertEquals(['customtypeparent_source' => 'id'], $cm->associationMappings['friendsWithMe']->relationToSourceKeyColumns);
        self::assertEquals(['customtypeparent_target' => 'id'], $cm->associationMappings['friendsWithMe']->relationToTargetKeyColumns);
    }

    #[TestGroup('DDC-2608')]
    public function testSetSequenceGeneratorThrowsExceptionWhenSequenceNameIsMissing(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $this->expectException(MappingException::class);
        $cm->setSequenceGeneratorDefinition([]);
    }

    #[TestGroup('DDC-2662')]
    #[TestGroup('6682')]
    public function testQuotedSequenceName(): void
    {
        $cm = new ClassMetadata(CmsUser::class);

        $cm->initializeReflection(new RuntimeReflectionService());
        $cm->setSequenceGeneratorDefinition(['sequenceName' => '`foo`']);

        self::assertSame(
            ['sequenceName' => 'foo', 'quoted' => true, 'allocationSize' => '1', 'initialValue' => '1'],
            $cm->sequenceGeneratorDefinition,
        );
    }

    #[DataProvider('fullTableNameProvider')]
    public function testGetSequenceName(
        string $expectedSequenceName,
        string $fullTableName,
    ): void {
        $cm = new ClassMetadata(self::class);
        $cm->setIdentifier(['id']);
        $cm->setPrimaryTable(['name' => $fullTableName]);

        $platform = $this->createStub(AbstractPlatform::class);

        self::assertSame(
            $expectedSequenceName,
            $cm->getSequenceName($platform),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function fullTableNameProvider(): iterable
    {
        yield 'quoted table name with schema' => [
            'custom.reserved_id_seq',
            'custom.`reserved`',
        ];

        yield 'unquoted table name with schema' => [
            'custom.non_reserved_id_seq',
            'custom.non_reserved',
        ];

        yield 'quoted table name without schema' => [
            'reserved_id_seq',
            '`reserved`',
        ];

        yield 'unquoted table name without schema' => [
            'non_reserved_id_seq',
            'non_reserved',
        ];
    }

    #[TestGroup('DDC-2700')]
    public function testIsIdentifierMappedSuperClass(): void
    {
        $class = new ClassMetadata(DDC2700MappedSuperClass::class);

        self::assertFalse($class->isIdentifier('foo'));
    }

    #[TestGroup('DDC-3120')]
    public function testCanInstantiateInternalPhpClassSubclass(): void
    {
        $classMetadata = new ClassMetadata(MyArrayObjectEntity::class);

        self::assertInstanceOf(MyArrayObjectEntity::class, $classMetadata->newInstance());
    }

    #[TestGroup('DDC-3120')]
    public function testCanInstantiateInternalPhpClassSubclassFromUnserializedMetadata(): void
    {
        $classMetadata = unserialize(serialize(new ClassMetadata(MyArrayObjectEntity::class)));
        assert($classMetadata instanceof ClassMetadata);

        $classMetadata->wakeupReflection(new RuntimeReflectionService());

        self::assertInstanceOf(MyArrayObjectEntity::class, $classMetadata->newInstance());
    }

    public function testGetColumnNamesWithGivenFieldNames(): void
    {
        $metadata = new ClassMetadata(CmsUser::class);
        $metadata->initializeReflection(new RuntimeReflectionService());

        $metadata->mapField(['fieldName' => 'status', 'type' => 'string', 'columnName' => 'foo']);
        $metadata->mapField(['fieldName' => 'username', 'type' => 'string', 'columnName' => 'bar']);
        $metadata->mapField(['fieldName' => 'name', 'type' => 'string', 'columnName' => 'baz']);

        self::assertSame(['foo', 'baz'], $metadata->getColumnNames(['status', 'name']));
    }

    #[TestGroup('DDC-6460')]
    public function testInlineEmbeddable(): void
    {
        $classMetadata = new ClassMetadata(TestEntity1::class);

        $classMetadata->mapEmbedded(
            [
                'fieldName'    => 'test',
                'class'        => TestEntity1::class,
                'columnPrefix' => false,
            ],
        );

        self::assertTrue($classMetadata->hasField('test'));
    }

    #[TestGroup('DDC-3305')]
    public function testRejectsEmbeddableWithoutValidClassName(): void
    {
        $metadata = new ClassMetadata(TestEntity1::class);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('The embed mapping \'embedded\' misses the \'class\' attribute.');
        $metadata->mapEmbedded([
            'fieldName'    => 'embedded',
            'class'        => '',
            'columnPrefix' => false,
        ]);
    }

    public function testItAddingLifecycleCallbackOnEmbeddedClassIsIllegal(): void
    {
        $metadata                  = new ClassMetadata(self::class);
        $metadata->isEmbeddedClass = true;

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(<<<'EXCEPTION'
            Context: Attempt to register lifecycle callback "foo" on embedded class "Doctrine\Tests\ORM\Mapping\ClassMetadataTest".
            Problem: Registering lifecycle callbacks on embedded classes is not allowed.
            EXCEPTION);

        $metadata->addLifecycleCallback('foo', 'bar');
    }

    public function testItThrowsOnInvalidCallToGetAssociationMappedByTargetField(): void
    {
        $metadata = new ClassMetadata(self::class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        $metadata->mapOneToOne(['fieldName' => 'foo', 'targetEntity' => 'bar']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(<<<'EXCEPTION'
            Context: Calling Doctrine\ORM\Mapping\ClassMetadata::getAssociationMappedByTargetField() with "foo", which is the owning side of an association.
            Problem: The owning side of an association has no "mappedBy" field.
            Solution: Call Doctrine\ORM\Mapping\ClassMetadata::isAssociationInverseSide() to check first.
            EXCEPTION);

        $metadata->getAssociationMappedByTargetField('foo');
    }

    public function testClassNameMappingDiscriminatorValue(): void
    {
        $driver     = new XmlDriver(
            __DIR__ . '/xml',
            XmlDriver::DEFAULT_FILE_EXTENSION,
            true,
        );
        $xmlElement = $driver->getElement(CustomerType::class);
        self::assertEquals(
            'Doctrine\Tests\Models\Customer\InternalCustomer',
            $xmlElement->children()->{'discriminator-map'}->{'discriminator-mapping'}[0]->attributes()['value'],
        );
    }

    #[IgnoreDeprecations]
    public function testDiscriminatorMapWithSameClassMultipleTimesDeprecated(): void
    {
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/issues/3519');

        $cm = new ClassMetadata(CMS\CmsUser::class);
        $cm->setDiscriminatorMap(['foo' => CMS\CmsUser::class, 'bar' => CMS\CmsUser::class]);
    }

    public function testCompositeIdentifierOrderingByPosition(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'username', 'type' => 'string', 'id' => true, 'idPosition' => 2]);
        $cm->mapField(['fieldName' => 'name', 'type' => 'string', 'id' => true, 'idPosition' => 1]);

        self::assertSame(['name', 'username'], $cm->identifier);
    }

    public function testCompositeIdentifierSamePositionPreservesInsertionOrder(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'username', 'type' => 'string', 'id' => true, 'idPosition' => 1]);
        $cm->mapField(['fieldName' => 'name', 'type' => 'string', 'id' => true, 'idPosition' => 1]);

        self::assertSame(['username', 'name'], $cm->identifier);
    }

    public function testCompositeIdentifierDefaultPositionPreservesInsertionOrder(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'username', 'type' => 'string', 'id' => true]);
        $cm->mapField(['fieldName' => 'name', 'type' => 'string', 'id' => true]);

        self::assertSame(['username', 'name'], $cm->identifier);
    }

    public function testCompositeIdentifierPositionSurvivesSerialization(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->mapField(['fieldName' => 'username', 'type' => 'string', 'id' => true, 'idPosition' => 2]);
        $cm->mapField(['fieldName' => 'name', 'type' => 'string', 'id' => true, 'idPosition' => 1]);

        $cm = unserialize(serialize($cm));
        $cm->wakeupReflection(new RuntimeReflectionService());

        self::assertSame(['name', 'username'], $cm->identifier);
        self::assertSame(['username' => 2, 'name' => 1], $cm->identifierPositions);
    }

    public function testSetIdentifierSortsByPosition(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->setIdentifier(['username', 'name'], ['username' => 2, 'name' => 1]);

        self::assertSame(['name', 'username'], $cm->identifier);
        self::assertSame(['username' => 2, 'name' => 1], $cm->identifierPositions);
    }

    public function testSetIdentifierWithoutPositionsDefaultsToZero(): void
    {
        $cm = new ClassMetadata(CmsUser::class);
        $cm->initializeReflection(new RuntimeReflectionService());

        $cm->setIdentifier(['username', 'name']);

        self::assertSame(['username', 'name'], $cm->identifier);
        self::assertSame(['username' => 0, 'name' => 0], $cm->identifierPositions);
    }
}

#[MappedSuperclass]
class DDC2700MappedSuperClass
{
    #[Column]
    private mixed $foo;
}

class MyNamespacedNamingStrategy extends DefaultNamingStrategy
{
    public function classToTableName(string $className): string
    {
        if (str_contains($className, '\\')) {
            $className = str_replace('\\', '_', str_replace('Doctrine\Tests\Models\\', '', $className));
        }

        return strtolower($className);
    }
}

class MyPrefixNamingStrategy extends DefaultNamingStrategy
{
    public function propertyToColumnName(string $propertyName, string $className): string
    {
        return strtolower($this->classToTableName($className)) . '_' . $propertyName;
    }
}

class MyArrayObjectEntity extends ArrayObject
{
}
