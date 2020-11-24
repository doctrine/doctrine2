<?php

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Proxy\Proxy;
use Doctrine\Tests\Models\ECommerce\ECommerceCart;
use Doctrine\Tests\Models\ECommerce\ECommerceCustomer;
use Doctrine\Tests\OrmFunctionalTestCase;

/**
 * Tests a bidirectional one-to-one association mapping (without inheritance).
 */
class OneToOneBidirectionalAssociationTest extends OrmFunctionalTestCase
{
    private $customer;
    private $cart;

    protected function setUp() : void
    {
        $this->useModelSet('ecommerce');
        parent::setUp();
        $this->customer = new ECommerceCustomer();
        $this->customer->setName('John Doe');
        $this->cart = new ECommerceCart();
        $this->cart->setPayment('Credit card');
    }

    public function testSavesAOneToOneAssociationWithCascadeSaveSet() {
        $this->customer->setCart($this->cart);
        $this->_em->persist($this->customer);
        $this->_em->flush();

        $this->assertCartForeignKeyIs($this->customer->getId());
    }

    public function testDoesNotSaveAnInverseSideSet() {
        $this->customer->brokenSetCart($this->cart);
        $this->_em->persist($this->customer);
        $this->_em->flush();

        $this->assertCartForeignKeyIs(null);
    }

    public function testRemovesOneToOneAssociation()
    {
        $this->customer->setCart($this->cart);
        $this->_em->persist($this->customer);
        $this->customer->removeCart();

        $this->_em->flush();

        $this->assertCartForeignKeyIs(null);
    }

    public function testEagerLoad()
    {
        $this->_createFixture();

        $query = $this->_em->createQuery('select c, ca from Doctrine\Tests\Models\ECommerce\ECommerceCustomer c join c.cart ca');
        $result = $query->getResult();
        $customer = $result[0];

        $this->assertInstanceOf(ECommerceCart::class, $customer->getCart());
        $this->assertEquals('paypal', $customer->getCart()->getPayment());
    }

    public function testLazyLoadsObjectsOnTheOwningSide() {
        $this->_createFixture();
        $metadata = $this->_em->getClassMetadata(ECommerceCart::class);
        $metadata->associationMappings['customer']['fetchMode'] = ClassMetadata::FETCH_LAZY;

        $query = $this->_em->createQuery('select c from Doctrine\Tests\Models\ECommerce\ECommerceCart c');
        $result = $query->getResult();
        $cart = $result[0];

        $this->assertInstanceOf(ECommerceCustomer::class, $cart->getCustomer());
        $this->assertEquals('Giorgio', $cart->getCustomer()->getName());
    }

    public function testInverseSideIsNeverLazy()
    {
        $this->_createFixture();
        $metadata = $this->_em->getClassMetadata(ECommerceCustomer::class);
        $metadata->associationMappings['mentor']['fetch'] = ClassMetadata::FETCH_EAGER;

        $query = $this->_em->createQuery('select c from Doctrine\Tests\Models\ECommerce\ECommerceCustomer c');
        $result = $query->getResult();
        $customer = $result[0];

        $this->assertNull($customer->getMentor());
        $this->assertInstanceOf(ECommerceCart::class, $customer->getCart());
        $this->assertNotInstanceOf(Proxy::class, $customer->getCart());
        $this->assertEquals('paypal', $customer->getCart()->getPayment());
    }

    public function testUpdateWithProxyObject()
    {
        $cust = new ECommerceCustomer;
        $cust->setName('Roman');
        $cart = new ECommerceCart;
        $cart->setPayment('CARD');
        $cust->setCart($cart);

        $this->_em->persist($cust);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertInstanceOf(ECommerceCart::class, $cust->getCart());
        $this->assertEquals('Roman', $cust->getName());
        $this->assertSame($cust, $cart->getCustomer());

        $query = $this->_em->createQuery('select ca from Doctrine\Tests\Models\ECommerce\ECommerceCart ca where ca.id =?1');
        $query->setParameter(1, $cart->getId());

        $cart2 = $query->getSingleResult();

        $cart2->setPayment('CHEQUE');

        $this->_em->flush();
        $this->_em->clear();

        $query2 = $this->_em->createQuery('select ca, c from Doctrine\Tests\Models\ECommerce\ECommerceCart ca left join ca.customer c where ca.id =?1');
        $query2->setParameter(1, $cart->getId());

        $cart3 = $query2->getSingleResult();

        $this->assertInstanceOf(ECommerceCustomer::class, $cart3->getCustomer());
        $this->assertEquals('Roman', $cart3->getCustomer()->getName());
    }

    protected function _createFixture()
    {
        $customer = new ECommerceCustomer;
        $customer->setName('Giorgio');
        $cart = new ECommerceCart;
        $cart->setPayment('paypal');
        $customer->setCart($cart);

        $this->_em->persist($customer);

        $this->_em->flush();
        $this->_em->clear();
    }

    public function assertCartForeignKeyIs($value) {
        $foreignKey = $this->_em->getConnection()->executeQuery('SELECT customer_id FROM ecommerce_carts WHERE id=?', [$this->cart->getId()]
        )->fetchOne();
        $this->assertEquals($value, $foreignKey);
    }
}
