<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

final class GH6531Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEntitySchema(
            [
                GH6531User::class,
                GH6531Address::class,
                GH6531Article::class,
                GH6531ArticleAttribute::class,
                GH6531Order::class,
                GH6531OrderItem::class,
                GH6531Product::class,
            ]
        );
    }

    /**
     * @group GH-6531
     */
    public function testSimpleDerivedIdentity(): void
    {
        $user          = new GH6531User();
        $address       = new GH6531Address();
        $address->user = $user;

        $this->_em->persist($user);
        $this->_em->persist($address);
        $this->_em->flush();

        self::assertSame($user, $this->_em->find(GH6531User::class, $user->id));
        self::assertSame($address, $this->_em->find(GH6531Address::class, $user));
    }

    /**
     * @group GH-6531
     */
    public function testDynamicAttributes(): void
    {
        $article = new GH6531Article();
        $article->addAttribute('name', 'value');

        $this->_em->persist($article);
        $this->_em->flush();

        self::assertSame(
            $article->attributes['name'],
            $this->_em->find(GH6531ArticleAttribute::class, ['article' => $article, 'attribute' => 'name'])
        );
    }

    /**
     * @group GH-6531
     */
    public function testJoinTableWithMetadata(): void
    {
        $product = new GH6531Product();
        $this->_em->persist($product);
        $this->_em->flush();

        $order = new GH6531Order();
        $order->addItem($product, 2);

        $this->_em->persist($order);
        $this->_em->flush();

        self::assertSame(
            $order->items->first(),
            $this->_em->find(GH6531OrderItem::class, ['product' => $product, 'order' => $order])
        );
    }
}

/**
 * @Entity
 */
class GH6531User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class GH6531Address
{
    /**
     * @var GH6531User
     * @Id
     * @OneToOne(targetEntity=GH6531User::class)
     */
    public $user;
}

/**
 * @Entity
 */
class GH6531Article
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<string, GH6531ArticleAttribute>
     * @OneToMany(targetEntity=GH6531ArticleAttribute::class, mappedBy="article", cascade={"ALL"}, indexBy="attribute")
     * */
    public $attributes;

    public function addAttribute(string $name, string $value): void
    {
        $this->attributes[$name] = new GH6531ArticleAttribute($name, $value, $this);
    }
}

/**
 * @Entity
 */
class GH6531ArticleAttribute
{
    /**
     * @var GH6531Article
     * @Id
     * @ManyToOne(targetEntity=GH6531Article::class, inversedBy="attributes")
     */
    public $article;

    /**
     * @var string
     * @Id
     * @Column(type="string")
     */
    public $attribute;

    /**
     * @var string
     * @Column(type="string")
     */
    public $value;

    public function __construct(string $name, string $value, GH6531Article $article)
    {
        $this->attribute = $name;
        $this->value     = $value;
        $this->article   = $article;
    }
}

/**
 * @Entity
 */
class GH6531Order
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, GH6531OrderItem>
     * @OneToMany(targetEntity=GH6531OrderItem::class, mappedBy="order", cascade={"ALL"})
     */
    public $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function addItem(GH6531Product $product, int $amount): void
    {
        $this->items->add(new GH6531OrderItem($this, $product, $amount));
    }
}

/**
 * @Entity
 */
class GH6531Product
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class GH6531OrderItem
{
    /**
     * @var GH6531Order
     * @Id
     * @ManyToOne(targetEntity=GH6531Order::class)
     */
    public $order;

    /**
     * @var GH6531Product
     * @Id @ManyToOne(targetEntity=GH6531Product::class)
     */
    public $product;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $amount = 1;

    public function __construct(GH6531Order $order, GH6531Product $product, int $amount = 1)
    {
        $this->order   = $order;
        $this->product = $product;
        $this->amount  = $amount;
    }
}
