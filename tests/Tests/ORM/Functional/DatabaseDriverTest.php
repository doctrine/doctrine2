<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\Identifier;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Group;

use function array_change_key_case;
use function class_exists;
use function count;
use function strtolower;

use const CASE_LOWER;

class DatabaseDriverTest extends DatabaseDriverTestCase
{
    protected AbstractSchemaManager|null $schemaManager = null;

    protected function setUp(): void
    {
        $this->useModelSet('cms');

        parent::setUp();

        $this->schemaManager = $this->createSchemaManager();
    }

    #[Group('DDC-2059')]
    public function testIssue2059(): void
    {
        $user = new Table(
            'ddc2059_user',
            [self::createColumn('id', Types::INTEGER)],
        );

        $project = new Table(
            'ddc2059_project',
            [
                self::createColumn('id', Types::INTEGER),
                self::createColumn('user_id', Types::INTEGER),
                self::createColumn('user', Types::STRING),

            ],
        );

        if (class_exists(TableEditor::class)) {
            $user    = $user->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->create();
            $project = $project->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->addForeignKeyConstraint(new ForeignKeyConstraint(
                    ['user_id'],
                    'ddc2059_user',
                    ['id'],
                ))
                ->create();
        } else {
            $user->setPrimaryKey(['id']);
            $project->setPrimaryKey(['id']);
            $project->addForeignKeyConstraint('ddc2059_user', ['user_id'], ['id']);
        }

        $metadata = $this->convertToClassMetadata([$project, $user], []);

        self::assertTrue(isset($metadata['Ddc2059Project']->fieldMappings['user']));
        self::assertTrue(isset($metadata['Ddc2059Project']->associationMappings['user2']));
    }

    public function testLoadMetadataFromDatabase(): void
    {
        $table = new Table(
            'dbdriver_foo',
            [
                self::createColumn('id', Types::INTEGER),
                self::createColumn('bar', Types::STRING, ['notnull' => false, 'length' => 200]),
            ],
        );

        if (class_exists(TableEditor::class)) {
            $table = $table->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->create();
        } else {
            $table->setPrimaryKey(['id']);
        }

        $this->dropAndCreateTable($table);

        $metadatas = $this->extractClassMetadata(['DbdriverFoo']);

        self::assertArrayHasKey('DbdriverFoo', $metadatas);
        $metadata = $metadatas['DbdriverFoo'];

        self::assertArrayHasKey('id', $metadata->fieldMappings);
        self::assertEquals('id', $metadata->fieldMappings['id']->fieldName);
        self::assertEquals('id', strtolower($metadata->fieldMappings['id']->columnName));
        self::assertEquals('integer', (string) $metadata->fieldMappings['id']->type);

        self::assertArrayHasKey('bar', $metadata->fieldMappings);
        self::assertEquals('bar', $metadata->fieldMappings['bar']->fieldName);
        self::assertEquals('bar', strtolower($metadata->fieldMappings['bar']->columnName));
        self::assertEquals('string', (string) $metadata->fieldMappings['bar']->type);
        self::assertEquals(200, $metadata->fieldMappings['bar']->length);
        self::assertTrue($metadata->fieldMappings['bar']->nullable);
    }

