<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12706;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Address
{
    public function __construct(
        #[ORM\Column(nullable: true)]
        public string|null $city = null,
        public string|null $label = null,
    ) {
    }
}
