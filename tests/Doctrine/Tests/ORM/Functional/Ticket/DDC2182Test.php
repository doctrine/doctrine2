<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function method_exists;
use function sprintf;

class DDC2182Test extends OrmFunctionalTestCase
{
    public function testPassColumnOptionsToJoinColumns(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'mysql') {
            $this->markTestSkipped('This test is useful for all databases, but designed only for mysql.');
        }

        $sql       = $this->_schemaTool->getCreateSchemaSql(
            [
                $this->_em->getClassMetadata(DDC2182OptionParent::class),
                $this->_em->getClassMetadata(DDC2182OptionChild::class),
            ]
        );
        $collation = $this->getColumnCollationDeclarationSQL('utf8_unicode_ci');

        $this->assertEquals('CREATE TABLE DDC2182OptionParent (id INT UNSIGNED NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[0]);
        $this->assertEquals('CREATE TABLE DDC2182OptionChild (id VARCHAR(255) NOT NULL, parent_id INT UNSIGNED DEFAULT NULL, INDEX IDX_B314D4AD727ACA70 (parent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 ' . $collation . ' ENGINE = InnoDB', $sql[1]);
        $this->assertEquals('ALTER TABLE DDC2182OptionChild ADD CONSTRAINT FK_B314D4AD727ACA70 FOREIGN KEY (parent_id) REFERENCES DDC2182OptionParent (id)', $sql[2]);
    }

    private function getColumnCollationDeclarationSQL(string $collation): string
    {
        if (method_exists($this->_em->getConnection()->getDatabasePlatform(), 'getColumnCollationDeclarationSQL')) {
            return $this->_em->getConnection()->getDatabasePlatform()->getColumnCollationDeclarationSQL($collation);
        }

        return sprintf('COLLATE %s', $collation);
    }
}

/**
 * @Entity
 * @Table
 */
class DDC2182OptionParent
{
    /**
     * @var int
     * @Id
     * @Column(type="integer", options={"unsigned": true})
     */
    private $id;
}

/**
 * @Entity
 * @Table
 */
class DDC2182OptionChild
{
    /**
     * @var string
     * @Id
     * @Column
     */
    private $id;

    /**
     * @var DDC2182OptionParent
     * @ManyToOne(targetEntity="DDC2182OptionParent")
     * @JoinColumn(referencedColumnName="id")
     */
    private $parent;
}
