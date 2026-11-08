<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DynamicProperties;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dynamic_properties_vendor')]
class Vendor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int $id;

    public function __construct(
        #[ORM\Column(length: 50)]
        public string $name,
    ) {
    }
}
