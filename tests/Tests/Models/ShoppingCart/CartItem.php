<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ShoppingCart;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\UuidObject;
use Doctrine\Tests\DbalTypes\UuidObjectType;

#[Table(name: 'shopping_cart_item')]
#[Entity]
class CartItem
{
    #[Id]
    #[Column]
    public int $id;

    #[ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    public Cart $cart;

    #[ManyToOne(targetEntity: Category::class)]
    public Category|null $category = null;

    #[Column(type: UuidObjectType::NAME)]
    public UuidObject $uuid;

    #[Column]
    public string $sku;

    #[Column]
    public int $quantity;

    #[Column]
    public DateTimeImmutable $addedAt;

    /** @var array<string, mixed> */
    #[Column]
    public array $options;
}
