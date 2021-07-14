<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function count;
use function get_class;

/**
 * Functional tests for the Class Table Inheritance mapping strategy.
 */
class ClassTableInheritanceSecondTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(CTIParent::class),
            $this->_em->getClassMetadata(CTIChild::class),
            $this->_em->getClassMetadata(CTIRelated::class),
            $this->_em->getClassMetadata(CTIRelated2::class),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->_schemaTool->dropSchema([
            $this->_em->getClassMetadata(CTIParent::class),
            $this->_em->getClassMetadata(CTIChild::class),
            $this->_em->getClassMetadata(CTIRelated::class),
            $this->_em->getClassMetadata(CTIRelated2::class),
        ]);
    }

    public function testOneToOneAssocToBaseTypeBidirectional(): void
    {
        $child = new CTIChild();
        $child->setData('hello');

        $related = new CTIRelated();
        $related->setCTIParent($child);

        $this->_em->persist($related);
        $this->_em->persist($child);

        $this->_em->flush();
        $this->_em->clear();

        $relatedId = $related->getId();

        $related2 = $this->_em->find(CTIRelated::class, $relatedId);

        $this->assertInstanceOf(CTIRelated::class, $related2);
        $this->assertInstanceOf(CTIChild::class, $related2->getCTIParent());
        $this->assertEquals('hello', $related2->getCTIParent()->getData());

        $this->assertSame($related2, $related2->getCTIParent()->getRelated());
    }

    public function testManyToManyToCTIHierarchy(): void
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger());
        $mmrel = new CTIRelated2();
        $child = new CTIChild();
        $child->setData('child');
        $mmrel->addCTIChild($child);

        $this->_em->persist($mmrel);
        $this->_em->persist($child);

        $this->_em->flush();
        $this->_em->clear();

        $mmrel2 = $this->_em->find(get_class($mmrel), $mmrel->getId());
        $this->assertFalse($mmrel2->getCTIChildren()->isInitialized());
        $this->assertEquals(1, count($mmrel2->getCTIChildren()));
        $this->assertTrue($mmrel2->getCTIChildren()->isInitialized());
        $this->assertInstanceOf(CTIChild::class, $mmrel2->getCTIChildren()->get(0));
    }
}

/**
 * @Entity @Table(name="cti_parents")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="type", type="string")
 * @DiscriminatorMap({"parent" = "CTIParent", "child" = "CTIChild"})
 */
class CTIParent
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var CTIRelated
     * @OneToOne(targetEntity="CTIRelated", mappedBy="ctiParent")
     */
    private $related;

    public function getId(): int
    {
        return $this->id;
    }

    public function getRelated(): CTIRelated
    {
        return $this->related;
    }

    public function setRelated(CTIRelated $related): void
    {
        $this->related = $related;
        $related->setCTIParent($this);
    }
}

/**
 * @Entity @Table(name="cti_children")
 */
class CTIChild extends CTIParent
{
    /**
     * @var string
     * @Column(type="string")
     */
    private $data;

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): void
    {
        $this->data = $data;
    }
}

/** @Entity */
class CTIRelated
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var CTIParent
     * @OneToOne(targetEntity="CTIParent")
     * @JoinColumn(name="ctiparent_id", referencedColumnName="id")
     */
    private $ctiParent;

    public function getId(): int
    {
        return $this->id;
    }

    public function getCTIParent(): CTIParent
    {
        return $this->ctiParent;
    }

    public function setCTIParent(CTIParent $ctiParent): void
    {
        $this->ctiParent = $ctiParent;
    }
}

/** @Entity */
class CTIRelated2
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @psalm-var Collection<int, CTIChild>
     * @ManyToMany(targetEntity="CTIChild")
     */
    private $ctiChildren;

    public function __construct()
    {
        $this->ctiChildren = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function addCTIChild(CTIChild $child): void
    {
        $this->ctiChildren->add($child);
    }

    /**
     * @psalm-return Collection<int, CTIChild>
     */
    public function getCTIChildren(): Collection
    {
        return $this->ctiChildren;
    }
}
