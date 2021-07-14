<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;

/**
 * @group DDC-1080
 */
class DDC1080Test extends OrmFunctionalTestCase
{
    public function testHydration(): void
    {
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1080Foo::class),
                $this->_em->getClassMetadata(DDC1080Bar::class),
                $this->_em->getClassMetadata(DDC1080FooBar::class),
            ]
        );

        $foo1 = new DDC1080Foo();
        $foo1->setFooTitle('foo title 1');
        $foo2 = new DDC1080Foo();
        $foo2->setFooTitle('foo title 2');

        $bar1 = new DDC1080Bar();
        $bar1->setBarTitle('bar title 1');
        $bar2 = new DDC1080Bar();
        $bar2->setBarTitle('bar title 2');
        $bar3 = new DDC1080Bar();
        $bar3->setBarTitle('bar title 3');

        $foobar1 = new DDC1080FooBar();
        $foobar1->setFoo($foo1);
        $foobar1->setBar($bar1);
        $foobar1->setOrderNr(0);

        $foobar2 = new DDC1080FooBar();
        $foobar2->setFoo($foo1);
        $foobar2->setBar($bar2);
        $foobar2->setOrderNr(0);

        $foobar3 = new DDC1080FooBar();
        $foobar3->setFoo($foo1);
        $foobar3->setBar($bar3);
        $foobar3->setOrderNr(0);

        $this->_em->persist($foo1);
        $this->_em->persist($foo2);
        $this->_em->persist($bar1);
        $this->_em->persist($bar2);
        $this->_em->persist($bar3);
        $this->_em->flush();

        $this->_em->persist($foobar1);
        $this->_em->persist($foobar2);
        $this->_em->persist($foobar3);
        $this->_em->flush();
        $this->_em->clear();

        $foo     = $this->_em->find(DDC1080Foo::class, $foo1->getFooID());
        $fooBars = $foo->getFooBars();

        $this->assertEquals(3, count($fooBars), 'Should return three foobars.');
    }
}


/**
 * @Entity
 * @Table(name="foo")
 */
class DDC1080Foo
{
    /**
     * @var int
     * @Id
     * @Column(name="fooID", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $fooID;

    /**
     * @var string
     * @Column(name="fooTitle", type="string")
     */
    protected $fooTitle;

    /**
     * @psalm-var Collection<DDC1080FooBar>
     * @OneToMany(targetEntity="DDC1080FooBar", mappedBy="foo",
     * cascade={"persist"})
     * @OrderBy({"orderNr"="ASC"})
     */
    protected $fooBars;

    public function __construct()
    {
        $this->fooBars = new ArrayCollection();
    }

    public function getFooID(): int
    {
        return $this->fooID;
    }

    public function getFooTitle(): string
    {
        return $this->fooTitle;
    }

    /**
     * @psalm-return Collection<DDC1080FooBar>
     */
    public function getFooBars(): Collection
    {
        return $this->fooBars;
    }

    public function setFooID(int $fooID): void
    {
        $this->fooID = $fooID;
    }

    public function setFooTitle(string $fooTitle): void
    {
        $this->fooTitle = $fooTitle;
    }

    public function setFooBars(array $fooBars): void
    {
        $this->fooBars = $fooBars;
    }
}
/**
 * @Entity
 * @Table(name="bar")
 */
class DDC1080Bar
{
    /**
     * @var int
     * @Id
     * @Column(name="barID", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    protected $barID;

    /**
     * @var string
     * @Column(name="barTitle", type="string")
     */
    protected $barTitle;

    /**
     * @psalm-var Collection<DDC1080FooBar>
     * @OneToMany(targetEntity="DDC1080FooBar", mappedBy="bar",
     * cascade={"persist"})
     * @OrderBy({"orderNr"="ASC"})
     */
    protected $fooBars;

    public function __construct()
    {
        $this->fooBars = new ArrayCollection();
    }

    public function getBarID(): int
    {
        return $this->barID;
    }

    public function getBarTitle(): string
    {
        return $this->barTitle;
    }

    /**
     * @psalm-return Collection<DDC1080FooBar>
     */
    public function getFooBars(): Collection
    {
        return $this->fooBars;
    }

    public function setBarID(int $barID): void
    {
        $this->barID = $barID;
    }

    public function setBarTitle(string $barTitle): void
    {
        $this->barTitle = $barTitle;
    }

    public function setFooBars(array $fooBars): void
    {
        $this->fooBars = $fooBars;
    }
}

/**
 * @Table(name="fooBar")
 * @Entity
 */
class DDC1080FooBar
{
    /**
     * @var DDC1080Foo
     * @ManyToOne(targetEntity="DDC1080Foo")
     * @JoinColumn(name="fooID", referencedColumnName="fooID")
     * @Id
     */
    protected $foo = null;

    /**
     * @var DDC1080Bar
     * @ManyToOne(targetEntity="DDC1080Bar")
     * @JoinColumn(name="barID", referencedColumnName="barID")
     * @Id
     */
    protected $bar = null;

    /**
     * @var int orderNr
     * @Column(name="orderNr", type="integer", nullable=false)
     */
    protected $orderNr = null;

    public function getFoo(): DDC1080Foo
    {
        return $this->foo;
    }

    public function setFoo(DDC1080Foo $foo): DDC1080FooBar
    {
        $this->foo = $foo;

        return $this;
    }

    public function getBar(): DDC1080Bar
    {
        return $this->bar;
    }

    public function setBar(DDC1080Bar $bar): DDC1080FooBar
    {
        $this->bar = $bar;

        return $this;
    }

    public function getOrderNr(): ?int
    {
        return $this->orderNr;
    }

    public function setOrderNr(?int $orderNr): DDC1080FooBar
    {
        $this->orderNr = $orderNr;

        return $this;
    }
}
