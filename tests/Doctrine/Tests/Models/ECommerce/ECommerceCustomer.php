<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ECommerce;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * ECommerceCustomer
 * Represents a registered user of a shopping application.
 *
 * @Entity
 * @Table(name="ecommerce_customers")
 */
class ECommerceCustomer
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", length=50)
     */
    private $name;

    /**
     * @var ECommerceCart|null
     * @OneToOne(targetEntity="ECommerceCart", mappedBy="customer", cascade={"persist"})
     */
    private $cart;

    /**
     * Example of a one-one self referential association. A mentor can follow
     * only one customer at the time, while a customer can choose only one
     * mentor. Not properly appropriate but it works.
     *
     * @var ECommerceCustomer|null
     * @OneToOne(targetEntity="ECommerceCustomer", cascade={"persist"}, fetch="EAGER")
     * @JoinColumn(name="mentor_id", referencedColumnName="id")
     */
    private $mentor;

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setCart(ECommerceCart $cart): void
    {
        if ($this->cart !== $cart) {
            $this->cart = $cart;
            $cart->setCustomer($this);
        }
    }

    /** Does not properly maintain the bidirectional association! */
    public function brokenSetCart(ECommerceCart $cart): void
    {
        $this->cart = $cart;
    }

    public function getCart(): ?ECommerceCart
    {
        return $this->cart;
    }

    public function removeCart(): void
    {
        if ($this->cart !== null) {
            $cart       = $this->cart;
            $this->cart = null;
            $cart->removeCustomer();
        }
    }

    public function setMentor(ECommerceCustomer $mentor): void
    {
        $this->mentor = $mentor;
    }

    public function removeMentor(): void
    {
        $this->mentor = null;
    }

    public function getMentor(): ?ECommerceCustomer
    {
        return $this->mentor;
    }
}
