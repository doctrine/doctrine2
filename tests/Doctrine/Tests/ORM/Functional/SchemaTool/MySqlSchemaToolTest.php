<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\SchemaTool;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\Models;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;
use function method_exists;
use function sprintf;

class MySqlSchemaToolTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'mysql') {
            $this->markTestSkipped('The ' . self::class . ' requires the use of mysql.');
        }
    }

    public function testGetCreateSchemaSql(): void
    {
        $classes = [
            $this->_em->getClassMetadata(Models\CMS\CmsGroup::class),
            $this->_em->getClassMetadata(Models\CMS\CmsUser::class),
            $this->_em->getClassMetadata(Models\CMS\CmsTag::class),
            $this->_em->getClassMetadata(Models\CMS\CmsAddress::class),
            $this->_em->getClassMetadata(Models\CMS\CmsEmail::class),
            $this->_em->getClassMetadata(Models\CMS\CmsPhonenumber::class),
        ];

        $tool      = new SchemaTool($this->_em);
        $sql       = $tool->getCreateSchemaSql($classes);
        $collation = $this->getColumnCollationDeclarationSQL('utf8_unicode_ci');

        $this->assertEquals('CREATE TABLE cms_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[0]);
        $this->assertEquals('CREATE TABLE cms_users (id INT AUTO_INCREMENT NOT NULL, email_id INT DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, username VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_3AF03EC5F85E0677 (username), UNIQUE INDEX UNIQ_3AF03EC5A832C1C9 (email_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[1]);
        $this->assertEquals('CREATE TABLE cms_users_groups (user_id INT NOT NULL, group_id INT NOT NULL, INDEX IDX_7EA9409AA76ED395 (user_id), INDEX IDX_7EA9409AFE54D947 (group_id), PRIMARY KEY(user_id, group_id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[2]);
        $this->assertEquals('CREATE TABLE cms_users_tags (user_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_93F5A1ADA76ED395 (user_id), INDEX IDX_93F5A1ADBAD26311 (tag_id), PRIMARY KEY(user_id, tag_id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[3]);
        $this->assertEquals('CREATE TABLE cms_tags (id INT AUTO_INCREMENT NOT NULL, tag_name VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[4]);
        $this->assertEquals('CREATE TABLE cms_addresses (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, country VARCHAR(50) NOT NULL, zip VARCHAR(50) NOT NULL, city VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_ACAC157BA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[5]);
        $this->assertEquals('CREATE TABLE cms_emails (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(250) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[6]);
        $this->assertEquals('CREATE TABLE cms_phonenumbers (phonenumber VARCHAR(50) NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_F21F790FA76ED395 (user_id), PRIMARY KEY(phonenumber)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[7]);
        $this->assertEquals('ALTER TABLE cms_users ADD CONSTRAINT FK_3AF03EC5A832C1C9 FOREIGN KEY (email_id) REFERENCES cms_emails (id)', $sql[8]);
        $this->assertEquals('ALTER TABLE cms_users_groups ADD CONSTRAINT FK_7EA9409AA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)', $sql[9]);
        $this->assertEquals('ALTER TABLE cms_users_groups ADD CONSTRAINT FK_7EA9409AFE54D947 FOREIGN KEY (group_id) REFERENCES cms_groups (id)', $sql[10]);
        $this->assertEquals('ALTER TABLE cms_users_tags ADD CONSTRAINT FK_93F5A1ADA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)', $sql[11]);
        $this->assertEquals('ALTER TABLE cms_users_tags ADD CONSTRAINT FK_93F5A1ADBAD26311 FOREIGN KEY (tag_id) REFERENCES cms_tags (id)', $sql[12]);
        $this->assertEquals('ALTER TABLE cms_addresses ADD CONSTRAINT FK_ACAC157BA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)', $sql[13]);
        $this->assertEquals('ALTER TABLE cms_phonenumbers ADD CONSTRAINT FK_F21F790FA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id)', $sql[14]);

        $this->assertEquals(15, count($sql));
    }

    private function getColumnCollationDeclarationSQL(string $collation): string
    {
        if (method_exists($this->_em->getConnection()->getDatabasePlatform(), 'getColumnCollationDeclarationSQL')) {
            return $this->_em->getConnection()->getDatabasePlatform()->getColumnCollationDeclarationSQL($collation);
        }

        return sprintf('COLLATE %s', $collation);
    }

    public function testGetCreateSchemaSql2(): void
    {
        $classes = [$this->_em->getClassMetadata(Models\Generic\DecimalModel::class)];

        $tool      = new SchemaTool($this->_em);
        $sql       = $tool->getCreateSchemaSql($classes);
        $collation = $this->getColumnCollationDeclarationSQL('utf8_unicode_ci');

        $this->assertEquals(1, count($sql));
        $this->assertEquals('CREATE TABLE decimal_model (id INT AUTO_INCREMENT NOT NULL, `decimal` NUMERIC(5, 2) NOT NULL, `high_scale` NUMERIC(14, 4) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[0]);
    }

    public function testGetCreateSchemaSql3(): void
    {
        $classes = [$this->_em->getClassMetadata(Models\Generic\BooleanModel::class)];

        $tool      = new SchemaTool($this->_em);
        $sql       = $tool->getCreateSchemaSql($classes);
        $collation = $this->getColumnCollationDeclarationSQL('utf8_unicode_ci');

        $this->assertEquals(1, count($sql));
        $this->assertEquals('CREATE TABLE boolean_model (id INT AUTO_INCREMENT NOT NULL, booleanField TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[0]);
    }

    /**
     * @group DBAL-204
     */
    public function testGetCreateSchemaSql4(): void
    {
        $classes = [$this->_em->getClassMetadata(MysqlSchemaNamespacedEntity::class)];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);

        $this->assertEquals(0, count($sql));
    }
}

/**
 * @Entity
 * @Table("namespace.entity")
 */
class MysqlSchemaNamespacedEntity
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     */
    public $id;
}
