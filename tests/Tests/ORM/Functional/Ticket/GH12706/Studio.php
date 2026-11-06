<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12706;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'gh12706_studio')]
class Studio
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public int|null $id = null;

    public function __construct(
        #[ORM\Embedded(class: Address::class)]
        public Address $address,
    ) {
    }
}
