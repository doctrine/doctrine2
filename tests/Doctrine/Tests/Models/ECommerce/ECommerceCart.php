<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ECommerce;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * ECommerceCart
 * Represents a typical cart of a shopping application.
 *
 * @Entity
 * @Table(name="ecommerce_carts")
 */
class ECommerceCart
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column(length=50, nullable=true)
     */
    private $payment;

    /**
     * @var ECommerceCustomer|null
     * @OneToOne(targetEntity="ECommerceCustomer", inversedBy="cart")
     * @JoinColumn(name="customer_id", referencedColumnName="id")
     */
    private $customer;

    /**
     * @psalm-var Collection<int, ECommerceProduct>
     * @ManyToMany(targetEntity="ECommerceProduct", cascade={"persist"})
     * @JoinTable(name="ecommerce_carts_products",
     *      joinColumns={@JoinColumn(name="cart_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="product_id", referencedColumnName="id")})
     */
    private $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPayment(): string
    {
        return $this->payment;
    }

    public function setPayment(string $payment): void
    {
        $this->payment = $payment;
    }

    public function setCustomer(ECommerceCustomer $customer): void
    {
        if ($this->customer !== $customer) {
            $this->customer = $customer;
            $customer->setCart($this);
        }
    }

    public function removeCustomer(): void
    {
        if ($this->customer !== null) {
            $customer       = $this->customer;
            $this->customer = null;
            $customer->removeCart();
        }
    }

    public function getCustomer(): ?ECommerceCustomer
    {
        return $this->customer;
    }

    /**
     * @psalm-return Collection<int, ECommerceProduct>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(ECommerceProduct $product): void
    {
        $this->products[] = $product;
    }

    public function removeProduct(ECommerceProduct $product): bool
    {
        return $this->products->removeElement($product);
    }
}
