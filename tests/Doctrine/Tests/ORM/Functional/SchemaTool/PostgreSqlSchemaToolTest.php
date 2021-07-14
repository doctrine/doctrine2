<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\SchemaTool;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\Models;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_filter;
use function array_shift;
use function count;
use function implode;
use function strpos;

class PostgreSqlSchemaToolTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'postgresql') {
            $this->markTestSkipped('The ' . self::class . ' requires the use of postgresql.');
        }
    }

    public function testPostgresMetadataSequenceIncrementedBy10(): void
    {
        $address = $this->_em->getClassMetadata(Models\CMS\CmsAddress::class);

        $this->assertEquals(1, $address->sequenceGeneratorDefinition['allocationSize']);
    }

    public function testGetCreateSchemaSql(): void
    {
        $classes = [
            $this->_em->getClassMetadata(Models\CMS\CmsAddress::class),
            $this->_em->getClassMetadata(Models\CMS\CmsUser::class),
            $this->_em->getClassMetadata(Models\CMS\CmsPhonenumber::class),
        ];

        $tool     = new SchemaTool($this->_em);
        $sql      = $tool->getCreateSchemaSql($classes);
        $sqlCount = count($sql);

        $this->assertEquals('CREATE TABLE cms_addresses (id INT NOT NULL, user_id INT DEFAULT NULL, country VARCHAR(50) NOT NULL, zip VARCHAR(50) NOT NULL, city VARCHAR(50) NOT NULL, PRIMARY KEY(id))', array_shift($sql));
        $this->assertEquals('CREATE UNIQUE INDEX UNIQ_ACAC157BA76ED395 ON cms_addresses (user_id)', array_shift($sql));
        $this->assertEquals('CREATE TABLE cms_users (id INT NOT NULL, email_id INT DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, username VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))', array_shift($sql));
        $this->assertEquals('CREATE UNIQUE INDEX UNIQ_3AF03EC5F85E0677 ON cms_users (username)', array_shift($sql));
        $this->assertEquals('CREATE UNIQUE INDEX UNIQ_3AF03EC5A832C1C9 ON cms_users (email_id)', array_shift($sql));
        $this->assertEquals('CREATE TABLE cms_users_groups (user_id INT NOT NULL, group_id INT NOT NULL, PRIMARY KEY(user_id, group_id))', array_shift($sql));
        $this->assertEquals('CREATE INDEX IDX_7EA9409AA76ED395 ON cms_users_groups (user_id)', array_shift($sql));
        $this->assertEquals('CREATE INDEX IDX_7EA9409AFE54D947 ON cms_users_groups (group_id)', array_shift($sql));
        $this->assertEquals('CREATE TABLE cms_users_tags (user_id INT NOT NULL, tag_id INT NOT NULL, PRIMARY KEY(user_id, tag_id))', array_shift($sql));
        $this->assertEquals('CREATE INDEX IDX_93F5A1ADA76ED395 ON cms_users_tags (user_id)', array_shift($sql));
        $this->assertEquals('CREATE INDEX IDX_93F5A1ADBAD26311 ON cms_users_tags (tag_id)', array_shift($sql));
        $this->assertEquals('CREATE TABLE cms_phonenumbers (phonenumber VARCHAR(50) NOT NULL, user_id INT DEFAULT NULL, PRIMARY KEY(phonenumber))', array_shift($sql));
        $this->assertEquals('CREATE INDEX IDX_F21F790FA76ED395 ON cms_phonenumbers (user_id)', array_shift($sql));
        $this->assertEquals('CREATE SEQUENCE cms_addresses_id_seq INCREMENT BY 1 MINVALUE 1 START 1', array_shift($sql));
        $this->assertEquals('CREATE SEQUENCE cms_users_id_seq INCREMENT BY 1 MINVALUE 1 START 1', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_addresses ADD CONSTRAINT FK_ACAC157BA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_users ADD CONSTRAINT FK_3AF03EC5A832C1C9 FOREIGN KEY (email_id) REFERENCES cms_emails (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_users_groups ADD CONSTRAINT FK_7EA9409AA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_users_groups ADD CONSTRAINT FK_7EA9409AFE54D947 FOREIGN KEY (group_id) REFERENCES cms_groups (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_users_tags ADD CONSTRAINT FK_93F5A1ADA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_users_tags ADD CONSTRAINT FK_93F5A1ADBAD26311 FOREIGN KEY (tag_id) REFERENCES cms_tags (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));
        $this->assertEquals('ALTER TABLE cms_phonenumbers ADD CONSTRAINT FK_F21F790FA76ED395 FOREIGN KEY (user_id) REFERENCES cms_users (id) NOT DEFERRABLE INITIALLY IMMEDIATE', array_shift($sql));

        $this->assertEquals([], $sql, 'SQL Array should be empty now.');
        $this->assertEquals(22, $sqlCount, 'Total of 22 queries should be executed');
    }

    public function testGetCreateSchemaSql2(): void
    {
        $classes = [$this->_em->getClassMetadata(Models\Generic\DecimalModel::class)];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);

        $this->assertEquals(2, count($sql));

        $this->assertEquals('CREATE TABLE decimal_model (id INT NOT NULL, "decimal" NUMERIC(5, 2) NOT NULL, "high_scale" NUMERIC(14, 4) NOT NULL, PRIMARY KEY(id))', $sql[0]);
        $this->assertEquals('CREATE SEQUENCE decimal_model_id_seq INCREMENT BY 1 MINVALUE 1 START 1', $sql[1]);
    }

    public function testGetCreateSchemaSql3(): void
    {
        $classes = [$this->_em->getClassMetadata(Models\Generic\BooleanModel::class)];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getCreateSchemaSql($classes);

        $this->assertEquals(2, count($sql));
        $this->assertEquals('CREATE TABLE boolean_model (id INT NOT NULL, booleanField BOOLEAN NOT NULL, PRIMARY KEY(id))', $sql[0]);
        $this->assertEquals('CREATE SEQUENCE boolean_model_id_seq INCREMENT BY 1 MINVALUE 1 START 1', $sql[1]);
    }

    public function testGetDropSchemaSql(): void
    {
        $classes = [
            $this->_em->getClassMetadata(Models\CMS\CmsAddress::class),
            $this->_em->getClassMetadata(Models\CMS\CmsUser::class),
            $this->_em->getClassMetadata(Models\CMS\CmsPhonenumber::class),
        ];

        $tool = new SchemaTool($this->_em);
        $sql  = $tool->getDropSchemaSQL($classes);

        $this->assertEquals(17, count($sql));

        $dropSequenceSQLs = 0;

        foreach ($sql as $stmt) {
            if (strpos($stmt, 'DROP SEQUENCE') === 0) {
                $dropSequenceSQLs++;
            }
        }

        $this->assertEquals(4, $dropSequenceSQLs, 'Expect 4 sequences to be dropped.');
    }

    /**
     * @group DDC-1657
     */
    public function testUpdateSchemaWithPostgreSQLSchema(): void
    {
        $classes = [
            $this->_em->getClassMetadata(DDC1657Screen::class),
            $this->_em->getClassMetadata(DDC1657Avatar::class),
        ];

        $tool = new SchemaTool($this->_em);
        $tool->createSchema($classes);

        $sql = $tool->getUpdateSchemaSql($classes);
        $sql = array_filter($sql, static function ($sql) {
            return strpos($sql, 'DROP SEQUENCE stonewood.') === 0;
        });

        $this->assertCount(0, $sql, implode("\n", $sql));
    }
}

