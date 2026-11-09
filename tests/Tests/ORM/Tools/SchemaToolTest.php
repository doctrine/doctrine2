<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\ForeignKeyConstraintEditor;
use Doctrine\DBAL\Schema\Index as DbalIndex;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\PrimaryKeyConstraintEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\EnumType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\InheritanceType;
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
use Doctrine\Tests\Models\CompositeIdWithPosition\CompositeIdEntity;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedChildClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedIdentityClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedRootClass;
use Doctrine\Tests\Models\Enums\Card;
use Doctrine\Tests\Models\Enums\Suit;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\GH10288\GH10288People;
use Doctrine\Tests\Models\NullDefault\NullDefaultColumn;
use Doctrine\Tests\OrmTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresMethod;

use function array_map;
use function class_exists;
use function count;
use function current;
use function enum_exists;
use function in_array;
use function method_exists;
use function strtolower;

class SchemaToolTest extends OrmTestCase
{
    use VerifyDeprecations;

    public function testAddUniqueIndexForUniqueFieldAttribute(): void
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

        self::assertTrue($schema->hasTable('cms_users'), 'Table cms_users should exist.');
        self::assertTrue(self::columnIsIndexed($schema->getTable('cms_users'), 'username'), 'username column should be indexed.');
    }

    /** Only the schema generation based on the schema editor API deprecates custom platform options. */
    private function expectSchemaEditorApiDeprecation(EntityManagerInterface $em): void
    {
        if (! $em->getConfiguration()->getUseSchemaEditorApi()) {
            return;
        }

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12733');
    }

    #[IgnoreDeprecations]
    public function testAttributeOptionsArgument(): void
    {
        $em = $this->getTestEntityManager();
        $this->expectSchemaEditorApiDeprecation($em);
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata(
            [$em->getClassMetadata(TestEntityWithAttributeOptionsArgument::class)],
        );
        $table  = $schema->getTable('TestEntityWithAttributeOptionsArgument');

        foreach ([$table->getOptions(), $table->getColumn('test')->getPlatformOptions()] as $options) {
            self::assertArrayHasKey('foo', $options);
            self::assertSame('bar', $options['foo']);
            self::assertArrayHasKey('baz', $options);
            self::assertSame(['key' => 'val'], $options['baz']);
        }
    }

    #[Group('DDC-200')]
    public function testPassColumnDefinitionToJoinColumn(): void
    {
        $customColumnDef = 'MEDIUMINT(6) UNSIGNED NOT NULL';

        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $avatar                                        = $em->getClassMetadata(ForumAvatar::class);
        $avatar->fieldMappings['id']->columnDefinition = $customColumnDef;
        $user                                          = $em->getClassMetadata(ForumUser::class);

        $classes = [$avatar, $user];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('forum_users'));
        $table = $schema->getTable('forum_users');
        self::assertTrue($table->hasColumn('avatar_id'));
        self::assertEquals($customColumnDef, $table->getColumn('avatar_id')->getColumnDefinition());
    }

    #[Group('6830')]
    #[IgnoreDeprecations]
    public function testPassColumnOptionsToJoinColumn(): void
    {
        $em = $this->getTestEntityManager();
        $this->expectSchemaEditorApiDeprecation($em);
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
            'Foreign key/join column should have the same value of option `fixed` as the referenced column',
        );

        self::assertEquals(
            $tableCategory->getColumn('id')->getPlatformOptions(),
            $tableBoard->getColumn('category_id')->getPlatformOptions(),
            'Foreign key/join column should have the same custom options as the referenced column',
        );

        self::assertEquals(
            ['collation' => 'latin1_bin', 'foo' => 'bar'],
            $tableBoard->getColumn('category_id')->getPlatformOptions(),
        );
    }

    #[Group('DDC-283')]
    public function testPostGenerateEvents(): void
    {
        $listener = new GenerateSchemaEventListener();

        $em = $this->getTestEntityManager();
        $em->getEventManager()->addEventListener(
            [ToolEvents::postGenerateSchemaTable, ToolEvents::postGenerateSchema],
            $listener,
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
        self::assertSame(1, $listener->schemaCalls);
    }

    public function testNullDefaultNotAddedToPlatformOptions(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        self::assertSame([], $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(NullDefaultColumn::class)])
            ->getTable('NullDefaultColumn')
            ->getColumn('nullDefault')
            ->getPlatformOptions());
    }

    public function testEnumTypeAddedToCustomSchemaOptions(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $platformOptions = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(Card::class)])
            ->getTable('Card')
            ->getColumn('suit')
            ->getPlatformOptions();

        self::assertArrayHasKey('enumType', $platformOptions);
        self::assertSame(Suit::class, $platformOptions['enumType']);
    }

    #[Group('DDC-3671')]
    public function testSchemaHasProperIndexesFromUniqueConstraintAttribute(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(UniqueConstraintAttributeModel::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('unique_constraint_attribute_table'));
        $table = $schema->getTable('unique_constraint_attribute_table');

        self::assertCount(2, $table->getIndexes());
        self::assertTrue($table->hasIndex('primary'));
        self::assertTrue($table->hasIndex('uniq_hash'));
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

        self::assertTrue($schema->hasTable('first_entity'), 'Table first_entity should exist.');

        $table = $schema->getTable('first_entity');

        self::assertTrue($table->hasIndex('primary'), 'Table should have a primary key.');

        $primaryKey = $table->getIndex('primary');
        $indexes    = $table->getIndexes();

        self::assertCount(1, $indexes, 'there should be only one index');
        self::assertSame($primaryKey, current($indexes), 'index should be primary');
    }

    public function testScalarUniqueIdentifierProducesOnlyPrimaryKey(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(UniqueScalarIdEntity::class)]);

        $indexes = $schema->getTable('unique_scalar_id')->getIndexes();

        self::assertCount(1, $indexes, 'there should be only one index');
        self::assertTrue($schema->getTable('unique_scalar_id')->hasIndex('primary'));
    }

    public function testSetDiscriminatorColumnWithoutLength(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $metadata->setDiscriminatorColumn(['name' => 'discriminator', 'type' => 'string']);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        self::assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        self::assertTrue($table->hasColumn('discriminator'));
        $column = $table->getColumn('discriminator');

        self::assertEquals(255, $column->getLength());
    }

    public function testSetDefaultStringLength(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setDefaultStringTypeSchemaLength(191);
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        self::assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        self::assertTrue($table->hasColumn('name'));
        $column = $table->getColumn('name');

        self::assertEquals(191, $column->getLength());
    }

    public function testSetDefaultStringLengthDoesNotOverrideExplicitLength(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setDefaultStringTypeSchemaLength(191);
        $schemaTool                              = new SchemaTool($em);
        $metadata                                = $em->getClassMetadata(FirstEntity::class);
        $metadata->fieldMappings['name']->length = 32;

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        self::assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        self::assertTrue($table->hasColumn('name'));
        $column = $table->getColumn('name');

        self::assertEquals(32, $column->getLength());
    }

    public function testSetDiscriminatorColumnWithCustomDefaultLength(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setDefaultStringTypeSchemaLength(191);
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $metadata->setDiscriminatorColumn(['name' => 'discriminator', 'type' => 'string']);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        self::assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        self::assertTrue($table->hasColumn('discriminator'));
        $column = $table->getColumn('discriminator');

        self::assertEquals(191, $column->getLength());
    }

    public function testSetDiscriminatorColumnWithEnumType(): void
    {
        if (! class_exists(EnumType::class)) {
            self::markTestSkipped('Test valid for doctrine/dbal versions with EnumType only.');
        }

        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $metadata->setDiscriminatorColumn([
            'name' => 'discriminator',
            'type' => Types::ENUM,
            'enumType' => GH10288People::class,
        ]);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        self::assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        self::assertTrue($table->hasColumn('discriminator'));
        $column = $table->getColumn('discriminator');
        self::assertEquals(GH10288People::class, $column->getPlatformOption('enumType'));
        self::assertEquals([0 => 'boss', 1 => 'employee'], $column->getValues());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage("The entries 'user' in the discriminator map of class '" . FirstEntity::class . "' do not correspond to enum cases of '" . GH10288People::class . "'.");
        $metadata->setDiscriminatorMap(['user' => CmsUser::class, 'employee' => CmsEmployee::class]);
    }

    #[Group('GH-12606')]
    public function testConflictingSingleTableInheritanceAssociationIndexesAreAddedOnce(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([
            $em->getClassMetadata(GH12606Media::class),
            $em->getClassMetadata(GH12606Housing::class),
            $em->getClassMetadata(GH12606Location::class),
            $em->getClassMetadata(GH12606Person::class),
        ]);

        $table = $schema->getTable('gh12606_media');

        self::assertTrue(self::columnIsIndexed($table, 'reference_id'));
    }

    #[Group('GH-12609')]
    public function testConflictingSingleTableInheritanceAssociationsTargetingSameEntityStillGenerateForeignKey(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([
            $em->getClassMetadata(GH12609Base::class),
            $em->getClassMetadata(GH12609Ref::class),
        ]);

        // GH12609ChildOne and GH12609ChildThree both map a "ref_id" column to
        // GH12609Ref, but declare conflicting JoinColumn configuration (nullable:
        // false vs. nullable: true). There is no single foreign key definition that
        // could honor both associations at once, so - exactly as before the two-pass
        // rewrite introduced in GH-12528 - the association registered first
        // (GH12609ChildOne, which declares the column as NOT NULL) wins. What matters
        // for this regression test is that a foreign key is generated at all, instead
        // of being silently dropped as it was on 3.7.0.
        $table = $schema->getTable('gh12609_base');
        self::assertTrue($table->hasColumn('ref_id'));
        self::assertTrue($table->getColumn('ref_id')->getNotnull());
        self::assertCount(1, $table->getForeignKeys());
    }

    #[Group('GH-12609')]
    public function testIdenticalSingleTableInheritanceAssociationsShareOneForeignKey(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([
            $em->getClassMetadata(GH12609IdenticalBase::class),
            $em->getClassMetadata(GH12609IdenticalRef::class),
        ]);

        $table = $schema->getTable('gh12609_identical_base');

        self::assertTrue($table->hasColumn('ref_id'));
        self::assertCount(1, $table->getForeignKeys());
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
            ],
        );

        self::assertTrue($schema->hasTable('joined_derived_identity'));
        self::assertTrue($schema->hasTable('joined_derived_root'));
        self::assertTrue($schema->hasTable('joined_derived_child'));

        if (class_exists(PrimaryKeyConstraintEditor::class)) {
            $rootTable = $schema->getTable('joined_derived_root');
            self::assertNotNull($rootTable->getPrimaryKeyConstraint());
            self::assertSame(['keyPart1_id', 'keyPart2'], array_map(static fn (UnqualifiedName $name) => $name->toString(), $rootTable->getPrimaryKeyConstraint()->getColumnNames()));

            $childTable = $schema->getTable('joined_derived_child');
            self::assertNotNull($childTable->getPrimaryKeyConstraint());
            self::assertSame(['keyPart1_id', 'keyPart2'], array_map(static fn (UnqualifiedName $name) => $name->toString(), $childTable->getPrimaryKeyConstraint()->getColumnNames()));
        } else {
            $rootTable = $schema->getTable('joined_derived_root');
            self::assertNotNull($rootTable->getPrimaryKey());
            self::assertSame(['keyPart1_id', 'keyPart2'], self::getIndexedColumns($rootTable->getPrimaryKey()));

            $childTable = $schema->getTable('joined_derived_child');
            self::assertNotNull($childTable->getPrimaryKey());
            self::assertSame(['keyPart1_id', 'keyPart2'], self::getIndexedColumns($childTable->getPrimaryKey()));
        }

        $childTableForeignKeys = $childTable->getForeignKeys();

        self::assertCount(2, $childTableForeignKeys);

        $expectedColumns = [
            'joined_derived_identity' => [['keyPart1_id'], ['id']],
            'joined_derived_root'     => [['keyPart1_id', 'keyPart2'], ['keyPart1_id', 'keyPart2']],
        ];

        foreach ($childTableForeignKeys as $foreignKey) {
            if (class_exists(ForeignKeyConstraintEditor::class)) {
                self::assertArrayHasKey($foreignKey->getReferencedTableName()->toString(), $expectedColumns);

                [$localColumns, $foreignColumns] = $expectedColumns[$foreignKey->getReferencedTableName()->toString()];

                self::assertSame($localColumns, array_map(static fn (UnqualifiedName $name) => $name->toString(), $foreignKey->getReferencingColumnNames()));
                self::assertSame($foreignColumns, array_map(static fn (UnqualifiedName $name) => $name->toString(), $foreignKey->getReferencedColumnNames()));
            } else {
                self::assertArrayHasKey($foreignKey->getForeignTableName(), $expectedColumns);

                [$localColumns, $foreignColumns] = $expectedColumns[$foreignKey->getForeignTableName()];

                self::assertSame($localColumns, $foreignKey->getLocalColumns());
                self::assertSame($foreignColumns, $foreignKey->getForeignColumns());
            }
        }
    }

    /**
     * @param list<class-string> $classNames
     * @param list<string>       $childTableNames
     */
    #[DataProvider('provideJoinedHierarchiesWithAssociationAsIdentifier')]
    #[Group('GH-12651')]
    public function testJoinedChildTableForeignKeyReferencesRootTableWhenIdentifierIsAnAssociation(
        array $classNames,
        string $rootTableName,
        string|null $rootTableOnDelete,
        array $childTableNames,
    ): void {
        // The inherited identifier association and the FK to the root table share the same
        // local columns, and thus the same generated name. Only one of them must be added.
        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/dbal/pull/7125');

        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata(array_map(
            static fn (string $className): ClassMetadata => $em->getClassMetadata($className),
            $classNames,
        ));

        self::assertTableHasSingleForeignKey(
            $schema->getTable($rootTableName),
            ['target_id'],
            'gh12651_target',
            ['id'],
            $rootTableOnDelete,
        );

        foreach ($childTableNames as $childTableName) {
            // JoinedSubclassPersister::delete() only deletes the root row and relies on this
            // ON DELETE CASCADE constraint to delete the rows of the child tables.
            self::assertTableHasSingleForeignKey(
                $schema->getTable($childTableName),
                ['target_id'],
                $rootTableName,
                ['target_id'],
                'CASCADE',
            );
        }
    }

    /** @return iterable<string, array{list<class-string>, string, string|null, list<string>}> */
    public static function provideJoinedHierarchiesWithAssociationAsIdentifier(): iterable
    {
        yield 'one-to-one identifier' => [
            [GH12651Target::class, GH12651Root::class, GH12651Child::class],
            'gh12651_root',
            'CASCADE',
            ['gh12651_child'],
        ];

        yield 'many-to-one identifier' => [
            [GH12651Target::class, GH12651ManyToOneRoot::class, GH12651ManyToOneChild::class],
            'gh12651_many_to_one_root',
            'CASCADE',
            ['gh12651_many_to_one_child'],
        ];

        yield 'identifier without onDelete' => [
            [GH12651Target::class, GH12651NoOnDeleteRoot::class, GH12651NoOnDeleteChild::class],
            'gh12651_no_on_delete_root',
            null,
            ['gh12651_no_on_delete_child'],
        ];

        // Like any other JOINED child table, the leaf table references the root table, not its parent table.
        yield 'three-level hierarchy' => [
            [
                GH12651Target::class,
                GH12651ThreeLevelRoot::class,
                GH12651ThreeLevelMiddle::class,
                GH12651ThreeLevelLeaf::class,
            ],
            'gh12651_three_level_root',
            'CASCADE',
            ['gh12651_three_level_middle', 'gh12651_three_level_leaf'],
        ];
    }

    public function testIndexesBasedOnFields(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setNamingStrategy(new UnderscoreNamingStrategy());

        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(IndexByFieldEntity::class);
        $schema     = $schemaTool->getSchemaFromMetadata([$metadata]);
        $table      = $schema->getTable('field_index');

        self::assertEquals(['index', 'field_name'], self::getIndexedColumns($table->getIndex('index')));
        self::assertEquals(['index', 'table'], self::getIndexedColumns($table->getIndex('uniq')));
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

    #[Group('schema-configuration')]
    public function testConfigurationSchemaIgnoredEntity(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(FirstEntity::class),
            $em->getClassMetadata(SecondEntity::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('first_entity'), 'Table first_entity should exist.');
        self::assertTrue($schema->hasTable('second_entity'), 'Table second_entity should exist.');

        $em->getConfiguration()->setSchemaIgnoreClasses([
            SecondEntity::class,
        ]);

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('first_entity'), 'Table first_entity should exist.');
        self::assertFalse($schema->hasTable('second_entity'), 'Table second_entity should not exist.');
    }

    #[Group('GH-11314')]
    public function testLoadUniqueConstraintWithoutName(): void
    {
        $em     = $this->getTestEntityManager();
        $entity = $em->getClassMetadata(GH11314Entity::class);

        $schemaTool = new SchemaTool($em);
        $schema     = $schemaTool->getSchemaFromMetadata([$entity]);

        self::assertTrue($schema->hasTable('GH11314Entity'));

        $tableEntity = $schema->getTable('GH11314Entity');

        self::assertTrue($tableEntity->hasIndex('uniq_2d81a3ed5bf54558875f7fd5'));

        $tableIndex = $tableEntity->getIndex('uniq_2d81a3ed5bf54558875f7fd5');

        if (enum_exists(IndexType::class)) {
            self::assertSame(IndexType::UNIQUE, $tableIndex->getType());
        } else {
            self::assertTrue($tableIndex->isUnique());
        }

        self::assertSame(['field', 'anotherField'], self::getIndexedColumns($tableIndex));
    }

    #[DataProvider('platformsProvider')]
    public function testQuotedIdentifiersOnPlatform(AbstractPlatform $platform): void
    {
        $em = $this->createTestEntityManagerWithPlatform($platform);

        if (! $em->getConfiguration()->getUseSchemaEditorApi()) {
            self::markTestSkipped('Requires the schema editor API.');
        }

        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(QuotedEntity::class)]);

        $table = $schema->getTable('quoted-table');

        $objectName = $table->getObjectName();
        self::assertTrue($objectName->getUnqualifiedName()->isQuoted());
        self::assertSame('quoted-table', $objectName->getUnqualifiedName()->getValue());

        $primaryKey = $table->getPrimaryKeyConstraint();
        self::assertNotNull($primaryKey);

        [$pkColumn] = $primaryKey->getColumnNames();
        self::assertTrue($pkColumn->getIdentifier()->isQuoted());
        self::assertSame('quoted-id', $pkColumn->getIdentifier()->getValue());
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platformsProvider(): iterable
    {
        yield 'MySQL (backticks)' => [new MySQLPlatform()];
        yield 'SQL Server (square brackets)' => [new SQLServerPlatform()];
    }

    public function testQuotedIdentifiers(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [$em->getClassMetadata(QuotedEntity::class)];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        self::assertTrue($schema->hasTable('quoted-table'), 'Table quoted-table should exist.');

        $table = $schema->getTable('quoted-table');

        $foundIndex = false;
        if (class_exists(TableEditor::class)) {
            foreach ($table->getIndexes() as $index) {
                foreach ($index->getIndexedColumns() as $column) {
                    if (
                        $column->getColumnName()->getIdentifier()->equals(
                            Identifier::quoted('quoted-name'),
                            UnquotedIdentifierFolding::NONE,
                        )
                    ) {
                        $foundIndex = true;
                        break 2;
                    }
                }
            }
        } elseif ($table->hasIndex('IDX_AA2790FB50D14D90')) {
            $foundIndex = true;
        }

        self::assertTrue($foundIndex, 'Index on quoted-name should exist.');

        // DBAL < 4.3
        if (! class_exists(PrimaryKeyConstraint::class)) {
            self::assertTrue($table->isQuoted(), 'The table name must be quoted.');
            self::assertTrue($table->hasIndex('primary'), 'Table should have a primary key.');

            return;
        }

        $objectName = $table->getObjectName();
        self::assertTrue($objectName->getUnqualifiedName()->isQuoted());
        self::assertSame('quoted-table', $objectName->getUnqualifiedName()->getValue());

        $primaryKey = $table->getPrimaryKeyConstraint();
        self::assertNotNull($primaryKey);
        self::assertCount(1, $primaryKey->getColumnNames());

        [$pkColumn] = $primaryKey->getColumnNames();
        self::assertTrue($pkColumn->getIdentifier()->isQuoted());
        self::assertSame('quoted-id', $pkColumn->getIdentifier()->getValue());
    }

    public function testCompositeIdPositionRespectedInSchema(): void
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata(
            [$em->getClassMetadata(CompositeIdEntity::class)],
        );

        $table = $schema->getTable('CompositeIdEntity');

        if (class_exists(PrimaryKeyConstraintEditor::class)) {
            $pkColumns = array_map(
                static fn (UnqualifiedName $name) => $name->toString(),
                $table->getPrimaryKeyConstraint()->getColumnNames(),
            );
        } else {
            $pkColumns = self::getIndexedColumns($table->getPrimaryKey());
        }

        self::assertSame(['first', 'second', 'third'], $pkColumns);
    }

    #[RequiresMethod(Schema::class, 'edit')]
    public function testOverwritingSchemaInListener(): void
    {
        $em = $this->getTestEntityManager();

        $em->getEventManager()->addEventListener(
            [ToolEvents::postGenerateSchemaTable, ToolEvents::postGenerateSchema],
            new class () {
                public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
                {
                    $eventArgs->setSchema(new Schema());
                }

                public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
                {
                    $eventArgs->setSchema(new Schema());
                }
            },
        );
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(CmsAddress::class)]);

        self::assertFalse(
            $schema->hasTable('cms_address'),
            'The original schema should have been overwritten by the listener, so cms_address table should not exist.',
        );
    }

    #[Group('GH-5593')]
    public function testFulltextIndexIsPreservedAlongsideUniqueConstraint(): void
    {
        $table = $this->getSchemaTableForEntity(GH5593FulltextWithUniqueConstraint::class);

        self::assertTrue($table->hasIndex('search_idx'));
        self::assertTrue($table->hasIndex('uniq_title'));

        $fulltextIndex = $table->getIndex('search_idx');
        $uniqueIndex   = $table->getIndex('uniq_title');

        self::assertSame(['title'], self::getIndexedColumns($fulltextIndex));
        self::assertSame(['title'], self::getIndexedColumns($uniqueIndex));
        self::assertIndexIsFulltext($fulltextIndex);
        self::assertIndexIsUnique($uniqueIndex);
        self::assertFalse(self::indexIsUnique($fulltextIndex));
    }

    #[Group('GH-5593')]
    public function testFulltextIndexIsPreservedAlongsideColumnLevelUnique(): void
    {
        $table = $this->getSchemaTableForEntity(GH5593FulltextWithColumnUnique::class);

        self::assertTrue($table->hasIndex('search_idx'));
        self::assertIndexIsFulltext($table->getIndex('search_idx'));
        self::assertSame(['title'], self::getIndexedColumns($table->getIndex('search_idx')));

        $uniqueIndex = self::findUniqueNonPrimaryIndex($table);
        self::assertNotNull($uniqueIndex, 'Column-level unique mapping should produce a unique index.');
        self::assertIndexIsUnique($uniqueIndex);
        self::assertSame(['title'], self::getIndexedColumns($uniqueIndex));
        self::assertFalse(self::indexIsUnique($table->getIndex('search_idx')));
    }

    #[Group('GH-5593')]
    public function testCompositeFulltextAndUniqueIndexesOnSameColumnsArePreserved(): void
    {
        $table = $this->getSchemaTableForEntity(GH5593CompositeFulltextAndUnique::class);

        self::assertTrue($table->hasIndex('fulltext_ab'));
        self::assertTrue($table->hasIndex('fulltext_c'));
        self::assertTrue($table->hasIndex('uniq_ab'));
        self::assertTrue($table->hasIndex('uniq_ac'));

        self::assertSame(['a', 'b'], self::getIndexedColumns($table->getIndex('fulltext_ab')));
        self::assertSame(['c'], self::getIndexedColumns($table->getIndex('fulltext_c')));
        self::assertSame(['a', 'b'], self::getIndexedColumns($table->getIndex('uniq_ab')));
        self::assertSame(['a', 'c'], self::getIndexedColumns($table->getIndex('uniq_ac')));

        self::assertIndexIsFulltext($table->getIndex('fulltext_ab'));
        self::assertIndexIsFulltext($table->getIndex('fulltext_c'));
        self::assertIndexIsUnique($table->getIndex('uniq_ab'));
        self::assertIndexIsUnique($table->getIndex('uniq_ac'));
    }

    #[Group('GH-5593')]
    public function testUnflaggedIndexCoveredByUniqueConstraintIsDeduplicated(): void
    {
        $table = $this->getSchemaTableForEntity(GH5593UnflaggedIndexWithUniqueConstraint::class);

        self::assertFalse($table->hasIndex('idx_name'));
        self::assertTrue($table->hasIndex('uniq_name'));
        self::assertTrue($table->hasIndex('idx_other'));

        self::assertIndexIsUnique($table->getIndex('uniq_name'));
        self::assertSame(['name'], self::getIndexedColumns($table->getIndex('uniq_name')));
        self::assertSame(['other'], self::getIndexedColumns($table->getIndex('idx_other')));
        self::assertFalse(self::indexIsUnique($table->getIndex('idx_other')));
        self::assertFalse(self::indexIsFulltext($table->getIndex('idx_other')));
    }

    public function testIndexFlagsAreApplied(): void
    {
        $table = $this->getSchemaTableForEntity(GH5593FlaggedIndexes::class);

        self::assertTrue($table->hasIndex('fulltext_title'));
        self::assertTrue($table->hasIndex('spatial_title'));
        self::assertTrue($table->hasIndex('clustered_title'));

        self::assertIndexIsFulltext($table->getIndex('fulltext_title'));
        self::assertIndexIsSpatial($table->getIndex('spatial_title'));
        self::assertTrue(self::indexIsClustered($table->getIndex('clustered_title')), 'Index should be clustered.');
        self::assertFalse(self::indexIsClustered($table->getIndex('fulltext_title')), 'Index should not be clustered.');
    }

    #[Group('GH-5593')]
    public function testPartialIndexOptionsStillPreventDeduplication(): void
    {
        $table = $this->getSchemaTableForEntity(GH5593PartialIndexWithUniqueConstraint::class);

        self::assertTrue($table->hasIndex('idx_partial'));
        self::assertFalse($table->hasIndex('idx_covered_partial'));
        self::assertTrue($table->hasIndex('uniq_name'));
        self::assertTrue($table->hasIndex('uniq_code'));

        self::assertSame(['name'], self::getIndexedColumns($table->getIndex('idx_partial')));
        self::assertSame(['name'], self::getIndexedColumns($table->getIndex('uniq_name')));
        self::assertSame(['code'], self::getIndexedColumns($table->getIndex('uniq_code')));
        self::assertSame('name IS NOT NULL', self::getIndexPredicate($table->getIndex('idx_partial')));
        self::assertSame('code IS NOT NULL', self::getIndexPredicate($table->getIndex('uniq_code')));
        self::assertNull(self::getIndexPredicate($table->getIndex('uniq_name')));
    }

    #[RequiresMethod(Schema::class, 'edit')]
    public function testConflictingIndexTypeFlagsThrow(): void
    {
        if (! $this->getTestEntityManager()->getConfiguration()->getUseSchemaEditorApi()) {
            self::markTestSkipped('Requires the schema editor API.');
        }

        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An index cannot be both fulltext and spatial.');

        $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(FulltextSpatialFlagIndex::class)]);
    }

    #[RequiresMethod(Schema::class, 'edit')]
    #[DataProvider('provideNonUniqueIndexFlags')]
    public function testUniqueConstraintWithIncompatibleFlagThrows(string $flag): void
    {
        $em = $this->getTestEntityManager();

        if (! $em->getConfiguration()->getUseSchemaEditorApi()) {
            self::markTestSkipped('Requires the schema editor API.');
        }

        $metadata = $em->getClassMetadata(FulltextSpatialFlagIndex::class);

        $metadata->table['indexes']           = [];
        $metadata->table['uniqueConstraints'] = ['uniq_title' => ['columns' => ['title'], 'flags' => [$flag]]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A unique index cannot be fulltext or spatial.');

        (new SchemaTool($em))->getSchemaFromMetadata([$metadata]);
    }

    /** @return iterable<string, array{string}> */
    public static function provideNonUniqueIndexFlags(): iterable
    {
        yield 'fulltext' => ['fulltext'];
        yield 'spatial' => ['spatial'];
    }

    #[RequiresMethod(Schema::class, 'edit')]
    public function testOverwritingTableInListener(): void
    {
        $em = $this->getTestEntityManager();

        $em->getEventManager()->addEventListener(
            [ToolEvents::postGenerateSchemaTable],
            new class () {
                public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
                {
                    $eventArgs->setClassTable(new DbalTable('just_address'));
                }
            },
        );
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(CmsAddress::class)]);
        self::assertTrue(
            $schema->hasTable('just_address'),
            'The original table should have been overwritten by the listener, so just_address table should exist.',
        );
        self::assertFalse(
            $schema->hasTable('cms_address'),
            'The original table should have been overwritten by the listener, so cms_address table should not exist.',
        );
    }

    #[RequiresMethod(Schema::class, 'edit')]
    #[IgnoreDeprecations]
    public function testNotUsingSchemaEditorApiIsDeprecated(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setUseSchemaEditorApi(false);

        // Take the expectation after disabling the API, so that this only verifies
        // the deprecation triggered when the SchemaTool is instantiated.
        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12780');

        new SchemaTool($em);
    }

    #[RequiresMethod(Schema::class, 'edit')]
    public function testUsingSchemaEditorApiIsNotDeprecated(): void
    {
        $em = $this->getTestEntityManager();
        $em->getConfiguration()->setUseSchemaEditorApi(true);

        $this->expectNoDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/12780');

        new SchemaTool($em);
    }

    private function getSchemaTableForEntity(string $className): DbalTable
    {
        $em         = $this->getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $schema     = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata($className)]);
        $tables     = $schema->getTables();

        self::assertCount(1, $tables);

        return current($tables);
    }

    /**
     * @param list<string> $localColumns
     * @param list<string> $foreignColumns
     */
    private static function assertTableHasSingleForeignKey(
        DbalTable $table,
        array $localColumns,
        string $foreignTableName,
        array $foreignColumns,
        string|null $onDelete,
    ): void {
        $foreignKeys = $table->getForeignKeys();

        self::assertCount(1, $foreignKeys);

        $foreignKey = current($foreignKeys);

        if (class_exists(ForeignKeyConstraintEditor::class)) {
            self::assertSame($foreignTableName, $foreignKey->getReferencedTableName()->toString());
            self::assertSame($localColumns, array_map(static fn (UnqualifiedName $name) => $name->toString(), $foreignKey->getReferencingColumnNames()));
            self::assertSame($foreignColumns, array_map(static fn (UnqualifiedName $name) => $name->toString(), $foreignKey->getReferencedColumnNames()));

            $onDeleteAction = $foreignKey->getOnDeleteAction();
            self::assertSame($onDelete, $onDeleteAction === ReferentialAction::NO_ACTION ? null : $onDeleteAction->value);
        } else {
            self::assertSame($foreignTableName, $foreignKey->getForeignTableName());
            self::assertSame($localColumns, $foreignKey->getLocalColumns());
            self::assertSame($foreignColumns, $foreignKey->getForeignColumns());
            self::assertSame($onDelete, $foreignKey->onDelete());
        }
    }

    /** @return string[] */
    private static function getIndexedColumns(DbalIndex $index): array
    {
        if (method_exists(DbalIndex::class, 'getIndexedColumns')) {
            return array_map(static fn (IndexedColumn $indexedColumn) => $indexedColumn->getColumnName()->toString(), $index->getIndexedColumns());
        }

        return $index->getColumns();
    }

    private static function columnIsIndexed(DbalTable $table, string $column): bool
    {
        foreach ($table->getIndexes() as $index) {
            if ($index->spansColumns([$column])) {
                return true;
            }
        }

        return false;
    }

    private static function assertIndexIsUnique(DbalIndex $index): void
    {
        self::assertTrue(self::indexIsUnique($index), 'Index should be unique.');
    }

    private static function assertIndexIsFulltext(DbalIndex $index): void
    {
        self::assertTrue(self::indexIsFulltext($index), 'Index should be fulltext.');
    }

    private static function assertIndexIsSpatial(DbalIndex $index): void
    {
        self::assertTrue(self::indexIsSpatial($index), 'Index should be spatial.');
    }

    private static function indexIsUnique(DbalIndex $index): bool
    {
        if (enum_exists(IndexType::class)) {
            return $index->getType() === IndexType::UNIQUE;
        }

        return $index->isUnique();
    }

    private static function indexIsFulltext(DbalIndex $index): bool
    {
        if (enum_exists(IndexType::class)) {
            return $index->getType() === IndexType::FULLTEXT;
        }

        return in_array('fulltext', $index->getFlags(), true);
    }

    private static function indexIsSpatial(DbalIndex $index): bool
    {
        if (enum_exists(IndexType::class)) {
            return $index->getType() === IndexType::SPATIAL;
        }

        return in_array('spatial', $index->getFlags(), true);
    }

    private static function indexIsClustered(DbalIndex $index): bool
    {
        if (method_exists(DbalIndex::class, 'isClustered')) {
            return $index->isClustered();
        }

        return in_array('clustered', $index->getFlags(), true);
    }

    private static function findUniqueNonPrimaryIndex(DbalTable $table): DbalIndex|null
    {
        foreach ($table->getIndexes() as $indexName => $index) {
            if (strtolower((string) $indexName) === 'primary') {
                continue;
            }

            if (self::indexIsUnique($index)) {
                return $index;
            }
        }

        return null;
    }

    private static function getIndexPredicate(DbalIndex $index): string|null
    {
        if (method_exists($index, 'getPredicate')) {
            return $index->getPredicate();
        }

        return $index->hasOption('where') ? $index->getOption('where') : null;
    }
}