    public function testLoadMetadataWithForeignKeyFromDatabase(): void
    {
        $tableB = new Table(
            'dbdriver_bar',
            [
                self::createColumn('id', Types::INTEGER),
            ],
        );

        $tableA = new Table(
            'dbdriver_baz',
            [
                self::createColumn('id', Types::INTEGER),
                self::createColumn('bar_id', Types::INTEGER),
            ],
        );

        if (class_exists(TableEditor::class)) {
            $tableB = $tableB->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->create();
            $tableA = $tableA->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->addForeignKeyConstraint(new ForeignKeyConstraint(
                    ['bar_id'],
                    'dbdriver_bar',
                    ['id'],
                ))
                ->create();
        } else {
            $tableB->setPrimaryKey(['id']);
            $tableA->setPrimaryKey(['id']);
            $tableA->addForeignKeyConstraint('dbdriver_bar', ['bar_id'], ['id']);
        }

        $this->dropAndCreateTable($tableB);
        $this->dropAndCreateTable($tableA);

        $metadatas = $this->extractClassMetadata(['DbdriverBar', 'DbdriverBaz']);

        self::assertArrayHasKey('DbdriverBaz', $metadatas);
        $bazMetadata = $metadatas['DbdriverBaz'];

        self::assertArrayNotHasKey('barId', $bazMetadata->fieldMappings, "The foreign Key field should not be inflected as 'barId' field, its an association.");
        self::assertArrayHasKey('id', $bazMetadata->fieldMappings);

        $bazMetadata->associationMappings = array_change_key_case($bazMetadata->associationMappings, CASE_LOWER);

        self::assertArrayHasKey('bar', $bazMetadata->associationMappings);
        self::assertTrue($bazMetadata->associationMappings['bar']->isManyToOne());
    }

    public function testDetectManyToManyTables(): void
    {
        $metadatas = $this->extractClassMetadata(['CmsUsers', 'CmsGroups', 'CmsTags']);

        self::assertArrayHasKey('CmsUsers', $metadatas, 'CmsUsers entity was not detected.');
        self::assertArrayHasKey('CmsGroups', $metadatas, 'CmsGroups entity was not detected.');
        self::assertArrayHasKey('CmsTags', $metadatas, 'CmsTags entity was not detected.');

        self::assertEquals(3, count($metadatas['CmsUsers']->associationMappings));
        self::assertArrayHasKey('group', $metadatas['CmsUsers']->associationMappings);
        self::assertEquals(1, count($metadatas['CmsGroups']->associationMappings));
        self::assertArrayHasKey('user', $metadatas['CmsGroups']->associationMappings);
        self::assertEquals(1, count($metadatas['CmsTags']->associationMappings));
        self::assertArrayHasKey('user', $metadatas['CmsGroups']->associationMappings);
    }

    public function testIgnoreManyToManyTableWithoutFurtherForeignKeyDetails(): void
    {
        $tableB = new Table(
            'dbdriver_bar',
            [self::createColumn('id', Types::INTEGER)],
        );

        $tableA = new Table(
            'dbdriver_baz',
            [
                self::createColumn('id', Types::INTEGER),
            ],
        );

        if (class_exists(PrimaryKeyConstraint::class)) {
            $tableB = $tableB->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->create();
            $tableA = $tableA->edit()
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->create();
        } else {
            $tableB->setPrimaryKey(['id']);
            $tableA->setPrimaryKey(['id']);
        }

        $tableMany = new Table(
            'dbdriver_bar_baz',
            [
                self::createColumn('bar_id', Types::INTEGER),
                self::createColumn('baz_id', Types::INTEGER),
            ],
        );
        if (class_exists(TableEditor::class)) {
            $tableMany = $tableMany->edit()
                ->addForeignKeyConstraint(new ForeignKeyConstraint(
                    ['bar_id'],
                    'dbdriver_bar',
                    ['id'],
                ))
                ->create();
        } else {
            $tableMany->addForeignKeyConstraint('dbdriver_bar', ['bar_id'], ['id']);
        }

        $metadatas = $this->convertToClassMetadata([$tableA, $tableB], [$tableMany]);

        self::assertEquals(0, count($metadatas['DbdriverBaz']->associationMappings), 'no association mappings should be detected.');
    }

