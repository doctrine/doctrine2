<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\SchemaTool;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Tools;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_filter;
use function count;
use function strpos;

class DBAL483Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_em->getConnection();

        $this->schemaTool = new Tools\SchemaTool($this->_em);
    }

    /**
     * @group DBAL-483
     */
    public function testDefaultValueIsComparedCorrectly(): void
    {
        $class = $this->_em->getClassMetadata(DBAL483Default::class);

        $this->schemaTool->createSchema([$class]);

        $updateSql = $this->schemaTool->getUpdateSchemaSql([$class]);

        $updateSql = array_filter($updateSql, static function ($sql) {
            return strpos($sql, 'DBAL483') !== false;
        });

        $this->assertEquals(0, count($updateSql));
    }
}

/**
 * @Entity
 */
class DBAL483Default
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var int
     * @Column(type="integer", options={"default": 0})
     */
    public $num;

    /**
     * @var string
     * @Column(type="string", options={"default": "foo"})
     */
    public $str = 'foo';
}