#[Table(options: ['foo' => 'bar', 'baz' => ['key' => 'val']])]
#[Entity]
class TestEntityWithAttributeOptionsArgument
{
    #[Id]
    #[Column]
    private int $id;

    #[Column(type: 'string', options: ['foo' => 'bar', 'baz' => ['key' => 'val']])]
    private string $test;
}

#[Entity]
class GH12606Housing
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
class GH12606Location
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
class GH12606Person
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
#[Table(name: 'gh12606_media')]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorMap([
    'housing' => GH12606HousingImage::class,
    'location' => GH12606LocationImage::class,
    'person' => GH12606PersonImage::class,
])]
class GH12606Media
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
class GH12606HousingImage extends GH12606Media
{
    #[ManyToOne(targetEntity: GH12606Housing::class)]
    #[JoinColumn(name: 'reference_id')]
    private GH12606Housing $housing;
}

#[Entity]
class GH12606LocationImage extends GH12606Media
{
    #[ManyToOne(targetEntity: GH12606Location::class)]
    #[JoinColumn(name: 'reference_id')]
    private GH12606Location $location;
}

#[Entity]
class GH12606PersonImage extends GH12606Media
{
    #[ManyToOne(targetEntity: GH12606Person::class)]
    #[JoinColumn(name: 'reference_id')]
    private GH12606Person $person;
}

