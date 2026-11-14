<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customcollection_book')]
#[ORM\Cache('NONSTRICT_READ_WRITE')]
class Book
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    public string $title;

    #[ORM\ManyToOne(targetEntity: Library::class, inversedBy: 'books')]
    public Library|null $library = null;

    #[ORM\ManyToOne(targetEntity: Shelf::class, inversedBy: 'books')]
    public Shelf|null $shelf = null;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function __clone()
    {
        $this->id = null;
    }
}
