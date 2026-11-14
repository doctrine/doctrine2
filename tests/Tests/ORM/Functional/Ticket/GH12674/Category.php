<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12674;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'gh12674_category')]
class Category
{
    #[ORM\Embedded(columnPrefix: false)]
    public CategoryId $id;

    public function __construct(
        string $id,
        #[ORM\Column]
        public string $label,
    ) {
        $this->id = new CategoryId($id);
    }
}