#[Entity]
#[Table(name: 'gh12609_base')]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'dtype', type: 'string')]
#[DiscriminatorMap([
    'one' => GH12609ChildOne::class,
    'two' => GH12609ChildTwo::class,
    'three' => GH12609ChildThree::class,
])]
class GH12609Base
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
class GH12609ChildOne extends GH12609Base
{
    #[ManyToOne(targetEntity: GH12609Ref::class)]
    #[JoinColumn(name: 'ref_id', nullable: false)]
    private GH12609Ref $ref;
}

#[Entity]
class GH12609ChildTwo extends GH12609Base
{
}

#[Entity]
class GH12609ChildThree extends GH12609Base
{
    #[ManyToOne(targetEntity: GH12609Ref::class)]
    #[JoinColumn(name: 'ref_id', nullable: true)]
    private GH12609Ref|null $ref = null;
}

#[Entity]
class GH12609Ref
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
#[Table(name: 'gh12609_identical_base')]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(name: 'dtype', type: 'string')]
#[DiscriminatorMap([
    'one' => GH12609IdenticalChildOne::class,
    'two' => GH12609IdenticalChildTwo::class,
])]
class GH12609IdenticalBase
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
class GH12609IdenticalChildOne extends GH12609IdenticalBase
{
    #[ManyToOne(targetEntity: GH12609IdenticalRef::class)]
    #[JoinColumn(name: 'ref_id', nullable: false)]
    private GH12609IdenticalRef $ref;
}

