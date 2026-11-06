<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ShoppingCart;

use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\UuidObject;
use Doctrine\Tests\DbalTypes\UuidObjectType;

#[Table(name: 'shopping_cart')]
#[Entity]
class Cart
{
    #[Id]
    #[Column]
    public int $id;

    #[Column(type: UuidObjectType::NAME)]
    public UuidObject $uuid;

    #[Column]
    public string $name;

    #[Column]
    public string $status;

    #[Column]
    public DateTimeImmutable $createdAt;

    #[Column]
    public DateTimeImmutable $updatedAt;

    /** @var array<string, mixed> */
    #[Column]
    public array $options;

    /** @var array<string, mixed> */
    #[Column]
    public array $metadata;

    #[Column]
    public bool $active;

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    public string $total;

    #[Column]
    public int $version;

    /** @var Collection<int, CartItem> */
    #[OneToMany(targetEntity: CartItem::class, mappedBy: 'cart')]
    public Collection $items;
}
