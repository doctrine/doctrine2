<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\SchemaTool;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Index as DbalIndex;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Table as DbalTable;
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
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function array_filter;
use function array_values;
use function implode;
use function str_contains;
use function strtolower;

class MySqlSchemaToolTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->_em->getConnection()->getDatabasePlatform() instanceof MySQLPlatform) {
            self::markTestSkipped('The ' . self::class . ' requires the use of mysql.');
        }
    }

    public function testGetCreateSchemaSql(): void
    {
        $classes = [
            $this->_em->getClassMetadata(CmsGroup::class),
            $this->_em->getClassMetadata(CmsUser::class),
            $this->_em->getClassMetadata(CmsTag::class),
            $this->_em->getClassMetadata(CmsAddress::class),
            $this->_em->getClassMetadata(CmsEmail::class),
            $this->_em->getClassMetadata(CmsPhonenumber::class),
        ];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);
        self::assertEquals(
            $sql[0],
            'CREATE TABLE cms_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[1],
            'CREATE TABLE cms_users (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(50) DEFAULT NULL, username VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, email_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3AF03EC5F85E0677 (username), UNIQUE INDEX UNIQ_3AF03EC5A832C1C9 (email_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[2],
            'CREATE TABLE cms_users_groups (user_id INT NOT NULL, group_id INT NOT NULL, INDEX IDX_7EA9409AA76ED395 (user_id), INDEX IDX_7EA9409AFE54D947 (group_id), PRIMARY KEY (user_id, group_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[3],
            'CREATE TABLE cms_users_tags (user_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_93F5A1ADA76ED395 (user_id), INDEX IDX_93F5A1ADBAD26311 (tag_id), PRIMARY KEY (user_id, tag_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[4],
            'CREATE TABLE cms_tags (id INT AUTO_INCREMENT NOT NULL, tag_name VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[5],
            'CREATE TABLE cms_addresses (id INT AUTO_INCREMENT NOT NULL, country VARCHAR(50) NOT NULL, zip VARCHAR(50) NOT NULL, city VARCHAR(50) NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_ACAC157BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[6],
            'CREATE TABLE cms_emails (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(250) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertEquals(
            $sql[7],
            'CREATE TABLE cms_phonenumbers (phonenumber VARCHAR(50) NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_F21F790FA76ED395 (user_id), PRIMARY KEY (phonenumber)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
        self::assertThat($sql[8], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_users ADD CONSTRAINT FK_3AF03EC5A832C1C9 FOREIGN KEY (email_id) REFERENCES cms_emails (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_users ADD FOREIGN KEY (email_id) REFERENCES cms_emails (id)'),
        ));
        self::assertThat($sql[9], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_users_groups ADD CONSTRAINT FK_7EA9409AA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_users_groups ADD FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
        ));
        self::assertThat($sql[10], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_users_groups ADD CONSTRAINT FK_7EA9409AFE54D947 FOREIGN KEY (group_id) REFERENCES cms_groups (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_users_groups ADD FOREIGN KEY (group_id) REFERENCES cms_groups (id)'),
        ));
        self::assertThat($sql[11], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_users_tags ADD CONSTRAINT FK_93F5A1ADA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_users_tags ADD FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
        ));
        self::assertThat($sql[12], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_users_tags ADD CONSTRAINT FK_93F5A1ADBAD26311 FOREIGN KEY (tag_id) REFERENCES cms_tags (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_users_tags ADD FOREIGN KEY (tag_id) REFERENCES cms_tags (id)'),
        ));
        self::assertThat($sql[13], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_addresses ADD CONSTRAINT FK_ACAC157BA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_addresses ADD FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
        ));
        self::assertThat($sql[14], self::logicalOr(
            // DBAL < 4.5
            self::equalTo('ALTER TABLE cms_phonenumbers ADD CONSTRAINT FK_F21F790FA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
            // DBAL 4.5
            self::equalTo('ALTER TABLE cms_phonenumbers ADD FOREIGN KEY (user_id) REFERENCES cms_users (id)'),
        ));

        self::assertCount(15, $sql);
    }

    public function testGetCreateSchemaSql2(): void
    {
        $classes = [$this->_em->getClassMetadata(DecimalModel::class)];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);

        self::assertCount(1, $sql);
        self::assertEquals(
            $sql[0],
            'CREATE TABLE decimal_model (id INT AUTO_INCREMENT NOT NULL, `decimal` NUMERIC(5, 2) NOT NULL, `high_scale` NUMERIC(14, 4) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
    }

    public function testGetCreateSchemaSql3(): void
    {
        $classes = [$this->_em->getClassMetadata(BooleanModel::class)];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);

        self::assertCount(1, $sql);
        self::assertEquals(
            $sql[0],
            'CREATE TABLE boolean_model (id INT AUTO_INCREMENT NOT NULL, booleanField TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB',
        );
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
        return $index->getType() === IndexType::UNIQUE;
    }

    private static function indexIsFulltext(DbalIndex $index): bool
    {
        return $index->getType() === IndexType::FULLTEXT;
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
