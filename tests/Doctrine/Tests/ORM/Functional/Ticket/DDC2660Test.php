<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-2660
 */
class DDC2660Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC2660Product::class),
                    $this->_em->getClassMetadata(DDC2660Customer::class),
                    $this->_em->getClassMetadata(DDC2660CustomerOrder::class),
                ]
            );
        } catch (Exception $e) {
            return;
        }

        for ($i = 0; $i < 5; $i++) {
            $product  = new DDC2660Product();
            $customer = new DDC2660Customer();
            $order    = new DDC2660CustomerOrder($product, $customer, 'name' . $i);

            $this->_em->persist($product);
            $this->_em->persist($customer);
            $this->_em->flush();

            $this->_em->persist($order);
            $this->_em->flush();
        }

        $this->_em->clear();
    }

    public function testIssueWithExtraColumn(): void
    {
        $sql = 'SELECT o.product_id, o.customer_id, o.name FROM ddc_2660_customer_order o';

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(DDC2660CustomerOrder::class, 'c');

        $query  = $this->_em->createNativeQuery($sql, $rsm);
        $result = $query->getResult();

        $this->assertCount(5, $result);

        foreach ($result as $order) {
            $this->assertNotNull($order);
            $this->assertInstanceOf(DDC2660CustomerOrder::class, $order);
        }
    }

    public function testIssueWithoutExtraColumn(): void
    {
        $sql = 'SELECT o.product_id, o.customer_id FROM ddc_2660_customer_order o';

        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(DDC2660CustomerOrder::class, 'c');

        $query  = $this->_em->createNativeQuery($sql, $rsm);
        $result = $query->getResult();

        $this->assertCount(5, $result);

        foreach ($result as $order) {
            $this->assertNotNull($order);
            $this->assertInstanceOf(DDC2660CustomerOrder::class, $order);
        }
    }
}
/**
 * @Entity @Table(name="ddc_2660_product")
 */
class DDC2660Product
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/** @Entity  @Table(name="ddc_2660_customer") */
class DDC2660Customer
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/** @Entity @Table(name="ddc_2660_customer_order") */
class DDC2660CustomerOrder
{
    /**
     * @var DDC2660Product
     * @Id
     * @ManyToOne(targetEntity="DDC2660Product")
     */
    public $product;

    /**
     * @var DDC2660Customer
     * @Id
     * @ManyToOne(targetEntity="DDC2660Customer")
     */
    public $customer;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    public function __construct(DDC2660Product $product, DDC2660Customer $customer, $name)
    {
        $this->product  = $product;
        $this->customer = $customer;
        $this->name     = $name;
    }
}
