<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

use function count;

/**
 * @group DDC-1430
 */
class DDC1430Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1430Order::class),
                    $this->_em->getClassMetadata(DDC1430OrderProduct::class),
                ]
            );
            $this->loadFixtures();
        } catch (Exception $exc) {
        }
    }

    public function testOrderByFields(): void
    {
        $repository = $this->_em->getRepository(DDC1430Order::class);
        $builder    = $repository->createQueryBuilder('o');
        $query      = $builder->select('o.id, o.date, COUNT(p.id) AS p_count')
                        ->leftJoin('o.products', 'p')
                        ->groupBy('o.id, o.date')
                        ->orderBy('o.id')
                        ->getQuery();

        $this->assertSQLEquals('SELECT o.id, o.date, COUNT(p.id) AS p_count FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1430Order o LEFT JOIN o.products p GROUP BY o.id, o.date ORDER BY o.id ASC', $query->getDQL());
        $this->assertSQLEquals('SELECT d0_.order_id AS order_id_0, d0_.created_at AS created_at_1, COUNT(d1_.id) AS sclr_2 FROM DDC1430Order d0_ LEFT JOIN DDC1430OrderProduct d1_ ON d0_.order_id = d1_.order_id GROUP BY d0_.order_id, d0_.created_at ORDER BY d0_.order_id ASC', $query->getSQL());

        $result = $query->getResult();

        $this->assertEquals(2, count($result));

        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('id', $result[1]);

        $this->assertArrayHasKey('p_count', $result[0]);
        $this->assertArrayHasKey('p_count', $result[1]);

        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(2, $result[1]['id']);

        $this->assertEquals(2, $result[0]['p_count']);
        $this->assertEquals(3, $result[1]['p_count']);
    }

    public function testOrderByAllObjectFields(): void
    {
        $repository = $this->_em->getRepository(DDC1430Order::class);
        $builder    = $repository->createQueryBuilder('o');
        $query      = $builder->select('o, COUNT(p.id) AS p_count')
                        ->leftJoin('o.products', 'p')
                        ->groupBy('o.id, o.date, o.status')
                        ->orderBy('o.id')
                        ->getQuery();

        $this->assertSQLEquals('SELECT o, COUNT(p.id) AS p_count FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1430Order o LEFT JOIN o.products p GROUP BY o.id, o.date, o.status ORDER BY o.id ASC', $query->getDQL());
        $this->assertSQLEquals('SELECT d0_.order_id AS order_id_0, d0_.created_at AS created_at_1, d0_.order_status AS order_status_2, COUNT(d1_.id) AS sclr_3 FROM DDC1430Order d0_ LEFT JOIN DDC1430OrderProduct d1_ ON d0_.order_id = d1_.order_id GROUP BY d0_.order_id, d0_.created_at, d0_.order_status ORDER BY d0_.order_id ASC', $query->getSQL());

        $result = $query->getResult();

        $this->assertEquals(2, count($result));

        $this->assertTrue($result[0][0] instanceof DDC1430Order);
        $this->assertTrue($result[1][0] instanceof DDC1430Order);

        $this->assertEquals($result[0][0]->getId(), 1);
        $this->assertEquals($result[1][0]->getId(), 2);

        $this->assertEquals($result[0]['p_count'], 2);
        $this->assertEquals($result[1]['p_count'], 3);
    }

    public function testTicket(): void
    {
        $repository = $this->_em->getRepository(DDC1430Order::class);
        $builder    = $repository->createQueryBuilder('o');
        $query      = $builder->select('o, COUNT(p.id) AS p_count')
                        ->leftJoin('o.products', 'p')
                        ->groupBy('o')
                        ->orderBy('o.id')
                        ->getQuery();

        $this->assertSQLEquals('SELECT o, COUNT(p.id) AS p_count FROM Doctrine\Tests\ORM\Functional\Ticket\DDC1430Order o LEFT JOIN o.products p GROUP BY o ORDER BY o.id ASC', $query->getDQL());
        $this->assertSQLEquals('SELECT d0_.order_id AS order_id_0, d0_.created_at AS created_at_1, d0_.order_status AS order_status_2, COUNT(d1_.id) AS sclr_3 FROM DDC1430Order d0_ LEFT JOIN DDC1430OrderProduct d1_ ON d0_.order_id = d1_.order_id GROUP BY d0_.order_id, d0_.created_at, d0_.order_status ORDER BY d0_.order_id ASC', $query->getSQL());

        $result = $query->getResult();

        $this->assertEquals(2, count($result));

        $this->assertTrue($result[0][0] instanceof DDC1430Order);
        $this->assertTrue($result[1][0] instanceof DDC1430Order);

        $this->assertEquals($result[0][0]->getId(), 1);
        $this->assertEquals($result[1][0]->getId(), 2);

        $this->assertEquals($result[0]['p_count'], 2);
        $this->assertEquals($result[1]['p_count'], 3);
    }

    public function loadFixtures(): void
    {
        $o1 = new DDC1430Order('NEW');
        $o2 = new DDC1430Order('OK');

        $o1->addProduct(new DDC1430OrderProduct(1.1));
        $o1->addProduct(new DDC1430OrderProduct(1.2));

        $o2->addProduct(new DDC1430OrderProduct(2.1));
        $o2->addProduct(new DDC1430OrderProduct(2.2));
        $o2->addProduct(new DDC1430OrderProduct(2.3));

        $this->_em->persist($o1);
        $this->_em->persist($o2);

        $this->_em->flush();
    }
}

/**
 * @Entity
 */
class DDC1430Order
{
    /**
     * @var int
     * @Id
     * @Column(name="order_id", type="integer")
     * @GeneratedValue()
     */
    protected $id;

    /**
     * @var DateTime
     * @Column(name="created_at", type="datetime")
     */
    private $date;

    /**
     * @var string
     * @Column(name="order_status", type="string")
     */
    private $status;

    /**
     * @OneToMany(targetEntity="DDC1430OrderProduct", mappedBy="order", cascade={"persist", "remove"})
     * @var ArrayCollection $products
     */
    private $products;

    public function getId(): int
    {
        return $this->id;
    }

    public function __construct(string $status)
    {
        $this->status   = $status;
        $this->date     = new DateTime();
        $this->products = new ArrayCollection();
    }

    public function getDate(): DateTime
    {
        return $this->date;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getProducts(): ArrayCollection
    {
        return $this->products;
    }

    public function addProduct(DDC1430OrderProduct $product): void
    {
        $product->setOrder($this);
        $this->products->add($product);
    }
}

/**
 * @Entity
 */
class DDC1430OrderProduct
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue()
     */
    protected $id;

    /**
     * @var DDC1430Order $order
     * @ManyToOne(targetEntity="DDC1430Order", inversedBy="products")
     * @JoinColumn(name="order_id", referencedColumnName="order_id", nullable = false)
     */
    private $order;

    /**
     * @var float
     * @Column(type="float")
     */
    private $value;

    public function __construct(float $value)
    {
        $this->value = $value;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrder(): DDC1430Order
    {
        return $this->order;
    }

    public function setOrder(DDC1430Order $order): void
    {
        $this->order = $order;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function setValue(float $value): void
    {
        $this->value = $value;
    }
}
