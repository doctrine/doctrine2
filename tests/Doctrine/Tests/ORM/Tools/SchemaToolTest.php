<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsEmployee;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedChildClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedIdentityClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedRootClass;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\NullDefault\NullDefaultColumn;
use Doctrine\Tests\OrmTestCase;

use function count;
use function current;

class SchemaToolTest extends OrmTestCase
{
    public function testAddUniqueIndexForUniqueFieldAnnotation(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(CmsAddress::class),
            $em->getClassMetadata(CmsArticle::class),
            $em->getClassMetadata(CmsComment::class),
            $em->getClassMetadata(CmsEmployee::class),
            $em->getClassMetadata(CmsGroup::class),
            $em->getClassMetadata(CmsPhonenumber::class),
            $em->getClassMetadata(CmsUser::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('cms_users'), 'Table cms_users should exist.');
        $this->assertTrue($schema->getTable('cms_users')->columnsAreIndexed(['username']), 'username column should be indexed.');
    }

    public function testAnnotationOptionsAttribute(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata(
            [$em->getClassMetadata(TestEntityWithAnnotationOptionsAttribute::class)]
        );
        $table  = $schema->getTable('TestEntityWithAnnotationOptionsAttribute');

        foreach ([$table->getOptions(), $table->getColumn('test')->getCustomSchemaOptions()] as $options) {
            self::assertArrayHasKey('foo', $options);
            self::assertSame('bar', $options['foo']);
            self::assertArrayHasKey('baz', $options);
            self::assertSame(['key' => 'val'], $options['baz']);
        }
    }

