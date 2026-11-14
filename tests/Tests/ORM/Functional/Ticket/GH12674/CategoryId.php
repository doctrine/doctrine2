<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12674;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class CategoryId
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: 'string', length: 36)]
        public string $value,
    ) {
    }
}
