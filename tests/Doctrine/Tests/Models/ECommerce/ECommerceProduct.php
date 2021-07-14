<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ECommerce;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * ECommerceProduct
 * Represents a type of product of a shopping application.
 *
 * @Entity
 * @Table(name="ecommerce_products",indexes={@Index(name="name_idx", columns={"name"})})
use Doctrine\ORM\Mapping\OrderBy;
 */
class ECommerceProduct
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
     * @Column(type="string", length=50, nullable=true)
     */
    private $name;

    /**
     * @var ECommerceShipping|null
     * @OneToOne(targetEntity="ECommerceShipping", cascade={"persist"})
     * @JoinColumn(name="shipping_id", referencedColumnName="id")
     */
    private $shipping;

    /**
     * @psalm-var Collection<int, ECommerceFeature>
     * @OneToMany(targetEntity="ECommerceFeature", mappedBy="product", cascade={"persist"})
     */
    private $features;

    /**
     * @psalm-var Collection<int, ECommerceCategory>
     * @ManyToMany(targetEntity="ECommerceCategory", cascade={"persist"}, inversedBy="products")
     * @JoinTable(name="ecommerce_products_categories",
     *      joinColumns={@JoinColumn(name="product_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="category_id", referencedColumnName="id")})
     */
    private $categories;

    /**
     * This relation is saved with two records in the association table for
     * simplicity.
     *
     * @psalm-var Collection<int, ECommerceProduct>
     * @ManyToMany(targetEntity="ECommerceProduct", cascade={"persist"})
     * @JoinTable(name="ecommerce_products_related",
     *      joinColumns={@JoinColumn(name="product_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="related_id", referencedColumnName="id")})
     */
    private $related;

    /** @var bool */
    public $isCloned = false;

    /** @var bool */
    public $wakeUp = false;

    public function __construct()
    {
        $this->features   = new ArrayCollection();
        $this->categories = new ArrayCollection();
        $this->related    = new ArrayCollection();
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

    public function getShipping(): ?ECommerceShipping
    {
        return $this->shipping;
    }

    public function setShipping(ECommerceShipping $shipping): void
    {
        $this->shipping = $shipping;
    }

    public function removeShipping(): void
    {
        $this->shipping = null;
    }

    /**
     * @psalm-return Collection<int, ECommerceFeature>
     */
    public function getFeatures(): Collection
    {
        return $this->features;
    }

    public function addFeature(ECommerceFeature $feature): void
    {
        $this->features[] = $feature;
        $feature->setProduct($this);
    }

    /** does not set the owning side */
    public function brokenAddFeature(ECommerceFeature $feature): void
    {
        $this->features[] = $feature;
    }

    public function removeFeature(ECommerceFeature $feature): bool
    {
        $removed = $this->features->removeElement($feature);
        if ($removed) {
            $feature->removeProduct();
        }

        return $removed;
    }

    public function addCategory(ECommerceCategory $category): void
    {
        if (! $this->categories->contains($category)) {
            $this->categories[] = $category;
            $category->addProduct($this);
        }
    }

    public function removeCategory(ECommerceCategory $category): void
    {
        $removed = $this->categories->removeElement($category);
        if ($removed) {
            $category->removeProduct($this);
        }
    }

    /**
     * @psalm-param Collection<int, ECommerceCategory> $categories
     */
    public function setCategories(Collection $categories): void
    {
        $this->categories = $categories;
    }

    /**
     * @psalm-return Collection<int, ECommerceCategory> $categories
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    /**
     * @psalm-return Collection<int, ECommerceProduct> $categories
     */
    public function getRelated(): Collection
    {
        return $this->related;
    }

    public function addRelated(ECommerceProduct $related): void
    {
        if (! $this->related->contains($related)) {
            $this->related[] = $related;
            $related->addRelated($this);
        }
    }

    public function removeRelated(ECommerceProduct $related): void
    {
        $removed = $this->related->removeElement($related);
        if ($removed) {
            $related->removeRelated($this);
        }
    }

    public function __clone()
    {
        $this->isCloned = true;
        if ($this->categories) {
            $this->categories = clone $this->categories;
        }
    }

    /**
     * Testing docblock contents here
     */
    public function __wakeup(): void
    {
        $this->wakeUp = true;
    }
}
