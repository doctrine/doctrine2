<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * @group DDC-1925
 * @group DDC-1210
 */
class DDC1925Test extends OrmFunctionalTestCase
{
    public function testIssue(): void
    {
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1925User::class),
                $this->_em->getClassMetadata(DDC1925Product::class),
            ]
        );

        $user = new DDC1925User();
        $user->setTitle('Test User');

        $product = new DDC1925Product();
        $product->setTitle('Test product');

        $this->_em->persist($user);
        $this->_em->persist($product);
        $this->_em->flush();

        $product->addBuyer($user);

        $this->_em->getUnitOfWork()
                  ->computeChangeSets();

        $this->_em->persist($product);
        $this->_em->flush();
        $this->_em->clear();

        $persistedProduct = $this->_em->find(DDC1925Product::class, $product->getId());
        assert($persistedProduct instanceof DDC1925Product);

        self::assertEquals($user, $persistedProduct->getBuyers()->first());
    }
}

/**
 * @Table
 * @Entity
 */
class DDC1925Product
{
    /**
     * @var int $id
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string $title
     * @Column(name="title", type="string", length=255)
     */
    private $title;

    /**
     * @psalm-var Collection<int, DDC1925User>
     * @ManyToMany(targetEntity="DDC1925User")
     * @JoinTable(
     *   name="user_purchases",
     *   joinColumns={@JoinColumn(name="product_id", referencedColumnName="id")},
     *   inverseJoinColumns={@JoinColumn(name="user_id", referencedColumnName="id")}
     * )
     */
    private $buyers;

    /**
     * Default constructor
     */
    public function __construct()
    {
        $this->buyers = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * Get title
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBuyers(): Collection
    {
        return $this->buyers;
    }

    public function addBuyer(DDC1925User $buyer): void
    {
        $this->buyers[] = $buyer;
    }
}

/**
 * @Table
 * @Entity
 */
class DDC1925User
{
    /**
     * @var int
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(name="title", type="string", length=255)
     */
    private $title;

    /**
     * Get id
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Set title
     */
    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /**
     * Get title
     */
    public function getTitle(): string
    {
        return $this->title;
    }
}