#[Entity]
class GH12609IdenticalChildTwo extends GH12609IdenticalBase
{
    #[ManyToOne(targetEntity: GH12609IdenticalRef::class)]
    #[JoinColumn(name: 'ref_id', nullable: false)]
    private GH12609IdenticalRef $ref;
}

#[Entity]
class GH12609IdenticalRef
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
#[Table(name: 'gh12651_target')]
class GH12651Target
{
    #[Id]
    #[Column]
    private int $id;
}

#[Entity]
#[Table(name: 'gh12651_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'dtype', type: 'string')]
#[DiscriminatorMap(['child' => GH12651Child::class])]
abstract class GH12651Root
{
    #[Id]
    #[OneToOne(targetEntity: GH12651Target::class)]
    #[JoinColumn(name: 'target_id', onDelete: 'CASCADE')]
    private GH12651Target $target;
}

#[Entity]
#[Table(name: 'gh12651_child')]
class GH12651Child extends GH12651Root
{
}

#[Entity]
#[Table(name: 'gh12651_many_to_one_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'dtype', type: 'string')]
#[DiscriminatorMap(['child' => GH12651ManyToOneChild::class])]
abstract class GH12651ManyToOneRoot
{
    #[Id]
    #[ManyToOne(targetEntity: GH12651Target::class)]
    #[JoinColumn(name: 'target_id', onDelete: 'CASCADE')]
    private GH12651Target $target;
}

