<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-3042
 */
class DDC3042Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC3042Foo::class),
                $this->_em->getClassMetadata(DDC3042Bar::class),
            ]
        );
    }

    public function testSQLGenerationDoesNotProvokeAliasCollisions(): void
    {
        $this->assertStringNotMatchesFormat(
            '%sfield11%sfield11%s',
            $this
                ->_em
                ->createQuery(
                    'SELECT f, b FROM ' . __NAMESPACE__ . '\DDC3042Foo f JOIN ' . __NAMESPACE__ . '\DDC3042Bar b WITH 1 = 1'
                )
                ->getSQL()
        );
    }
}

/**
 * @Entity
 */
class DDC3042Foo
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $field;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field1;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field2;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field3;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field4;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field5;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field6;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field7;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field8;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field9;
    /**
     * @var int
     * @Column(type="integer")
     */
    public $field10;
}

/**
 * @Entity
 */
class DDC3042Bar
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $field;
}
