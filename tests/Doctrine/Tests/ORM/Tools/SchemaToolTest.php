<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Mapping\DiscriminatorColumnMetadata;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsEmployee;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\NullDefault\NullDefaultColumn;
use Doctrine\Tests\OrmTestCase;

class SchemaToolTest extends OrmTestCase
{
    public function testAddUniqueIndexForUniqueFieldAnnotation()
    {
        $em = $this->getTestEntityManager();
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

        self::assertTrue($schema->hasTable('cms_users'), "Table cms_users should exist.");
        self::assertTrue($schema->getTable('cms_users')->columnsAreIndexed(['username']), "username column should be indexed.");
    }

    public function testAnnotationOptionsAttribute()
    {
        $em = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(TestEntityWithAnnotationOptionsAttribute::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $expected = ['foo' => 'bar', 'baz' => ['key' => 'val']];

        self::assertEquals($expected, $schema->getTable('TestEntityWithAnnotationOptionsAttribute')->getOptions(), "options annotation are passed to the tables options");
        self::assertEquals($expected, $schema->getTable('TestEntityWithAnnotationOptionsAttribute')->getColumn('test')->getCustomSchemaOptions(), "options annotation are passed to the columns customSchemaOptions");
    }

    /**
     * @group DDC-200
     */
    public function testPassColumnDefinitionToJoinColumn()
    {
        $customColumnDef = "MEDIUMINT(6) UNSIGNED NOT NULL";

        $em = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $avatar     = $em->getClassMetadata(ForumAvatar::class);
        $idProperty = $avatar->getProperty('id');

        $idProperty->setColumnDefinition($customColumnDef);

        $user    = $em->getClassMetadata(ForumUser::class);
        $classes = [$avatar, $user];
        $schema  = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('forum_users'));

        $table = $schema->getTable("forum_users");

        self::assertTrue($table->hasColumn('avatar_id'));
        self::assertEquals($customColumnDef, $table->getColumn('avatar_id')->getColumnDefinition());
    }

    /**
     * @group DDC-283
     */
    public function testPostGenerateEvents()
    {
        $listener = new GenerateSchemaEventListener();

        $em = $this->getTestEntityManager();
        $em->getEventManager()->addEventListener(
            [ToolEvents::postGenerateSchemaTable, ToolEvents::postGenerateSchema], $listener
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

        self::assertEquals(count($classes), $listener->tableCalls);
        self::assertTrue($listener->schemaCalled);
    }

    public function testNullDefaultNotAddedToCustomSchemaOptions()
    {
        $em = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $customSchemaOptions = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(NullDefaultColumn::class)])
            ->getTable('NullDefaultColumn')
            ->getColumn('nullDefault')
            ->getCustomSchemaOptions();

        self::assertSame([], $customSchemaOptions);
    }

    /**
     * @group DDC-3671
     */
    public function testSchemaHasProperIndexesFromUniqueConstraintAnnotation()
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(UniqueConstraintAnnotationModel::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('unique_constraint_annotation_table'));
        $table = $schema->getTable('unique_constraint_annotation_table');

        self::assertEquals(1, count($table->getIndexes()));
        self::assertEquals(1, count($table->getUniqueConstraints()));
        self::assertTrue($table->hasIndex('primary'));
        self::assertTrue($table->hasUniqueConstraint('uniq_hash'));
    }

    public function testRemoveUniqueIndexOverruledByPrimaryKey()
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(FirstEntity::class),
            $em->getClassMetadata(SecondEntity::class)
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('first_entity'), "Table first_entity should exist.");

        $indexes = $schema->getTable('first_entity')->getIndexes();

        self::assertCount(1, $indexes, "there should be only one index");
        self::assertTrue(current($indexes)->isPrimary(), "index should be primary");
    }

    public function testSetDiscriminatorColumnWithoutLength() : void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $metadata->setInheritanceType(InheritanceType::SINGLE_TABLE);

        $discriminatorColumn = new DiscriminatorColumnMetadata();

        $discriminatorColumn->setColumnName('discriminator');
        $discriminatorColumn->setType(Type::getType('string'));

        $metadata->setDiscriminatorColumn($discriminatorColumn);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        $this->assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        $this->assertTrue($table->hasColumn('discriminator'));
        $column = $table->getColumn('discriminator');

        $this->assertEquals(255, $column->getLength());
    }
}

/**
 * @ORM\Entity
 * @ORM\Table(options={"foo": "bar", "baz": {"key": "val"}})
 */
class TestEntityWithAnnotationOptionsAttribute
{
    /** @ORM\Id @ORM\Column */
    private $id;

    /**
     * @ORM\Column(type="string", options={"foo": "bar", "baz": {"key": "val"}})
     */
    private $test;
}

class GenerateSchemaEventListener
{
    public $tableCalls = 0;
    public $schemaCalled = false;

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs)
    {
        $this->tableCalls++;
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs)
    {
        $this->schemaCalled = true;
    }
}

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="unique_constraint_annotation_table",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uniq_hash", columns={"hash"})
 *     }
 * )
 */
class UniqueConstraintAnnotationModel
{
    /** @ORM\Id @ORM\Column */
    private $id;

    /**
     * @ORM\Column(name="hash", type="string", length=8, nullable=false, unique=true)
     */
    private $hash;
}

/**
 * @ORM\Entity
 * @ORM\Table(name="first_entity")
 */
class FirstEntity
{
    /**
     * @ORM\Id
     * @ORM\Column(name="id")
     */
    public $id;

    /**
     * @ORM\OneToOne(targetEntity="SecondEntity")
     * @ORM\JoinColumn(name="id", referencedColumnName="fist_entity_id")
     */
    public $secondEntity;

    /**
     * @ORM\Column(name="name")
     */
    public $name;
}

/**
 * @ORM\Entity
 * @ORM\Table(name="second_entity")
 */
class SecondEntity
{
    /**
     * @ORM\Id
     * @ORM\Column(name="fist_entity_id")
     */
    public $fist_entity_id;

    /**
     * @ORM\Column(name="name")
     */
    public $name;
}