#[Entity]
#[Table(name: 'gh12651_many_to_one_child')]
class GH12651ManyToOneChild extends GH12651ManyToOneRoot
{
}

#[Entity]
#[Table(name: 'gh12651_no_on_delete_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'dtype', type: 'string')]
#[DiscriminatorMap(['child' => GH12651NoOnDeleteChild::class])]
abstract class GH12651NoOnDeleteRoot
{
    #[Id]
    #[OneToOne(targetEntity: GH12651Target::class)]
    #[JoinColumn(name: 'target_id')]
    private GH12651Target $target;
}

#[Entity]
#[Table(name: 'gh12651_no_on_delete_child')]
class GH12651NoOnDeleteChild extends GH12651NoOnDeleteRoot
{
}

#[Entity]
#[Table(name: 'gh12651_three_level_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'dtype', type: 'string')]
#[DiscriminatorMap([
    'middle' => GH12651ThreeLevelMiddle::class,
    'leaf' => GH12651ThreeLevelLeaf::class,
])]
abstract class GH12651ThreeLevelRoot
{
    #[Id]
    #[OneToOne(targetEntity: GH12651Target::class)]
    #[JoinColumn(name: 'target_id', onDelete: 'CASCADE')]
    private GH12651Target $target;
}

#[Entity]
#[Table(name: 'gh12651_three_level_middle')]
class GH12651ThreeLevelMiddle extends GH12651ThreeLevelRoot
{
}

