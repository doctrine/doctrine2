<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function get_class;

/**
 * Tests that join columns (foreign keys) can be named the same as the association
 * fields they're used on without causing issues.
 */
class DDC522Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC522Customer::class),
                    $this->_em->getClassMetadata(DDC522Cart::class),
                    $this->_em->getClassMetadata(DDC522ForeignKeyTest::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    /**
     * @group DDC-522
     */
    public function testJoinColumnWithSameNameAsAssociationField(): void
    {
        $cust           = new DDC522Customer();
        $cust->name     = 'name';
        $cart           = new DDC522Cart();
        $cart->total    = 0;
        $cust->cart     = $cart;
        $cart->customer = $cust;
        $this->_em->persist($cust);
        $this->_em->persist($cart);
        $this->_em->flush();

        $this->_em->clear();

        $r = $this->_em->createQuery('select ca,c from ' . DDC522Cart::class . ' ca join ca.customer c')
                       ->getResult();

        $this->assertInstanceOf(DDC522Cart::class, $r[0]);
        $this->assertInstanceOf(DDC522Customer::class, $r[0]->customer);
        $this->assertNotInstanceOf(Proxy::class, $r[0]->customer);
        $this->assertEquals('name', $r[0]->customer->name);

        $fkt         = new DDC522ForeignKeyTest();
        $fkt->cartId = $r[0]->id; // ignored for persistence
        $fkt->cart   = $r[0]; // must be set properly
        $this->_em->persist($fkt);
        $this->_em->flush();
        $this->_em->clear();

        $fkt2 = $this->_em->find(get_class($fkt), $fkt->id);
        $this->assertEquals($fkt->cart->id, $fkt2->cartId);
        $this->assertInstanceOf(Proxy::class, $fkt2->cart);
        $this->assertFalse($fkt2->cart->__isInitialized__);
    }

    /**
     * @group DDC-522
     * @group DDC-762
     */
    public function testJoinColumnWithNullSameNameAssociationField(): void
    {
        $fkCust       = new DDC522ForeignKeyTest();
        $fkCust->name = 'name';
        $fkCust->cart = null;

        $this->_em->persist($fkCust);
        $this->_em->flush();
        $this->_em->clear();

        $expected = clone $fkCust;
        // removing dynamic field (which is not persisted)
        unset($expected->name);

        self::assertEquals($expected, $this->_em->find(DDC522ForeignKeyTest::class, $fkCust->id));
    }
}

/** @Entity */
class DDC522Customer
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var mixed
     * @Column
     */
    public $name;

    /**
     * @var DDC522Cart
     * @OneToOne(targetEntity="DDC522Cart", mappedBy="customer")
     */
    public $cart;
}

/** @Entity */
class DDC522Cart
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
     * @Column(type="integer")
     */
    public $total;

    /**
     * @var DDC522Customer
     * @OneToOne(targetEntity="DDC522Customer", inversedBy="cart")
     * @JoinColumn(name="customer", referencedColumnName="id")
     */
    public $customer;
}

/** @Entity */
class DDC522ForeignKeyTest
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
     * @Column(type="integer", name="cart_id", nullable=true)
     */
    public $cartId;

    /**
     * @var DDC522Cart
     * @OneToOne(targetEntity="DDC522Cart")
     * @JoinColumn(name="cart_id", referencedColumnName="id")
     */
    public $cart;
}
