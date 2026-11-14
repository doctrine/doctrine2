<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Mapping\Builder\ClassMetadataBuilder;
use Doctrine\ORM\Mapping\Builder\EmbeddedBuilder;
use Doctrine\ORM\Mapping\Builder\FieldBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DiscriminatorColumnMapping;
use Doctrine\ORM\Mapping\EmbeddedClassMapping;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\ValueObjects\Name;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;
use SortDirection;

#[Group('DDC-659')]
class ClassMetadataBuilderTest extends OrmTestCase
{
    use VerifyDeprecations;

    private ClassMetadata $cm;
    private ClassMetadataBuilder $builder;

    protected function setUp(): void
    {
        $this->cm = new ClassMetadata(CmsUser::class);
        $this->cm->initializeReflection(new RuntimeReflectionService());
        $this->builder = new ClassMetadataBuilder($this->cm);
    }

    public function testSetMappedSuperClass(): void
    {
        $this->assertIsFluent($this->builder->setMappedSuperClass());
        self::assertTrue($this->cm->isMappedSuperclass);
        self::assertFalse($this->cm->isEmbeddedClass);
    }

    public function testSetEmbedable(): void
    {
        $this->assertIsFluent($this->builder->setEmbeddable());
        self::assertTrue($this->cm->isEmbeddedClass);
        self::assertFalse($this->cm->isMappedSuperclass);
    }

    public function testAddEmbeddedWithOnlyRequiredParams(): void
    {
        $this->assertIsFluent($this->builder->addEmbedded('name', Name::class));

        self::assertEquals(
            [
                'name' => EmbeddedClassMapping::fromMappingArray([
                    'class' => Name::class,
                    'columnPrefix' => null,
                    'declaredField' => null,
                    'originalField' => null,
                ]),
            ],
            $this->cm->embeddedClasses,
        );
    }

    public function testAddEmbeddedWithPrefix(): void
    {
        $this->assertIsFluent(
            $this->builder->addEmbedded(
                'name',
                Name::class,
                'nm_',
            ),
        );

        self::assertEquals(
            [
                'name' => EmbeddedClassMapping::fromMappingArray([
                    'class' => Name::class,
                    'columnPrefix' => 'nm_',
                    'declaredField' => null,
                    'originalField' => null,
                ]),
            ],
            $this->cm->embeddedClasses,
        );
    }

    public function testCreateEmbeddedWithoutExtraParams(): void
    {
        $embeddedBuilder = $this->builder->createEmbedded('name', Name::class);
        self::assertInstanceOf(EmbeddedBuilder::class, $embeddedBuilder);

        self::assertFalse(isset($this->cm->embeddedClasses['name']));

        $this->assertIsFluent($embeddedBuilder->build());
        self::assertEquals(
            EmbeddedClassMapping::fromMappingArray([
                'class' => Name::class,
                'columnPrefix' => null,
                'declaredField' => null,
                'originalField' => null,
            ]),
            $this->cm->embeddedClasses['name'],
        );
    }

    public function testCreateEmbeddedWithColumnPrefix(): void
    {
        $embeddedBuilder = $this->builder->createEmbedded('name', Name::class);

        self::assertEquals($embeddedBuilder, $embeddedBuilder->setColumnPrefix('nm_'));

        $this->assertIsFluent($embeddedBuilder->build());

        self::assertEquals(
            EmbeddedClassMapping::fromMappingArray([
                'class' => Name::class,
                'columnPrefix' => 'nm_',
                'declaredField' => null,
                'originalField' => null,
            ]),
            $this->cm->embeddedClasses['name'],
        );
    }

    public function testSetCustomRepositoryClass(): void
    {
        $this->assertIsFluent($this->builder->setCustomRepositoryClass(CmsGroup::class));
        self::assertEquals(CmsGroup::class, $this->cm->customRepositoryClassName);
    }

    public function testSetReadOnly(): void
    {
        $this->assertIsFluent($this->builder->setReadOnly());
        self::assertTrue($this->cm->isReadOnly);
    }

    public function testSetTable(): void
    {
        $this->assertIsFluent($this->builder->setTable('users'));
        self::assertEquals('users', $this->cm->table['name']);
    }

