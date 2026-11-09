<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customcollection_shelf')]
#[ORM\Cache('NONSTRICT_READ_WRITE')]
class Shelf
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\ManyToMany(targetEntity: Tag::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'customcollection_shelf_tag')]
    #[ORM\Cache('NONSTRICT_READ_WRITE')]
    public PublicTagList $tags;

    #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'shelf', cascade: ['persist', 'refresh'], fetch: 'EAGER')]
    public BookList $books;

    public function __construct()
    {
        $this->tags  = new PublicTagList();
        $this->books = new BookList();
    }

    public function addBook(Book $book): void
    {
        $this->books->addBook($book);
        $book->shelf = $this;
    }
}
