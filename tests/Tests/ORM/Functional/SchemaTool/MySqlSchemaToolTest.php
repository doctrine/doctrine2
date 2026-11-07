<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\SchemaTool;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Index as DbalIndex;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Table as DbalTable;
use Doctrine\DBAL\Schema\Visitor\Visitor;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsEmail;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsTag;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\Generic\BooleanModel;
use Doctrine\Tests\Models\Generic\DecimalModel;
use Doctrine\Tests\ORM\Tools\SchemaAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_filter;
use function array_values;
use function class_exists;
use function enum_exists;
use function implode;
use function in_array;
use function str_contains;
use function strtolower;

class MySqlSchemaToolTest extends OrmFunctionalTestCase
{
    use SchemaAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->_em->getConnection()->getDatabasePlatform() instanceof MySQLPlatform) {
            self::markTestSkipped('The ' . self::class . ' requires the use of mysql.');
        }
    }

    public function testGetSchemaFromMetadata(): void
    {
        $classes = [
            $this->_em->getClassMetadata(CmsGroup::class),
            $this->_em->getClassMetadata(CmsUser::class),
            $this->_em->getClassMetadata(CmsTag::class),
            $this->_em->getClassMetadata(CmsAddress::class),
            $this->_em->getClassMetadata(CmsEmail::class),
            $this->_em->getClassMetadata(CmsPhonenumber::class),
        ];

        $tool   = new SchemaTool($this->_em);
        $schema = $tool->getSchemaFromMetadata($classes);

        self::assertCount(8, $schema->getTables());

        self::assertTableEquals(
            self::table(
                'cms_groups',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('name', 'string', ['length' => 50]),
                ],
                primaryKey: ['id'],
            ),
            $schema->getTable('cms_groups'),
        );

        self::assertTableEquals(
            self::table(
                'cms_users',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('status', 'string', ['length' => 50, 'notnull' => false]),
                    self::column('username', 'string', ['length' => 255]),
                    self::column('name', 'string', ['length' => 255]),
                    self::joinColumn('email_id', 'integer', ['notnull' => false]),
                ],
                primaryKey: ['id'],
                indexes: [
                    new DbalIndex('UNIQ_3AF03EC5F85E0677', ['username'], true),
                    new DbalIndex('UNIQ_3AF03EC5A832C1C9', ['email_id'], true),
                ],
                foreignKeys: [
                    self::foreignKey(['email_id'], 'cms_emails', ['id']),
                ],
            ),
            $schema->getTable('cms_users'),
        );

        self::assertTableEquals(
            self::table(
                'cms_users_groups',
                [
                    self::joinColumn('user_id', 'integer'),
                    self::joinColumn('group_id', 'integer'),
                ],
                primaryKey: ['user_id', 'group_id'],
                indexes: [
                    new DbalIndex('IDX_7EA9409AA76ED395', ['user_id']),
                    new DbalIndex('IDX_7EA9409AFE54D947', ['group_id']),
                ],
                foreignKeys: [
                    self::foreignKey(['user_id'], 'cms_users', ['id']),
                    self::foreignKey(['group_id'], 'cms_groups', ['id']),
                ],
            ),
            $schema->getTable('cms_users_groups'),
        );

        self::assertTableEquals(
            self::table(
                'cms_users_tags',
                [
                    self::joinColumn('user_id', 'integer'),
                    self::joinColumn('tag_id', 'integer'),
                ],
                primaryKey: ['user_id', 'tag_id'],
                indexes: [
                    new DbalIndex('IDX_93F5A1ADA76ED395', ['user_id']),
                    new DbalIndex('IDX_93F5A1ADBAD26311', ['tag_id']),
                ],
                foreignKeys: [
                    self::foreignKey(['user_id'], 'cms_users', ['id']),
                    self::foreignKey(['tag_id'], 'cms_tags', ['id']),
                ],
            ),
            $schema->getTable('cms_users_tags'),
        );

        self::assertTableEquals(
            self::table(
                'cms_tags',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('tag_name', 'string', ['length' => 50, 'notnull' => false]),
                ],
                primaryKey: ['id'],
            ),
            $schema->getTable('cms_tags'),
        );

        self::assertTableEquals(
            self::table(
                'cms_addresses',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('country', 'string', ['length' => 50]),
                    self::column('zip', 'string', ['length' => 50]),
                    self::column('city', 'string', ['length' => 50]),
                    self::joinColumn('user_id', 'integer', ['notnull' => false]),
                ],
                primaryKey: ['id'],
                indexes: [
                    new DbalIndex('UNIQ_ACAC157BA76ED395', ['user_id'], true),
                ],
                foreignKeys: [
                    self::foreignKey(['user_id'], 'cms_users', ['id']),
                ],
            ),
            $schema->getTable('cms_addresses'),
        );

        self::assertTableEquals(
            self::table(
                'cms_emails',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('email', 'string', ['length' => 250]),
                ],
                primaryKey: ['id'],
            ),
            $schema->getTable('cms_emails'),
        );

        self::assertTableEquals(
            self::table(
                'cms_phonenumbers',
                [
                    self::column('phonenumber', 'string', ['length' => 50]),
                    self::joinColumn('user_id', 'integer', ['notnull' => false]),
                ],
                primaryKey: ['phonenumber'],
                indexes: [
                    new DbalIndex('IDX_F21F790FA76ED395', ['user_id']),
                ],
                foreignKeys: [
                    self::foreignKey(['user_id'], 'cms_users', ['id']),
                ],
            ),
            $schema->getTable('cms_phonenumbers'),
        );
    }

    public function testGetSchemaForDecimalModel(): void
    {
        $tool   = new SchemaTool($this->_em);
        $schema = $tool->getSchemaFromMetadata([$this->_em->getClassMetadata(DecimalModel::class)]);

        self::assertTrue($schema->hasTable('decimal_model'));

        self::assertTableEquals(
            self::table(
                'decimal_model',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('`decimal`', 'decimal', ['precision' => 5, 'scale' => 2]),
                    self::column('`high_scale`', 'decimal', ['precision' => 14, 'scale' => 4]),
                ],
                primaryKey: ['id'],
            ),
            $schema->getTable('decimal_model'),
        );
    }

    public function testGetSchemaForBooleanModel(): void
    {
        $tool   = new SchemaTool($this->_em);
        $schema = $tool->getSchemaFromMetadata([$this->_em->getClassMetadata(BooleanModel::class)]);

        self::assertTrue($schema->hasTable('boolean_model'));

        self::assertTableEquals(
            self::table(
                'boolean_model',
                [
                    self::column('id', 'integer', ['autoincrement' => true]),
                    self::column('booleanField', 'boolean'),
                ],
                primaryKey: ['id'],
            ),
            $schema->getTable('boolean_model'),
        );
    }

    #[Group('DBAL-204')]
    public function testGetCreateSchemaSql4(): void
    {
        if (! class_exists(Visitor::class)) {
            self::markTestSkipped('Test valid for doctrine/dbal:3.x only.');
        }

        $classes = [$this->_em->getClassMetadata(MysqlSchemaNamespacedEntity::class)];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);

        self::assertCount(0, $sql);
    }

    #[Group('GH-5593')]
    public function testFulltextIndexSurvivesCreateIntrospectAndUpdateWithUniqueConstraint(): void
    {
        $this->assertFulltextAndUniqueSurviveRoundTrip(
            GH5593MySqlFulltextUnique::class,
            'gh5593_mysql_fulltext_unique',
            'search_idx',
            'uniq_title',
        );
    }

    #[Group('GH-5593')]
    public function testFulltextIndexSurvivesCreateIntrospectAndUpdateWithColumnUnique(): void
    {
        $className = GH5593MySqlFulltextColumnUnique::class;
        $tableName = 'gh5593_mysql_fulltext_column_unique';

        $this->createSchemaForModels($className);

        $createSql = implode("\n", $this->_schemaTool->getCreateSchemaSql([
            $this->_em->getClassMetadata($className),
        ]));
        self::assertStringContainsString('FULLTEXT INDEX search_idx', $createSql);
        self::assertDoesNotContainAlwaysTruePredicate($createSql);

        $table = $this->createSchemaManager()->introspectTable($tableName);
        self::assertTrue($table->hasIndex('search_idx'));
        self::assertTrue(self::indexIsFulltext($table->getIndex('search_idx')));
        self::assertNotNull(self::findUniqueNonPrimaryIndex($table), 'Uniqueness should survive alongside the fulltext index.');

        $this->assertEmptySchemaUpdate($className, $tableName);
    }

    /** @param class-string $className */
    private function assertFulltextAndUniqueSurviveRoundTrip(
        string $className,
        string $tableName,
        string $fulltextIndexName,
        string $uniqueIndexName,
    ): void {
        $this->createSchemaForModels($className);

        $createSql = implode("\n", $this->_schemaTool->getCreateSchemaSql([
            $this->_em->getClassMetadata($className),
        ]));
        self::assertStringContainsString('FULLTEXT INDEX ' . $fulltextIndexName, $createSql);
        self::assertStringContainsString('UNIQUE INDEX ' . $uniqueIndexName, $createSql);
        self::assertDoesNotContainAlwaysTruePredicate($createSql);

        $table = $this->createSchemaManager()->introspectTable($tableName);
        self::assertTrue($table->hasIndex($fulltextIndexName));
        self::assertTrue($table->hasIndex($uniqueIndexName));
        self::assertTrue(self::indexIsFulltext($table->getIndex($fulltextIndexName)));
        self::assertTrue(self::indexIsUnique($table->getIndex($uniqueIndexName)));

        $this->assertEmptySchemaUpdate($className, $tableName);
    }

    /** @param class-string $className */
    private function assertEmptySchemaUpdate(string $className, string $tableName): void
    {
        $updateSql = array_filter(
            $this->getUpdateSchemaSqlForModels($className),
            static fn (string $sql): bool => str_contains($sql, $tableName),
        );

        self::assertSame([], array_values($updateSql), implode("\n", $updateSql));
        self::assertDoesNotContainAlwaysTruePredicate(implode("\n", $updateSql));
    }

    private static function assertDoesNotContainAlwaysTruePredicate(string $sql): void
    {
        self::assertStringNotContainsString('1 = 1', $sql);
        self::assertStringNotContainsString('1=1', $sql);
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
}

#[Table('namespace.entity')]
#[Entity]
class MysqlSchemaNamespacedEntity
{
    /** @var int */
    #[Column(type: 'integer')]
    #[Id]
    #[GeneratedValue]
    public $id;
}

#[Table(name: 'gh5593_mysql_fulltext_unique')]
#[Index(name: 'search_idx', columns: ['title'], flags: ['fulltext'])]
#[UniqueConstraint(name: 'uniq_title', columns: ['title'])]
#[Entity]
class GH5593MySqlFulltextUnique
{
    #[Id]
    #[Column]
    #[GeneratedValue]
    public int $id;

    #[Column(length: 255)]
    public string $title = '';
}

#[Table(name: 'gh5593_mysql_fulltext_column_unique')]
#[Index(name: 'search_idx', columns: ['title'], flags: ['fulltext'])]
#[Entity]
class GH5593MySqlFulltextColumnUnique
{
    #[Id]
    #[Column]
    #[GeneratedValue]
    public int $id;

    #[Column(length: 255, unique: true)]
    public string $title = '';
}