    public function testAddIndex(): void
    {
        $this->assertIsFluent($this->builder->addIndex(['username', 'name'], 'users_idx'));
        self::assertEquals(['users_idx' => ['columns' => ['username', 'name']]], $this->cm->table['indexes']);
    }

    public function testAddUniqueConstraint(): void
    {
        $this->assertIsFluent($this->builder->addUniqueConstraint(['username', 'name'], 'users_idx'));
        self::assertEquals(['users_idx' => ['columns' => ['username', 'name']]], $this->cm->table['uniqueConstraints']);
    }

    public function testSetPrimaryTableRelated(): void
    {
        $this->builder->addUniqueConstraint(['username', 'name'], 'users_idx');
        $this->builder->addIndex(['username', 'name'], 'users_idx');
        $this->builder->setTable('users');

        self::assertEquals(
            [
                'name' => 'users',
                'indexes' => ['users_idx' => ['columns' => ['username', 'name']]],
                'uniqueConstraints' => ['users_idx' => ['columns' => ['username', 'name']]],
            ],
            $this->cm->table,
        );
    }

    public function testSetInheritanceJoined(): void
    {
        $this->assertIsFluent($this->builder->setJoinedTableInheritance());
        self::assertEquals(ClassMetadata::INHERITANCE_TYPE_JOINED, $this->cm->inheritanceType);
    }

    public function testSetInheritanceSingleTable(): void
    {
        $this->assertIsFluent($this->builder->setSingleTableInheritance());
        self::assertEquals(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE, $this->cm->inheritanceType);
    }

    public function testSetDiscriminatorColumn(): void
    {
        $this->assertIsFluent($this->builder->setDiscriminatorColumn('discr', 'string', 124, null, null));
        self::assertEquals(DiscriminatorColumnMapping::fromMappingArray([
            'fieldName' => 'discr',
            'name' => 'discr',
            'type' => 'string',
            'length' => 124,
            'columnDefinition' => null,
            'enumType' => null,
            'options' => [],
        ]), $this->cm->discriminatorColumn);
    }

    public function testAddDiscriminatorMapClass(): void
    {
        $this->assertIsFluent($this->builder->addDiscriminatorMapClass('test', CmsUser::class));
        $this->assertIsFluent($this->builder->addDiscriminatorMapClass('test2', CmsGroup::class));

        self::assertEquals(
            ['test' => CmsUser::class, 'test2' => CmsGroup::class],
            $this->cm->discriminatorMap,
        );
        self::assertEquals('test', $this->cm->discriminatorValue);
    }

    public function testChangeTrackingPolicyExplicit(): void
    {
        $this->assertIsFluent($this->builder->setChangeTrackingPolicyDeferredExplicit());
        self::assertEquals(ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT, $this->cm->changeTrackingPolicy);
    }

    public function testAddField(): void
    {
        $this->assertIsFluent($this->builder->addField('name', 'string'));
        $mapping = $this->cm->getFieldMapping('name');
        self::assertSame('name', $mapping->fieldName);
        self::assertSame('name', $mapping->columnName);
        self::assertSame('string', $mapping->type);
    }

    public function testCreateField(): void
    {
        $fieldBuilder = $this->builder->createField('name', 'string');
        self::assertInstanceOf(FieldBuilder::class, $fieldBuilder);

        self::assertFalse(isset($this->cm->fieldMappings['name']));
        $this->assertIsFluent($fieldBuilder->build());
        $mapping = $this->cm->getFieldMapping('name');
        self::assertSame('name', $mapping->fieldName);
        self::assertSame('name', $mapping->columnName);
        self::assertSame('string', $mapping->type);
    }

    public function testCreateVersionedField(): void
    {
        $this->builder->createField('name', 'integer')->columnName('username')->length(124)->nullable()->columnDefinition('foobar')->unique()->isVersionField()->build();
        self::assertEquals(
            FieldMapping::fromMappingArray([
                'columnDefinition' => 'foobar',
                'columnName' => 'username',
                'options' => ['default' => 1],
                'fieldName' => 'name',
                'length' => 124,
                'type' => 'integer',
                'nullable' => true,
                'unique' => true,
            ]),
            $this->cm->fieldMappings['name'],
        );
    }