#[Entity]
#[Table(name: 'gh12651_three_level_leaf')]
class GH12651ThreeLevelLeaf extends GH12651ThreeLevelMiddle
{
}

class GenerateSchemaEventListener
{
    /** @var int */
    public $tableCalls = 0;

    /** @var int */
    public $schemaCalls = 0;

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $this->tableCalls++;
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
    {
        $this->schemaCalls++;
    }
}

#[Table(name: 'unique_constraint_attribute_table')]
#[UniqueConstraint(name: 'uniq_hash', columns: ['hash'])]
#[Entity]
class UniqueConstraintAttributeModel
{
    #[Id]
    #[Column]
    private int $id;

    #[Column(name: 'hash', type: 'string', length: 8, nullable: false, unique: true)]
    private string $hash;
}

#[Table(name: 'first_entity')]
#[Entity]
class FirstEntity
{
    /** @var int */
    #[Id]
    #[Column(name: 'id')]
    public $id;

    /** @var SecondEntity */
    #[OneToOne(targetEntity: 'SecondEntity')]
    #[JoinColumn(name: 'id', referencedColumnName: 'first_entity_id')]
    public $secondEntity;

    /** @var string */
    #[Column(name: 'name')]
    public $name;
}

#[Table(name: 'second_entity')]
#[Entity]
class SecondEntity
{
    /** @var int */
    #[Id]
    #[Column(name: 'first_entity_id')]
    public $firstEntityId;