    public function testLoadMetadataFromDatabaseDetail(): void
    {
        $table = new Table(
            'dbdriver_foo',
            [
                self::createColumn('id', Types::INTEGER, ['unsigned' => true]),
                self::createColumn('column_unsigned', Types::INTEGER, ['unsigned' => true]),
                self::createColumn('column_comment', Types::STRING, ['length' => 16, 'comment' => 'test_comment']),
                self::createColumn('column_default', Types::STRING, ['length' => 16, 'default' => 'test_default']),
                self::createColumn('column_decimal', Types::DECIMAL, ['precision' => 4, 'scale' => 3]),
                self::createColumn('column_index1', Types::STRING, ['length' => 16]),
                self::createColumn('column_index2', Types::STRING, ['length' => 16]),
                self::createColumn('column_unique_index1', Types::STRING, ['length' => 16]),
                self::createColumn('column_unique_index2', Types::STRING, ['length' => 16]),
            ],
        );

        if (class_exists(TableEditor::class)) {
            $table = $table->edit()
                ->addIndex(
                    Index::editor()
                        ->setUnquotedName('index1')
                        ->setUnquotedColumnNames('column_index1', 'column_index2')
                        ->create(),
                )
                ->addIndex(
                    Index::editor()
                        ->setUnquotedName('unique_index1')
                        ->setType(IndexType::UNIQUE)
                        ->setUnquotedColumnNames('column_unique_index1', 'column_unique_index2')
                        ->create(),
                )
                ->addPrimaryKeyConstraint(new PrimaryKeyConstraint(
                    null,
                    [new UnqualifiedName(Identifier::unquoted('id'))],
                    true,
                ))
                ->create();
        } else {
            $table->addIndex(['column_index1', 'column_index2'], 'index1');
            $table->addUniqueIndex(['column_unique_index1', 'column_unique_index2'], 'unique_index1');
            $table->setPrimaryKey(['id']);
        }

        $this->dropAndCreateTable($table);

        $metadatas = $this->extractClassMetadata(['DbdriverFoo']);

        self::assertArrayHasKey('DbdriverFoo', $metadatas);

        $metadata = $metadatas['DbdriverFoo'];

        self::assertArrayHasKey('id', $metadata->fieldMappings);
        self::assertEquals('id', $metadata->fieldMappings['id']->fieldName);
        self::assertEquals('id', strtolower($metadata->fieldMappings['id']->columnName));
        self::assertEquals('integer', (string) $metadata->fieldMappings['id']->type);

        if (self::supportsUnsignedInteger($this->_em->getConnection()->getDatabasePlatform())) {
            self::assertArrayHasKey('columnUnsigned', $metadata->fieldMappings);
            self::assertTrue($metadata->fieldMappings['columnUnsigned']->options['unsigned']);
        }

        self::assertArrayHasKey('columnComment', $metadata->fieldMappings);
        self::assertEquals('test_comment', $metadata->fieldMappings['columnComment']->options['comment']);

        self::assertArrayHasKey('columnDefault', $metadata->fieldMappings);
        self::assertEquals('test_default', $metadata->fieldMappings['columnDefault']->options['default']);

        self::assertArrayHasKey('columnDecimal', $metadata->fieldMappings);
        self::assertEquals(4, $metadata->fieldMappings['columnDecimal']->precision);
        self::assertEquals(3, $metadata->fieldMappings['columnDecimal']->scale);

        self::assertNotEmpty($metadata->table['indexes']['index1']['columns']);
        self::assertEquals(
            ['column_index1', 'column_index2'],
            $metadata->table['indexes']['index1']['columns'],
        );

        self::assertNotEmpty($metadata->table['uniqueConstraints']['unique_index1']['columns']);
        self::assertEquals(
            ['column_unique_index1', 'column_unique_index2'],
            $metadata->table['uniqueConstraints']['unique_index1']['columns'],
        );
    }

    private static function supportsUnsignedInteger(AbstractPlatform $platform): bool
    {
        // FIXME: Condition here is fugly.
        // NOTE: PostgreSQL and SQL SERVER do not support UNSIGNED integer

        return ! $platform instanceof SQLServerPlatform
            && ! $platform instanceof PostgreSQLPlatform;
    }
}
