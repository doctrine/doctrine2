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
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * ECommerceCategory
 * Represents a tag applied on particular products.
 *
 * @Entity
 * @Table(name="ecommerce_categories")
 */
class ECommerceCategory
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", length=50)
     */
    private $name;

    /**
     * @psalm-var Collection<int, ECommerceProduct>
     * @ManyToMany(targetEntity="ECommerceProduct", mappedBy="categories")
     */
    private $products;

    /**
     * @psalm-var Collection<int, ECommerceCategory>
     * @OneToMany(targetEntity="ECommerceCategory", mappedBy="parent", cascade={"persist"})
     */
    private $children;

    /**
     * @var ECommerceCategory
     * @ManyToOne(targetEntity="ECommerceCategory", inversedBy="children")
     * @JoinColumn(name="parent_id", referencedColumnName="id")
     */
    private $parent;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->children = new ArrayCollection();
    }

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

    public function addProduct(ECommerceProduct $product): void
    {
        if (! $this->products->contains($product)) {
            $this->products[] = $product;
            $product->addCategory($this);
        }
    }

    public function removeProduct(ECommerceProduct $product): void
    {
        $removed = $this->products->removeElement($product);
        if ($removed) {
            $product->removeCategory($this);
        }
    }

    /**
     * @psalm-return Collection<int, ECommerceProduct>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    private function setParent(ECommerceCategory $parent): void
    {
        $this->parent = $parent;
    }

    /**
     * @psalm-return Collection<int, ECommerceCategory>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getParent(): ?ECommerceCategory
    {
        return $this->parent;
    }

    public function addChild(ECommerceCategory $child): void
    {
        $this->children[] = $child;
        $child->setParent($this);
    }

    /** does not set the owning side. */
    public function brokenAddChild(ECommerceCategory $child): void
    {
        $this->children[] = $child;
    }

    public function removeChild(ECommerceCategory $child): void
    {
        $removed = $this->children->removeElement($child);
        if ($removed) {
            $child->removeParent();
        }
    }

    private function removeParent(): void
    {
        $this->parent = null;
    }
}
