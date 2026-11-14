<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ShoppingCart;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Table(name: 'shopping_cart_category')]
#[Entity]
class Category
{
    #[Id]
    #[Column]
    public int $id;

    #[Column]
    public string $name;
}
