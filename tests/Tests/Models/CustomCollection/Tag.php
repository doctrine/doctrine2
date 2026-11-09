<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customcollection_tag')]
#[ORM\Cache('NONSTRICT_READ_WRITE')]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