    public function testCreatePrimaryField(): void
    {
        $this->builder->createField('id', 'integer')->makePrimaryKey()->generatedValue()->build();

        self::assertEquals(['id'], $this->cm->identifier);
        self::assertEquals(FieldMapping::fromMappingArray(
            ['columnName' => 'id', 'fieldName' => 'id', 'id' => true, 'type' => 'integer'],
        ), $this->cm->fieldMappings['id']);
    }

    public function testCreateUnsignedOptionField(): void
    {
        $this->builder->createField('state', 'integer')->option('unsigned', true)->build();

        self::assertEquals(FieldMapping::fromMappingArray(
            ['fieldName' => 'state', 'type' => 'integer', 'options' => ['unsigned' => true], 'columnName' => 'state'],
        ), $this->cm->fieldMappings['state']);
    }

    public function testAddLifecycleEvent(): void
    {
        $this->builder->addLifecycleEvent('getStatus', 'postLoad');

        self::assertEquals(['postLoad' => ['getStatus']], $this->cm->lifecycleCallbacks);
    }

    public function testCreateManyToOne(): void
    {
        $this->assertIsFluent(
            $this->builder->createManyToOne('groups', CmsGroup::class)
                              ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                              ->cascadeAll()
                              ->fetchExtraLazy()
                              ->build(),
        );

        self::assertEquals(
            [
                'groups' => ManyToOneAssociationMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' => [
                        0 => 'remove',
                        1 => 'persist',
                        2 => 'refresh',
                        3 => 'detach',
                    ],
                    'fetch' => 4,
                    'joinColumns' => [
                        0 =>
                        [
                            'name' => 'group_id',
                            'referencedColumnName' => 'id',
                            'nullable' => true,
                            'unique' => false,
                            'onDelete' => 'CASCADE',
                            'columnDefinition' => null,
                        ],
                    ],
                    'type' => 2,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'sourceEntity' => CmsUser::class,
                    'sourceToTargetKeyColumns' =>
                    ['group_id' => 'id'],
                    'joinColumnFieldNames' =>
                    ['group_id' => 'group_id'],
                    'targetToSourceKeyColumns' =>
                    ['id' => 'group_id'],
                    'orphanRemoval' => false,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testCreateManyToOneWithIdentity(): void
    {
        $this->assertIsFluent(
            $this
                ->builder
                ->createManyToOne('groups', CmsGroup::class)
                ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                ->cascadeAll()
                ->fetchExtraLazy()
                ->makePrimaryKey()
                ->build(),
        );

        self::assertEquals(
            [
                'groups' => ManyToOneAssociationMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' => [
                        0 => 'remove',
                        1 => 'persist',
                        2 => 'refresh',
                        3 => 'detach',
                    ],
                    'fetch' => 4,
                    'joinColumns' => [
                        0 =>
                            [
                                'name' => 'group_id',
                                'referencedColumnName' => 'id',
                                'nullable' => false,
                                'unique' => false,
                                'onDelete' => 'CASCADE',
                                'columnDefinition' => null,
                            ],
                    ],
                    'type' => 2,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'sourceEntity' => CmsUser::class,
                    'sourceToTargetKeyColumns' =>
                        ['group_id' => 'id'],
                    'joinColumnFieldNames' =>
                        ['group_id' => 'group_id'],
                    'targetToSourceKeyColumns' =>
                        ['id' => 'group_id'],
                    'orphanRemoval' => false,
                    'id' => true,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testCreateOneToOne(): void
    {
        $this->assertIsFluent(
            $this->builder->createOneToOne('groups', CmsGroup::class)
                              ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                              ->cascadeAll()
                              ->fetchExtraLazy()
                              ->build(),
        );

        self::assertEquals(
            [
                'groups' => OneToOneOwningSideMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' => [
                        0 => 'remove',
                        1 => 'persist',
                        2 => 'refresh',
                        3 => 'detach',
                    ],
                    'fetch' => 4,
                    'joinColumns' => [
                        0 =>
                        [
                            'name' => 'group_id',
                            'referencedColumnName' => 'id',
                            'nullable' => true,
                            'unique' => true,
                            'onDelete' => 'CASCADE',
                            'columnDefinition' => null,
                        ],
                    ],
                    'type' => 1,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'sourceEntity' => CmsUser::class,
                    'sourceToTargetKeyColumns' =>
                    ['group_id' => 'id'],
                    'joinColumnFieldNames' =>
                    ['group_id' => 'group_id'],
                    'targetToSourceKeyColumns' =>
                    ['id' => 'group_id'],
                    'orphanRemoval' => false,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testCreateOneToOneWithIdentity(): void
    {
        $this->assertIsFluent(
            $this
                ->builder
                ->createOneToOne('groups', CmsGroup::class)
                ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                ->cascadeAll()
                ->fetchExtraLazy()
                ->makePrimaryKey()
                ->build(),
        );

        self::assertEquals(
            [
                'groups' => OneToOneOwningSideMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' => [
                        0 => 'remove',
                        1 => 'persist',
                        2 => 'refresh',
                        3 => 'detach',
                    ],
                    'fetch' => 4,
                    'id' => true,
                    'joinColumns' => [
                        0 =>
                            [
                                'name' => 'group_id',
                                'referencedColumnName' => 'id',
                                'nullable' => false,
                                'unique' => false,
                                'onDelete' => 'CASCADE',
                                'columnDefinition' => null,
                            ],
                    ],
                    'type' => 1,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'sourceEntity' => CmsUser::class,
                    'sourceToTargetKeyColumns' =>
                        ['group_id' => 'id'],
                    'joinColumnFieldNames' =>
                        ['group_id' => 'group_id'],
                    'targetToSourceKeyColumns' =>
                        ['id' => 'group_id'],
                    'orphanRemoval' => false,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testThrowsExceptionOnCreateOneToOneWithIdentityOnInverseSide(): void
    {
        $this->expectException(MappingException::class);

        $this
            ->builder
            ->createOneToOne('groups', CmsGroup::class)
            ->mappedBy('test')
            ->fetchExtraLazy()
            ->makePrimaryKey()
            ->build();
    }

    public function testCreateManyToMany(): void
    {
        $this->assertIsFluent(
            $this->builder->createManyToMany('groups', CmsGroup::class)
                              ->setJoinTable('groups_users')
                              ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                              ->addInverseJoinColumn('user_id', 'id')
                              ->cascadeAll()
                              ->fetchExtraLazy()
                              ->build(),
        );

        self::assertEquals(
            [
                'groups' =>
                ManyToManyOwningSideMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' =>
                    [
                        0 => 'remove',
                        1 => 'persist',
                        2 => 'refresh',
                        3 => 'detach',
                    ],
                    'fetch' => 4,
                    'joinTable' =>
                    [
                        'joinColumns' =>
                        [
                            0 =>
                            [
                                'name' => 'group_id',
                                'referencedColumnName' => 'id',
                                'unique' => false,
                                'onDelete' => 'CASCADE',
                                'columnDefinition' => null,
                            ],
                        ],
                        'inverseJoinColumns' =>
                        [
                            0 =>
                            [
                                'name' => 'user_id',
                                'referencedColumnName' => 'id',
                                'unique' => false,
                                'onDelete' => null,
                                'columnDefinition' => null,
                            ],
                        ],
                        'name' => 'groups_users',
                    ],
                    'type' => 8,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'isOnDeleteCascade' => true,
                    'sourceEntity' => CmsUser::class,
                    'relationToSourceKeyColumns' =>
                    ['group_id' => 'id'],
                    'joinTableColumns' =>
                    [
                        0 => 'group_id',
                        1 => 'user_id',
                    ],
                    'relationToTargetKeyColumns' =>
                    ['user_id' => 'id'],
                    'orphanRemoval' => false,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testThrowsExceptionOnCreateManyToManyWithIdentity(): void
    {
        $this->expectException(MappingException::class);

        $this->builder->createManyToMany('groups', CmsGroup::class)
                          ->makePrimaryKey()
                          ->setJoinTable('groups_users')
                          ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                          ->addInverseJoinColumn('user_id', 'id')
                          ->cascadeAll()
                          ->fetchExtraLazy()
                          ->build();
    }

    public function testCreateOneToMany(): void
    {
        $this->assertIsFluent(
            $this->builder->createOneToMany('groups', CmsGroup::class)
                        ->mappedBy('test')
                        ->setOrderBy(['test' => SortDirection::Ascending])
                        ->setIndexBy('test')
                        ->build(),
        );

        self::assertEquals(
            [
                'groups' =>
                OneToManyAssociationMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'mappedBy' => 'test',
                    'orderBy' => ['test' => SortDirection::Ascending],
                    'indexBy' => 'test',
                    'type' => 4,
                    'isOwningSide' => false,
                    'sourceEntity' => CmsUser::class,
                    'fetch' => 2,
                    'cascade' => [],
                    'orphanRemoval' => false,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testThrowsExceptionOnCreateOneToManyWithIdentity(): void
    {
        $this->expectException(MappingException::class);

        $this->builder->createOneToMany('groups', CmsGroup::class)
                ->makePrimaryKey()
                ->mappedBy('test')
                ->setOrderBy(['test' => SortDirection::Ascending])
                ->setIndexBy('test')
                ->build();
    }

    public function testOrphanRemovalOnCreateOneToOne(): void
    {
        $this->assertIsFluent(
            $this->builder
                ->createOneToOne('groups', CmsGroup::class)
                ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
                ->orphanRemoval()
                ->build(),
        );

        self::assertEquals(
            [
                'groups' => OneToOneOwningSideMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' => [],
                    'fetch' => 2,
                    'joinColumns' => [
                        0 =>
                        [
                            'name' => 'group_id',
                            'referencedColumnName' => 'id',
                            'nullable' => true,
                            'unique' => true,
                            'onDelete' => 'CASCADE',
                            'columnDefinition' => null,
                        ],
                    ],
                    'type' => 1,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'sourceEntity' => CmsUser::class,
                    'sourceToTargetKeyColumns' =>
                    ['group_id' => 'id'],
                    'joinColumnFieldNames' =>
                    ['group_id' => 'group_id'],
                    'targetToSourceKeyColumns' =>
                    ['id' => 'group_id'],
                    'orphanRemoval' => true,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testOrphanRemovalOnCreateOneToMany(): void
    {
        $this->assertIsFluent(
            $this->builder
                ->createOneToMany('groups', CmsGroup::class)
                ->mappedBy('test')
                ->orphanRemoval()
                ->build(),
        );

        self::assertEquals(
            [
                'groups' =>
                OneToManyAssociationMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'mappedBy' => 'test',
                    'type' => 4,
                    'isOwningSide' => false,
                    'sourceEntity' => CmsUser::class,
                    'fetch' => 2,
                    'cascade' => [],
                    'orphanRemoval' => true,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function testExceptionOnOrphanRemovalOnManyToOne(): void
    {
        $this->expectException(MappingException::class);

        $this->builder
            ->createManyToOne('groups', CmsGroup::class)
            ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
            ->orphanRemoval()
            ->build();
    }

    public function testOrphanRemovalOnManyToMany(): void
    {
        $this->builder
            ->createManyToMany('groups', CmsGroup::class)
            ->addJoinColumn('group_id', 'id', true, false, 'CASCADE')
            ->orphanRemoval()
            ->build();

        self::assertEquals(
            [
                'groups' => ManyToManyOwningSideMapping::fromMappingArray([
                    'fieldName' => 'groups',
                    'targetEntity' => CmsGroup::class,
                    'cascade' => [],
                    'fetch' => 2,
                    'joinTable' => [
                        'joinColumns' => [
                            0 => [
                                'name' => 'group_id',
                                'referencedColumnName' => 'id',
                                'unique' => false,
                                'onDelete' => 'CASCADE',
                                'columnDefinition' => null,
                            ],
                        ],
                        'inverseJoinColumns' => [
                            0 => [
                                'name' => 'cmsgroup_id',
                                'referencedColumnName' => 'id',
                                'onDelete' => 'CASCADE',
                            ],
                        ],
                        'name' => 'cmsuser_cmsgroup',
                    ],
                    'type' => 8,
                    'inversedBy' => null,
                    'isOwningSide' => true,
                    'sourceEntity' => CmsUser::class,
                    'isOnDeleteCascade' => true,
                    'relationToSourceKeyColumns' => ['group_id' => 'id'],
                    'joinTableColumns' => [
                        0 => 'group_id',
                        1 => 'cmsgroup_id',
                    ],
                    'relationToTargetKeyColumns' => ['cmsgroup_id' => 'id'],
                    'orphanRemoval' => true,
                ]),
            ],
            $this->cm->associationMappings,
        );
    }

    public function assertIsFluent($ret): void
    {
        self::assertSame($this->builder, $ret, 'Return Value has to be same instance as used builder');
    }
}