    /** @var string */
    #[Column(name: 'name')]
    public $name;
}

#[Entity]
class GH6830Board
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    public $id;

    /** @var GH6830Category */
    #[ManyToOne(targetEntity: GH6830Category::class, inversedBy: 'boards')]
    #[JoinColumn(name: 'category_id', referencedColumnName: 'id')]
    public $category;
}

#[Entity]
class GH6830Category
{
    /** @var string */
    #[Id]
    #[Column(type: 'string', length: 8, options: ['fixed' => true, 'collation' => 'latin1_bin', 'foo' => 'bar'])]
    public $id;

    /** @phpstan-var Collection<int, GH6830Board> */
    #[OneToMany(targetEntity: GH6830Board::class, mappedBy: 'category')]
    public $boards;
}

#[Table(name: 'field_index')]
#[Index(name: 'index', fields: ['index', 'fieldName'])]
#[UniqueConstraint(name: 'uniq', fields: ['index', 'table'])]
#[Entity]
class IndexByFieldEntity
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    public $id;

    /** @var string */
    #[Column]
    public $index;

    /** @var string */
    #[Column]
    public $table;

    /** @var string */
    #[Column]
    public $fieldName;
}

#[Entity]
#[UniqueConstraint(columns: ['field', 'anotherField'])]
class GH11314Entity
{
    #[Column]
    #[Id]
    private int $id;