    /**
     * @group DDC-200
     */
    public function testPassColumnDefinitionToJoinColumn(): void
    {
        $customColumnDef = 'MEDIUMINT(6) UNSIGNED NOT NULL';

        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $avatar                                          = $em->getClassMetadata(ForumAvatar::class);
        $avatar->fieldMappings['id']['columnDefinition'] = $customColumnDef;
        $user                                            = $em->getClassMetadata(ForumUser::class);

        $classes = [$avatar, $user];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('forum_users'));
        $table = $schema->getTable('forum_users');
        $this->assertTrue($table->hasColumn('avatar_id'));
        $this->assertEquals($customColumnDef, $table->getColumn('avatar_id')->getColumnDefinition());
    }

    /**
     * @group 6830
     */
    public function testPassColumnOptionsToJoinColumn(): void
    {
        $em       = $this->getTestEntityManager();
        $category = $em->getClassMetadata(GH6830Category::class);
        $board    = $em->getClassMetadata(GH6830Board::class);

        $schemaTool = new SchemaTool($em);
        $schema     = $schemaTool->getSchemaFromMetadata([$category, $board]);

        self::assertTrue($schema->hasTable('GH6830Category'));
        self::assertTrue($schema->hasTable('GH6830Board'));

        $tableCategory = $schema->getTable('GH6830Category');
        $tableBoard    = $schema->getTable('GH6830Board');

        self::assertTrue($tableBoard->hasColumn('category_id'));

        self::assertSame(
            $tableCategory->getColumn('id')->getFixed(),
            $tableBoard->getColumn('category_id')->getFixed(),
            'Foreign key/join column should have the same value of option `fixed` as the referenced column'
        );

        self::assertEquals(
            $tableCategory->getColumn('id')->getCustomSchemaOptions(),
            $tableBoard->getColumn('category_id')->getCustomSchemaOptions(),
            'Foreign key/join column should have the same custom options as the referenced column'
        );

        self::assertEquals(
            ['collation' => 'latin1_bin', 'foo' => 'bar'],
            $tableBoard->getColumn('category_id')->getCustomSchemaOptions()
        );
    }

    /**
     * @group DDC-283
     */
    public function testPostGenerateEvents(): void
    {
        $listener = new GenerateSchemaEventListener();

        $em = $this->getTestEntityManager();
        $em->getEventManager()->addEventListener(
            [ToolEvents::postGenerateSchemaTable, ToolEvents::postGenerateSchema],
            $listener
        );
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(CmsAddress::class),
            $em->getClassMetadata(CmsArticle::class),
            $em->getClassMetadata(CmsComment::class),
            $em->getClassMetadata(CmsEmployee::class),
            $em->getClassMetadata(CmsGroup::class),
            $em->getClassMetadata(CmsPhonenumber::class),
            $em->getClassMetadata(CmsUser::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertEquals(count($classes), $listener->tableCalls);
        $this->assertTrue($listener->schemaCalled);
    }

    public function testNullDefaultNotAddedToCustomSchemaOptions(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $customSchemaOptions = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(NullDefaultColumn::class)])
            ->getTable('NullDefaultColumn')
            ->getColumn('nullDefault')
            ->getCustomSchemaOptions();

        $this->assertSame([], $customSchemaOptions);
    }

    /**
     * @group DDC-3671
     */
    public function testSchemaHasProperIndexesFromUniqueConstraintAnnotation(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(UniqueConstraintAnnotationModel::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('unique_constraint_annotation_table'));
        $table = $schema->getTable('unique_constraint_annotation_table');

        $this->assertEquals(2, count($table->getIndexes()));
        $this->assertTrue($table->hasIndex('primary'));
        $this->assertTrue($table->hasIndex('uniq_hash'));
    }

    public function testRemoveUniqueIndexOverruledByPrimaryKey(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(FirstEntity::class),
            $em->getClassMetadata(SecondEntity::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('first_entity'), 'Table first_entity should exist.');

        $indexes = $schema->getTable('first_entity')->getIndexes();

        $this->assertCount(1, $indexes, 'there should be only one index');
        $this->assertTrue(current($indexes)->isPrimary(), 'index should be primary');
    }

    public function testSetDiscriminatorColumnWithoutLength(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $metadata->setDiscriminatorColumn(['name' => 'discriminator', 'type' => 'string']);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        $this->assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        $this->assertTrue($table->hasColumn('discriminator'));
        $column = $table->getColumn('discriminator');

        $this->assertEquals(255, $column->getLength());
    }

    public function testDerivedCompositeKey(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata(
            [
                $em->getClassMetadata(JoinedDerivedIdentityClass::class),
                $em->getClassMetadata(JoinedDerivedRootClass::class),
                $em->getClassMetadata(JoinedDerivedChildClass::class),
            ]
        );

        self::assertTrue($schema->hasTable('joined_derived_identity'));
        self::assertTrue($schema->hasTable('joined_derived_root'));
        self::assertTrue($schema->hasTable('joined_derived_child'));

        $rootTable = $schema->getTable('joined_derived_root');
        self::assertNotNull($rootTable->getPrimaryKey());
        self::assertSame(['keyPart1_id', 'keyPart2'], $rootTable->getPrimaryKey()->getColumns());

        $childTable = $schema->getTable('joined_derived_child');
        self::assertNotNull($childTable->getPrimaryKey());
        self::assertSame(['keyPart1_id', 'keyPart2'], $childTable->getPrimaryKey()->getColumns());

        $childTableForeignKeys = $childTable->getForeignKeys();

        self::assertCount(2, $childTableForeignKeys);

        $expectedColumns = [
            'joined_derived_identity' => [['keyPart1_id'], ['id']],
            'joined_derived_root'     => [['keyPart1_id', 'keyPart2'], ['keyPart1_id', 'keyPart2']],
        ];

        foreach ($childTableForeignKeys as $foreignKey) {
            self::assertArrayHasKey($foreignKey->getForeignTableName(), $expectedColumns);

            [$localColumns, $foreignColumns] = $expectedColumns[$foreignKey->getForeignTableName()];

            self::assertSame($localColumns, $foreignKey->getLocalColumns());
            self::assertSame($foreignColumns, $foreignKey->getForeignColumns());
        }
    }

    public function testIndexesBasedOnFields(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy());

        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(IndexByFieldEntity::class);
        $schema     = $schemaTool->getSchemaFromMetadata([$metadata]);
        $table      = $schema->getTable('field_index');

        self::assertEquals(['index', 'field_name'], $table->getIndex('index')->getColumns());
        self::assertEquals(['index', 'table'], $table->getIndex('uniq')->getColumns());
    }

    public function testIncorrectIndexesBasedOnFields(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy());

        $schemaTool    = new SchemaTool($em);
        $mappingDriver = new StaticPHPDriver([]);
        $class         = new ClassMetadata(IncorrectIndexByFieldEntity::class);

        $class->initializeReflection(new RuntimeReflectionService());
        $mappingDriver->loadMetadataForClass(IncorrectIndexByFieldEntity::class, $class);

        $this->expectException(MappingException::class);
        $schemaTool->getSchemaFromMetadata([$class]);
    }

    public function testIncorrectUniqueConstraintsBasedOnFields(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy());

        $schemaTool    = new SchemaTool($em);
        $mappingDriver = new StaticPHPDriver([]);
        $class         = new ClassMetadata(IncorrectUniqueConstraintByFieldEntity::class);

        $class->initializeReflection(new RuntimeReflectionService());
        $mappingDriver->loadMetadataForClass(IncorrectUniqueConstraintByFieldEntity::class, $class);

        $this->expectException(MappingException::class);
        $schemaTool->getSchemaFromMetadata([$class]);
    }
}