/**
 * @Entity
 * @Table(name="stonewood.screen")
 */
class DDC1657Screen
{
    /**
     * Identifier
     *
     * @var int
     * @Id
     * @GeneratedValue(strategy="IDENTITY")
     * @Column(name="pk", type="integer", nullable=false)
     */
    private $pk;

    /**
     * Title
     *
     * @var string
     * @Column(name="title", type="string", length=255, nullable=false)
     */
    private $title;

    /**
     * Path
     *
     * @var string
     * @Column(name="path", type="string", length=255, nullable=false)
     */
    private $path;

    /**
     * Register date
     *
     * @var Date
     * @Column(name="ddate", type="date", nullable=false)
     */
    private $ddate;

    /**
     * Avatar
     *
     * @var Stonewood\Model\Entity\Avatar
     * @ManyToOne(targetEntity="DDC1657Avatar")
     * @JoinColumn(name="pk_avatar", referencedColumnName="pk", nullable=true, onDelete="CASCADE")
     */
    private $avatar;
}

/**
 * @Entity
 * @Table(name="stonewood.avatar")
 */
class DDC1657Avatar
{
    /**
     * Identifier
     *
     * @var int
     * @Id
     * @GeneratedValue(strategy="IDENTITY")
     * @Column(name="pk", type="integer", nullable=false)
     */
    private $pk;
}
