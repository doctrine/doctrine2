<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomCollection;

use Doctrine\ORM\Mapping as ORM;

// Cacheable for CustomCollectionSecondLevelCacheTest; inert elsewhere.
#[ORM\Entity]
#[ORM\Table(name: 'customcollection_library')]
#[ORM\Cache('NONSTRICT_READ_WRITE')]
class Library
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue]
    public int|null $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    public string $name;

    #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'library', cascade: ['persist', 'remove', 'detach'], fetch: 'EXTRA_LAZY', orphanRemoval: true, collectionClass: BookList::class)]
    #[ORM\Cache('NONSTRICT_READ_WRITE')]
    private BookList $books;

    #[ORM\ManyToMany(targetEntity: Tag::class, cascade: ['persist'], indexBy: 'name')]
    #[ORM\JoinTable(name: 'customcollection_library_tag')]
    #[ORM\Cache('NONSTRICT_READ_WRITE')]
    private TagCollection $tags;

    public function __construct(string $name)
    {
        $this->name  = $name;
        $this->books = new BookList();
        $this->tags  = new TagCollection();
    }

    public function getBooks(): BookList
    {
        return $this->books;
    }

    public function getTags(): TagCollection
    {
        return $this->tags;
    }

    public function addBook(Book $book): void
    {
        $this->books->addBook($book);
        $book->library = $this;
    }

    public function removeBook(Book $book): void
    {
        $this->books->removeBook($book);
        $book->library = null;
    }

    public function replaceBooks(BookList $books): void
    {
        $this->books = $books;
    }

    public function addTag(Tag $tag): void
    {
        $this->tags->set($tag->name, $tag);
    }

    public function __clone()
    {
        $this->id = null;

        $books = new BookList();

        foreach ($this->books as $book) {
            $bookCopy          = clone $book;
            $bookCopy->library = $this;
            $books->addBook($bookCopy);
        }

        $this->books = $books;

        // Tags are shared between the original and the copy: cloning the
        // custom collection clones the underlying managed collection while
        // keeping the same Tag instances.
        $this->tags = clone $this->tags;
    }
}
