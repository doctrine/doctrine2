<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1360
 */
class DDC1360Test extends OrmFunctionalTestCase
{
    public function testSchemaDoubleQuotedCreate(): void
    {
        if ($this->_em->getConnection()->getDatabasePlatform()->getName() !== 'postgresql') {
            $this->markTestSkipped('PostgreSQL only test.');
        }

        $sql = $this->_schemaTool->getCreateSchemaSql(
            [
                $this->_em->getClassMetadata(DDC1360DoubleQuote::class),
            ]
        );

        $this->assertEquals(
            [
                'CREATE SCHEMA user',
                'CREATE TABLE "user"."user" (id INT NOT NULL, PRIMARY KEY(id))',
                'CREATE SEQUENCE "user"."user_id_seq" INCREMENT BY 1 MINVALUE 1 START 1',
            ],
            $sql
        );
    }
}

/**
 * @Entity @Table(name="`user`.`user`")
 */
class DDC1360DoubleQuote
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;
}
