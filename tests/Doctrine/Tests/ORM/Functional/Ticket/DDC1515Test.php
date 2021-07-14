<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * @group DDC-1515
 */
class DDC1515Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1515Foo::class),
                $this->_em->getClassMetadata(DDC1515Bar::class),
            ]
        );
    }

    public function testIssue(): void
    {
        $bar = new DDC1515Bar();
        $this->_em->persist($bar);
        $this->_em->flush();

        $foo      = new DDC1515Foo();
        $foo->bar = $bar;
        $this->_em->persist($foo);
        $this->_em->flush();
        $this->_em->clear();

        $bar = $this->_em->find(DDC1515Bar::class, $bar->id);
        $this->assertInstanceOf(DDC1515Foo::class, $bar->foo);
    }
}

/**
 * @Entity
 */
class DDC1515Foo
{
    /**
     * @var DDC1515Bar
     * @OneToOne(targetEntity="DDC1515Bar", inversedBy="foo") @Id
     */
    public $bar;
}

/**
 * @Entity
 */
class DDC1515Bar
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var DDC1515Foo
     * @OneToOne(targetEntity="DDC1515Foo", mappedBy="bar")
     */
    public $foo;
}
