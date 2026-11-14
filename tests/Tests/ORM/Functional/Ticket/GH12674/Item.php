<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12674;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'gh12674_item')]
class Item
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(referencedColumnName: 'id', nullable: false)]
        public Category $category,
    ) {
    }
}