    #[Column(name: 'field')]
    private string $field;

    #[Column(name: 'anotherField')]
    private string $anotherField;
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

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
            ],
        );

        $metadata->mapField(['fieldName' => 'index']);

        $metadata->mapField(['fieldName' => 'table']);

        $metadata->mapField(['fieldName' => 'fieldName']);

        $metadata->setPrimaryTable(
            [
                'indexes' => [
                    ['columns' => ['index'], 'fields' => ['fieldName']],
                ],
            ],
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

    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $metadata->mapField(
            [
                'id'                 => true,
                'fieldName'          => 'id',
            ],
        );

        $metadata->mapField(['fieldName' => 'index']);

        $metadata->mapField(['fieldName' => 'table']);

        $metadata->mapField(['fieldName' => 'fieldName']);

        $metadata->setPrimaryTable(
            [
                'uniqueConstraints' => [
                    ['columns' => ['index'], 'fields' => ['fieldName']],
                ],
            ],
        );
    }
}

#[Entity]
#[Table(name: 'gh5593_fulltext_unique')]
#[Index(name: 'search_idx', columns: ['title'], flags: ['fulltext'])]
#[UniqueConstraint(name: 'uniq_title', columns: ['title'])]
class GH5593FulltextWithUniqueConstraint
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255)]
    public string $title = '';
}

#[Entity]
#[Table(name: 'gh5593_fulltext_column_unique')]
#[Index(name: 'search_idx', columns: ['title'], flags: ['fulltext'])]
class GH5593FulltextWithColumnUnique
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255, unique: true)]
    public string $title = '';
}

#[Entity]
#[Table(name: 'gh5593_composite_fulltext_unique')]
#[Index(name: 'fulltext_ab', columns: ['a', 'b'], flags: ['fulltext'])]
#[Index(name: 'fulltext_c', columns: ['c'], flags: ['fulltext'])]
#[UniqueConstraint(name: 'uniq_ab', columns: ['a', 'b'])]
#[UniqueConstraint(name: 'uniq_ac', columns: ['a', 'c'])]
class GH5593CompositeFulltextAndUnique
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255)]
    public string $a = '';

    #[Column(length: 255)]
    public string $b = '';

    #[Column(length: 255)]
    public string $c = '';
}

#[Entity]
#[Table(name: 'gh5593_unflagged_unique')]
#[Index(name: 'idx_name', columns: ['name'])]
#[Index(name: 'idx_other', columns: ['other'])]
#[UniqueConstraint(name: 'uniq_name', columns: ['name'])]
class GH5593UnflaggedIndexWithUniqueConstraint
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255)]
    public string $name = '';

    #[Column(length: 255)]
    public string $other = '';
}

#[Entity]
#[Table(name: 'gh5593_flagged_indexes')]
#[Index(name: 'fulltext_title', columns: ['title'], flags: ['fulltext'])]
#[Index(name: 'spatial_title', columns: ['position'], flags: ['spatial'])]
#[Index(name: 'clustered_title', columns: ['subtitle'], flags: ['clustered'])]
class GH5593FlaggedIndexes
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255)]
    public string $title = '';

    #[Column(length: 255)]
    public string $position = '';

    #[Column(length: 255)]
    public string $subtitle = '';
}

#[Entity]
#[Table(name: 'gh5593_partial_unique')]
#[Index(name: 'idx_partial', columns: ['name'], options: ['where' => 'name IS NOT NULL'])]
#[Index(name: 'idx_covered_partial', columns: ['code'], options: ['where' => 'code IS NOT NULL'])]
#[UniqueConstraint(name: 'uniq_name', columns: ['name'])]
#[UniqueConstraint(name: 'uniq_code', columns: ['code'], options: ['where' => 'code IS NOT NULL'])]
class GH5593PartialIndexWithUniqueConstraint
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255)]
    public string $name = '';

    #[Column(length: 255)]
    public string $code = '';
}

#[Entity]
#[Table(name: '`quoted-table`')]
#[Index(columns: ['`quoted-name`'])]
class QuotedEntity
{
    #[Id]
    #[Column(name: '`quoted-id`')]
    public int $id = 0;

    #[Column(name: '`quoted-name`')]
    public string $name = '';
}

#[Entity]
#[Table(name: 'fulltext_spatial_flag_index')]
#[Index(name: 'bad_idx', columns: ['title'], flags: ['fulltext', 'spatial'])]
class FulltextSpatialFlagIndex
{
    #[Id]
    #[Column]
    public int $id = 0;

    #[Column(length: 255)]
    public string $title = '';
}

#[Entity]
#[Table(name: 'unique_scalar_id')]
class UniqueScalarIdEntity
{
    #[Id]
    #[Column(unique: true)]
    public int $id = 0;
}