/**
 * @Entity
 * @Table(options={"foo": "bar", "baz": {"key": "val"}})
 */
class TestEntityWithAnnotationOptionsAttribute
{
    /**
     * @var int
     * @Id
     * @Column
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", options={"foo": "bar", "baz": {"key": "val"}})
     */
    private $test;
}

class GenerateSchemaEventListener
{
    /** @var int */
    public $tableCalls = 0;

    /** @var bool */
    public $schemaCalled = false;

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $this->tableCalls++;
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
    {
        $this->schemaCalled = true;
    }
}

/**
 * @Entity
 * @Table(name="unique_constraint_annotation_table", uniqueConstraints={
 *   @UniqueConstraint(name="uniq_hash", columns={"hash"})
 * })
 */
class UniqueConstraintAnnotationModel
{
    /**
     * @var int
     * @Id
     * @Column
     */
    private $id;

    /**
     * @var string
     * @Column(name="hash", type="string", length=8, nullable=false, unique=true)
     */
    private $hash;
}

/**
 * @Entity
 * @Table(name="first_entity")
 */
class FirstEntity
{
    /**
     * @var int
     * @Id
     * @Column(name="id")
     */
    public $id;

    /**
     * @var SecondEntity
     * @OneToOne(targetEntity="SecondEntity")
     * @JoinColumn(name="id", referencedColumnName="first_entity_id")
     */
    public $secondEntity;

    /**
     * @var string
     * @Column(name="name")
     */
    public $name;
}

/**
 * @Entity
 * @Table(name="second_entity")
 */
class SecondEntity
{
    /**
     * @var int
     * @Id
     * @Column(name="first_entity_id")
     */
    public $firstEntityId;

    /**
     * @var string
     * @Column(name="name")
     */
    public $name;
}

/**
 * @Entity
 */
class GH6830Board
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var GH6830Category
     * @ManyToOne(targetEntity=GH6830Category::class, inversedBy="boards")
     * @JoinColumn(name="category_id", referencedColumnName="id")
     */
    public $category;
}

/**
 * @Entity
 */
class GH6830Category
{
    /**
     * @Id
     * @Column(type="string", length=8, options={"fixed":true, "collation":"latin1_bin", "foo":"bar"})
     * @var string
     */
    public $id;

    /**
     * @psalm-var Collection<int, GH6830Board>
     * @OneToMany(targetEntity=GH6830Board::class, mappedBy="category")
     */
    public $boards;
}

/**
 * @Entity
 * @Table(
 *     name="field_index",
 *     indexes={
 *         @Index(name="index", fields={"index", "fieldName"}),
 *     },
 *     uniqueConstraints={
 *         @UniqueConstraint(name="uniq", fields={"index", "table"})
 *     }
 * )
 */
class IndexByFieldEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column
     */
    public $index;

    /**
     * @var string
     * @Column
     */
    public $table;

    /**
     * @var string
     * @Column
     */
    public $fieldName;
}

class IncorrectIndexByFieldEntity
{
    /** @var int */
    public $id;

    /** @var string */
    public $index;

    /** @var string */
    public $table;

    /** @var string */
    public $fieldName;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
            ]
        );

        $metadata->mapField(['fieldName' => 'index']);

        $metadata->mapField(['fieldName' => 'table']);

        $metadata->mapField(['fieldName' => 'fieldName']);

        $metadata->setPrimaryTable(
            [
                'indexes' => [
                    ['columns' => ['index'], 'fields' => ['fieldName']],
                ],
            ]
        );
    }
}

class IncorrectUniqueConstraintByFieldEntity
{
    /** @var int */
    public $id;

    /** @var string */
    public $index;

    /** @var string */
    public $table;

    /** @var string */
    public $fieldName;

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
            ]
        );

        $metadata->mapField(['fieldName' => 'index']);

        $metadata->mapField(['fieldName' => 'table']);

        $metadata->mapField(['fieldName' => 'fieldName']);

        $metadata->setPrimaryTable(
            [
                'uniqueConstraints' => [
                    ['columns' => ['index'], 'fields' => ['fieldName']],
                ],
            ]
        );
    }
}
